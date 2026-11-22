<?php

use App\Models\SafetyCertificateService;
use App\Models\SpbService;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\EntityProfilePhoto;
use App\Services\SafetyCertificateReadinessService;
use App\Services\SpbWorkflow;
use App\Services\TaskServiceRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Vessel')] class extends Component
{
    use WithFileUploads, WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
        unset($this->vessels);
    }

    #[Locked]
    public ?int $editingVesselId = null;

    #[Locked]
    public ?int $selectedVesselId = null;

    #[Locked]
    public ?int $departureSpbId = null;

    /** @var array<string, string> */
    public array $departure = ['actual_departure_at' => '', 'expected_arrival_at' => '', 'notes' => ''];

    #[Locked]
    public bool $registrationPage = false;

    #[Locked]
    public bool $editingPage = false;

    #[Locked]
    public ?string $completionServiceType = null;

    #[Locked]
    public ?int $completionServiceId = null;

    #[Locked]
    public string $completionSafetyRequestType = 'new_issue';

    public function mount(?Vessel $vessel = null, bool $registrationPage = false): void
    {
        $this->registrationPage = $registrationPage || request()->routeIs('vessels.create');
        $this->editingPage = $vessel?->exists ?? false;
        if ($this->editingPage) {
            $this->edit($vessel->id);
            if (request()->hasAny(['service_type', 'service_id'])) {
                $validator = Validator::make(request()->query(), [
                    'service_type' => ['required', 'string', Rule::in(array_keys(TaskServiceRegistry::TYPES))],
                    'service_id' => [Rule::requiredIf(request()->query('service_type') !== 'safety-certificates'), 'nullable', 'integer', 'min:1'],
                    'request_type' => ['sometimes', Rule::in(array_keys(SafetyCertificateService::REQUEST_TYPES))],
                ]);
                if ($validator->fails()) {
                    throw new HttpResponseException(redirect()->route('vessels.edit', $vessel)->withErrors($validator));
                }
                $context = $validator->validated();
                $this->completionServiceType = $context['service_type'];
                $this->completionServiceId = isset($context['service_id']) ? (int) $context['service_id'] : null;
                $this->completionSafetyRequestType = $context['request_type'] ?? 'new_issue';
            }
        } elseif ($this->registrationPage) {
            $this->create();
        } elseif (request()->filled('vessel')) {
            $this->detail((int) request()->query('vessel'));
        }
    }

    public function openDeparture(int $spbId): void
    {
        $spb = SpbService::findOrFail($spbId);
        Gate::authorize('work', $spb);
        abort_unless(in_array($spb->status, ['ready_to_sail', 'sailing'], true), 422);
        $this->redirectRoute('spb.show', ['spb' => $spb->id], navigate: true);
    }

    public function cancelDeparture(): void
    {
        $this->reset('departureSpbId', 'departure');
        unset($this->departureSpb);
        $this->resetValidation();
    }

    public function confirmDeparture(SpbWorkflow $workflow): void
    {
        abort_unless($this->departureSpbId !== null, 422);
        $this->resetValidation();
        $spb = SpbService::findOrFail($this->departureSpbId);
        $workflow->depart($spb, $this->departure);
        $this->cancelDeparture();
        unset($this->selectedVessel, $this->vessels, $this->summary);
        $this->dispatch('vessel-status-updated');
        session()->flash('vessel_status', 'Keberangkatan dikonfirmasi. Vessel berstatus Berlayar. Catat hasilnya pada detail SPB.');
    }

    #[Locked]
    public bool $showForm = false;

    #[Locked]
    public bool $documentsOnly = false;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<string, array{file: mixed, issued_date: string|null, expired_date: string|null}> */
    public array $documents = [];

    public $profilePhoto = null;

    public bool $removePhoto = false;

    #[Locked]
    public ?string $currentPhotoUrl = null;

    public function updatedProfilePhoto(): void
    {
        $this->removePhoto = false;
        $this->validateOnly('profilePhoto', ['profilePhoto' => EntityProfilePhoto::rules()], EntityProfilePhoto::messages());
    }

    public function removeProfilePhoto(): void
    {
        $this->profilePhoto = null;
        $this->removePhoto = true;
        $this->resetValidation('profilePhoto');
    }

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function create(): void
    {
        Gate::authorize('manage-vessels');
        $this->reset('completionServiceType', 'completionServiceId', 'completionSafetyRequestType');
        unset($this->serviceCompletionContext);
        $this->reset('editingVesselId', 'selectedVesselId', 'documentsOnly', 'form', 'documents', 'profilePhoto', 'removePhoto', 'currentPhotoUrl');
        $this->resetValidation();
        $this->form = array_fill_keys([
            'vessel_name', 'vessel_type', 'owner', 'passenger_capacity', 'port_of_registry',
            'registry_number', 'call_sign', 'gross_tonnage', 'net_tonnage',
            'loa', 'breadth', 'draft', 'build_year',
        ], '');
        $this->form['flag'] = 'Indonesia';
        $this->form['operational_status'] = 'berthed';
        $this->initializeDocuments();
        $this->showForm = true;
    }

    public function edit(int $vesselId): void
    {
        $this->create();
        $vessel = Vessel::with('documents')->findOrFail($vesselId);
        $this->editingVesselId = $vessel->id;
        $this->currentPhotoUrl = $vessel->profile_picture_path ? route('vessels.picture', $vessel) : null;
        $this->form = $vessel->only(array_keys($this->form));
        if (! isset(Vessel::TYPES[$vessel->vessel_type])) {
            $this->form['vessel_type'] = '';
        }
        $this->initializeDocuments($vessel);
    }

    public function manageDocuments(int $vesselId): void
    {
        $this->edit($vesselId);
        $this->documentsOnly = true;
    }

    public function detail(int $vesselId): void
    {
        $this->selectedVesselId = Vessel::findOrFail($vesselId)->id;
    }

    public function closeDetail(): void
    {
        $this->cancelDeparture();
        $this->reset('selectedVesselId');
    }

    public function closeForm(): void
    {
        if ($this->registrationPage || $this->editingPage) {
            $this->redirectRoute('dashboard.vessel', navigate: true);

            return;
        }

        $this->reset('showForm', 'editingVesselId', 'documentsOnly', 'form', 'documents', 'profilePhoto', 'removePhoto', 'currentPhotoUrl');
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('manage-vessels');
        abort_unless($this->showForm, 422);

        $vessel = $this->editingVesselId !== null
            ? Vessel::with('documents')->findOrFail($this->editingVesselId)
            : new Vessel;

        foreach ($this->form as $key => $value) {
            if (is_string($value)) {
                $this->form[$key] = trim($value) === '' ? null : trim($value);
            }
        }
        foreach ($this->documents as $type => $document) {
            if (! is_array($document)) {
                continue;
            }
            foreach (['issued_date', 'expired_date'] as $field) {
                if (($document[$field] ?? '') === '') {
                    $this->documents[$type][$field] = null;
                }
            }
            if (in_array($type, VesselDocument::EQUIPMENT_TYPES, true) && is_array($document['equipment'] ?? null)) {
                foreach ($document['equipment'] as $field => $value) {
                    if (is_string($value)) {
                        $this->documents[$type]['equipment'][$field] = trim($value) === '' ? null : trim($value);
                    }
                }
            }
        }

        $rules = $this->documentsOnly ? [] : $this->vesselRules();
        $rules['profilePhoto'] = $this->documentsOnly ? ['prohibited'] : EntityProfilePhoto::rules();
        $rules['removePhoto'] = ['boolean'];
        $rules['documents'] = ['array:'.implode(',', array_keys(VesselDocument::TYPES))];

        foreach (VesselDocument::TYPES as $type => $label) {
            $prefix = 'documents.'.$type;
            $isEquipment = in_array($type, VesselDocument::EQUIPMENT_TYPES, true);
            $rules[$prefix] = ['sometimes', 'array:file,issued_date,expired_date'.($isEquipment ? ',equipment' : '')];
            $hasDates = filled($this->documents[$type]['issued_date'] ?? null) || filled($this->documents[$type]['expired_date'] ?? null);
            $hasEquipment = $isEquipment && collect($this->documents[$type]['equipment'] ?? [])->contains(fn (mixed $value): bool => filled($value));
            $rules[$prefix.'.file'] = [
                Rule::requiredIf(($hasDates || $hasEquipment) && ! $vessel->documents->contains('document_type', $type)),
                'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,gif,doc,docx', 'extensions:pdf,jpg,jpeg,png,webp,gif,doc,docx',
                new \App\Rules\UploadSize((int) config('vessels.document_max_kb')),
            ];
            $rules[$prefix.'.issued_date'] = ['nullable', 'date_format:Y-m-d'];
            $rules[$prefix.'.expired_date'] = ['nullable', 'date_format:Y-m-d'];
            if (filled($this->documents[$type]['issued_date'] ?? null)) {
                $rules[$prefix.'.expired_date'][] = 'after_or_equal:'.$prefix.'.issued_date';
            }
            if ($isEquipment) {
                $rules[$prefix.'.equipment'] = ['sometimes', 'array:condition,quantity,capacity,notes'];
                $rules[$prefix.'.equipment.condition'] = ['nullable', Rule::in(array_keys(VesselDocument::EQUIPMENT_CONDITIONS))];
                foreach (['quantity', 'capacity'] as $field) {
                    $rules[$prefix.'.equipment.'.$field] = ['nullable', 'integer', 'min:0', 'max:1000000'];
                }
                $rules[$prefix.'.equipment.notes'] = ['required_if:'.$prefix.'.equipment.condition,not_applicable', 'nullable', 'string', 'max:2000'];
            }
        }

        $data = $this->validate($rules, array_merge(EntityProfilePhoto::messages(), [
            'required' => ':attribute wajib diisi.',
            'in' => ':attribute tidak valid.',
            'numeric' => ':attribute harus berupa angka.',
            'decimal' => ':attribute maksimal dua angka desimal.',
            'integer' => ':attribute harus berupa bilangan bulat.',
            'between' => ':attribute harus antara :min dan :max.',
            'max' => ':attribute melebihi batas :max.',
            'date_format' => ':attribute harus berupa tanggal yang valid.',
            'after_or_equal' => 'Tanggal kedaluwarsa tidak boleh sebelum tanggal terbit.',
            'documents.registry_endorsement.expired_date.after_or_equal' => 'Tanggal pengukuhan berikutnya tidak boleh sebelum tanggal pengukuhan.',
            'mimes' => 'Dokumen harus berupa PDF, JPG, JPEG, PNG, WebP, GIF, DOC, atau DOCX.',
            'extensions' => 'Ekstensi dokumen harus PDF, JPG, JPEG, PNG, WebP, GIF, DOC, atau DOCX.',
        ]), array_merge($this->validationLabels(), ['profilePhoto' => 'Foto profil']));

        $isNew = ! $vessel->exists;
        $attributes = $this->documentsOnly ? [] : $data['form'];

        try {
            app(EntityProfilePhoto::class)->save($vessel, $this->profilePhoto, ! $this->documentsOnly && $this->removePhoto, function () use ($vessel, $attributes, $data): void {
                $vessel->saveWithDocuments($attributes, $data['documents']);
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('save', 'Penyimpanan gagal. Data belum disimpan. Silakan coba kembali.');

            return;
        }

        session()->flash('vessel_status', $this->documentsOnly
            ? 'Dokumen vessel berhasil ditambahkan.'
            : ($isNew ? 'Vessel berhasil didaftarkan.' : 'Data vessel berhasil diperbarui.'));

        if (! $isNew) {
            $this->redirectRoute('dashboard.vessel');

            return;
        }

        $this->closeForm();
        $this->resetPage();
        unset($this->vessels, $this->summary);
    }

    /** @return array<string, array<int, mixed>> */
    private function vesselRules(): array
    {
        $rules = [
            'form.vessel_name' => ['required', 'string', 'max:255'],
            'form.vessel_type' => ['required', Rule::in(array_keys(Vessel::TYPES))],
            'form.operational_status' => ['required', Rule::in(array_keys(Vessel::STATUSES))],
            'form.flag' => ['required', 'string', 'max:255'],
            'form.owner' => ['nullable', 'string', 'max:255'],
            'form.passenger_capacity' => ['nullable', 'integer', 'between:0,1000000'],
            'form.port_of_registry' => ['nullable', 'string', 'max:255'],
            'form.registry_number' => ['nullable', 'string', 'max:255'],
            'form.call_sign' => ['nullable', 'string', 'max:50'],
            'form.build_year' => ['nullable', 'integer', 'between:1000,'.now()->year],
        ];

        foreach (['gross_tonnage', 'net_tonnage', 'loa', 'breadth', 'draft'] as $field) {
            $max = in_array($field, ['gross_tonnage', 'net_tonnage'], true) ? '9999999999.99' : '99999999.99';
            $rules['form.'.$field] = ['nullable', 'numeric', 'decimal:0,2', 'between:0,'.$max];
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function validationLabels(): array
    {
        $labels = [
            'form.vessel_name' => 'Nama kapal',
            'form.vessel_type' => 'Jenis kapal', 'form.operational_status' => 'Status kapal',
            'form.flag' => 'Bendera', 'form.owner' => 'Pemilik / operator',
            'form.passenger_capacity' => 'Kapasitas penumpang',
            'form.port_of_registry' => 'Port of registry', 'form.registry_number' => 'Registry number',
            'form.call_sign' => 'Call sign', 'form.build_year' => 'Tahun pembuatan',
            'form.gross_tonnage' => 'Gross tonnage', 'form.net_tonnage' => 'Net tonnage',
            'form.loa' => 'LOA', 'form.breadth' => 'Breadth', 'form.draft' => 'Draft',
        ];
        foreach (VesselDocument::TYPES as $type => $label) {
            $labels['documents.'.$type.'.file'] = 'File '.$label;
            $labels['documents.'.$type.'.issued_date'] = ($type === 'registry_endorsement' ? 'Tanggal pengukuhan ' : 'Tanggal terbit ').$label;
            $labels['documents.'.$type.'.expired_date'] = ($type === 'registry_endorsement' ? 'Tanggal pengukuhan berikutnya ' : 'Tanggal kedaluwarsa ').$label;
            foreach (['condition' => 'Kondisi', 'quantity' => 'Jumlah', 'capacity' => 'Kapasitas', 'notes' => 'Catatan / alasan applicability'] as $field => $fieldLabel) {
                $labels['documents.'.$type.'.equipment.'.$field] = $fieldLabel.' '.$label;
            }
        }

        return $labels;
    }

    private function initializeDocuments(?Vessel $vessel = null): void
    {
        foreach (VesselDocument::TYPES as $type => $label) {
            $document = $vessel?->documents->firstWhere('document_type', $type);
            $this->documents[$type] = [
                'file' => null,
                'issued_date' => $document?->issued_date?->format('Y-m-d'),
                'expired_date' => $document?->expired_date?->format('Y-m-d'),
            ];
            if (in_array($type, VesselDocument::EQUIPMENT_TYPES, true)) {
                $this->documents[$type]['equipment'] = $document?->equipment ?? ['condition' => null, 'quantity' => null, 'capacity' => null, 'notes' => null];
            }
        }
    }

    /** @return LengthAwarePaginator<int, Vessel> */
    #[Computed]
    public function vessels(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Vessel::with(['documents', 'crewAssignments' => fn (HasMany $query): HasMany => $query->whereNull('sign_off_at'), 'spbServices' => fn (HasMany $query): HasMany => $query->where('status', 'ready_to_sail')->whereNull('actual_departure_at')->latest('id')])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('vessel_name', 'like', '%'.$search.'%')
                    ->orWhere('owner', 'like', '%'.$search.'%')
                    ->orWhere('registry_number', 'like', '%'.$search.'%')
                    ->orWhere('call_sign', 'like', '%'.$search.'%')
                    ->orWhere('port_of_registry', 'like', '%'.$search.'%');
            }))
            ->latest('id')->paginate(9);
    }

    #[Computed]
    public function selectedVessel(): ?Vessel
    {
        return $this->selectedVesselId !== null
            ? Vessel::with([
                'documents.versions', 'spbServices', 'safetyCertificateServices', 'bunkerServices',
                'crewAssignments', 'sijilServices.crews', 'shipNationalityServices',
                'sijilServices.documents' => fn (HasMany $query): HasMany => $query->where('document_type', 'official_result')->latest('id'),
            ])->findOrFail($this->selectedVesselId)
            : null;
    }

    #[Computed]
    public function editingVessel(): ?Vessel
    {
        return $this->editingVesselId !== null
            ? Vessel::with('documents')->findOrFail($this->editingVesselId)
            : null;
    }

    #[Computed]
    public function departureSpb(): ?SpbService
    {
        return $this->departureSpbId !== null
            ? SpbService::with('vessel')->findOrFail($this->departureSpbId)
            : null;
    }

    /** @return array{label: string, number: string, url: string, request_label: string|null, warning_only: bool, document_types: array<string, string>, documents: array<string, array{status: string, required: bool, complete: bool, alternative: string|null, alternative_satisfied: bool}>}|null */
    #[Computed]
    public function serviceCompletionContext(): ?array
    {
        if (! $this->editingPage || $this->editingVesselId === null || $this->completionServiceType === null) {
            return null;
        }

        $serviceClass = TaskServiceRegistry::TYPES[$this->completionServiceType];
        $service = $this->completionServiceId !== null
            ? $serviceClass::query()->where('vessel_id', $this->editingVesselId)->findOrFail($this->completionServiceId)
            : null;
        $isSafetyCertificate = $this->completionServiceType === 'safety-certificates';
        $checks = $service !== null
            ? app(TaskServiceRegistry::class)->readiness($service)['vessel_checks']
            : app(SafetyCertificateReadinessService::class)->vesselCheck($this->editingVessel);
        $requestType = $service instanceof SafetyCertificateService ? $service->request_type : $this->completionSafetyRequestType;
        if ($isSafetyCertificate) {
            abort_unless($this->editingVessel->vessel_type === 'passenger', 422);
            $certificate = $this->editingVessel->documents->firstWhere('document_type', 'safety_certificate');
            $previous = $service?->latestDocument('previous_certificate');
            $path = $requestType === 'renewal' ? ($previous->file_path ?? $certificate->file_path ?? null) : $certificate?->file_path;
            $available = $path !== null && Storage::disk('local')->exists($path);
            $checks[] = [
                'label' => 'Sertifikat Keselamatan Kapal Penumpang lama', 'types' => ['safety_certificate'],
                'required' => $requestType === 'renewal', 'complete' => $available, 'document' => $certificate,
                'status' => $available
                    ? ($requestType === 'renewal' && $previous !== null ? 'Sertifikat lama sudah dilampirkan pada service.' : 'Sertifikat tersedia; sertifikat lama boleh kedaluwarsa untuk perpanjangan.')
                    : ($requestType === 'renewal' ? 'Sertifikat lama wajib dilengkapi untuk perpanjangan.' : 'Opsional untuk penerbitan baru; hasil sertifikat diunggah melalui service.'),
            ];
        }
        $documents = [];
        foreach ($checks as $check) {
            if ($check['complete']) {
                $documentType = $check['document']?->document_type ?? $check['types'][0];
                $documents[$documentType] = ['status' => $check['status'], 'required' => $check['required'], 'complete' => true, 'alternative' => null, 'alternative_satisfied' => false];
                if ($isSafetyCertificate && count($check['types']) > 1) {
                    foreach (array_diff($check['types'], [$documentType]) as $alternativeType) {
                        $documents[$alternativeType] = [
                            'status' => 'Persyaratan sudah dipenuhi oleh '.VesselDocument::TYPES[$documentType].'.',
                            'required' => false, 'complete' => false, 'alternative' => $check['label'], 'alternative_satisfied' => true,
                        ];
                    }
                }
            } elseif ($check['required'] || $isSafetyCertificate) {
                foreach ($check['types'] as $documentType) {
                    $documents[$documentType] = [
                        'status' => $check['status'], 'required' => $check['required'], 'complete' => false, 'alternative_satisfied' => false,
                        'alternative' => count($check['types']) > 1 ? $check['label'] : null,
                    ];
                }
            }
        }

        return [
            'label' => TaskServiceRegistry::LABELS[$this->completionServiceType],
            'number' => $service?->service_number ?? 'Layanan baru',
            'url' => $service !== null ? route($this->completionServiceType.'.show', $service) : route('safety-certificates.create', ['vessel_id' => $this->editingVesselId, 'request_type' => $requestType]),
            'request_label' => $isSafetyCertificate ? SafetyCertificateService::REQUEST_TYPES[$requestType] : null,
            'warning_only' => $this->completionServiceType === 'spb',
            'document_types' => $isSafetyCertificate
                ? collect(VesselDocument::TYPES)->sortBy(fn (string $label, string $type): int => isset($documents[$type]) ? ($documents[$type]['required'] || $documents[$type]['alternative'] !== null ? 0 : 1) : 2)->all()
                : VesselDocument::TYPES,
            'documents' => $documents,
        ];
    }

    /** @return array<string, int> */
    #[Computed]
    public function summary(): array
    {
        $counts = Vessel::query()->selectRaw('operational_status, COUNT(*) as total')
            ->groupBy('operational_status')->pluck('total', 'operational_status');

        return [
            'total' => (int) $counts->sum(),
            'sailing' => (int) $counts->get('sailing', 0),
            'berthed' => (int) $counts->get('berthed', 0),
            'maintenance' => (int) $counts->get('maintenance', 0),
        ];
    }
};
?>

<x-dashboard.shell page="Vessel" :demo="false">
    <div x-data>
        @if ($registrationPage || $editingPage)
            <div class="mb-6 flex flex-col gap-3">
                <a href="{{ route('dashboard.vessel') }}" wire:navigate class="text-xs font-semibold text-blue-600 dark:text-blue-400 hover:underline">Kembali ke daftar kapal</a>
                <h1 class="text-2xl font-semibold tracking-tight sm:text-[28px]">{{ $editingPage ? 'Edit Vessel' : 'Tambah Vessel' }}</h1>
            </div>
        @else
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="mb-1.5 text-xs text-slate-500 dark:text-slate-400">Your fleet, at a glance</p>
                <h1 class="text-2xl font-semibold tracking-tight sm:text-[28px]">Vessel</h1>
                <p class="mt-2 text-[13px] text-slate-500 dark:text-slate-400">Kenali armada Anda. Pantau status dan dokumen setiap kapal.</p>
            </div>
@can('manage-vessels')            <x-dashboard.add-button :href="route('vessels.create')" wire:navigate>Tambahkan Kapal Baru</x-dashboard.add-button>@endcan
        </div>

        @if (session('vessel_status'))
            <p data-form-success role="status" class="mt-5 rounded-lg border border-emerald-100 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300">{{ session('vessel_status') }}</p>
        @endif

        <section aria-label="Ringkasan armada" class="mt-4 grid grid-cols-2 gap-2 rounded-xl border border-slate-200/80 dark:border-slate-700/80 bg-white dark:bg-slate-900 p-3 shadow-xs sm:mt-6 sm:grid-cols-4 sm:gap-6 sm:p-5">
            @foreach (['total' => ['Total kapal', 'ship'], 'sailing' => ['Berlayar', 'arrow'], 'berthed' => ['Sandar', 'anchor'], 'maintenance' => ['Perawatan', 'clock']] as $key => [$label, $icon])
                <div wire:key="fleet-summary-{{ $key }}" class="flex min-w-0 items-center gap-2 sm:gap-3">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 sm:size-9"><x-dashboard.icon :name="$icon" class="size-4" /></span>
                    <div><p class="text-[11px] text-slate-500 dark:text-slate-400">{{ $label }}</p><p class="mt-0.5 text-lg font-semibold leading-tight tabular-nums sm:text-xl">{{ $this->summary[$key] }}</p></div>
                </div>
            @endforeach
        </section>


        <section aria-labelledby="fleet-heading" class="mt-7">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 id="fleet-heading" class="text-sm font-semibold">Daftar kapal</h2>
                <span role="status" class="text-xs text-slate-500 dark:text-slate-400">{{ $this->vessels->total() }} {{ trim($search) !== '' ? 'kapal ditemukan' : 'kapal terdaftar' }}</span>
            </div>
            <div class="mb-4 rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="min-w-0 flex-1">
                        <x-dashboard.filter-field name="vessel-search" label="Pencarian kapal" type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama kapal, pemilik, nomor registrasi, call sign, atau pelabuhan..." />
                    </div>
                    @if (trim($search) !== '')
                        <button type="button" wire:click="$set('search', '')" class="flex h-10 items-center justify-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50 motion-reduce:transition-none dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            <x-dashboard.icon name="close" class="size-3.5" /> Hapus pencarian
                        </button>
                    @endif
                </div>
                <p wire:loading wire:target="search" role="status" class="mt-2 text-xs text-blue-600 dark:text-blue-400">Mencari kapal...</p>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($this->vessels as $vessel)
                    <x-dashboard.vessel-card :vessel="$vessel" wire:key="vessel-{{ $vessel->id }}" />
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-5 py-12 text-center md:col-span-2 xl:col-span-3">
                        @if (trim($search) !== '')
                            <p class="text-sm text-slate-500 dark:text-slate-400">Tidak ada kapal yang cocok dengan pencarian.</p>
                            <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">Coba kata kunci lain atau hapus pencarian untuk melihat semua kapal.</p>
                        @else
                            <p class="text-sm text-slate-500 dark:text-slate-400">Belum ada kapal yang terdaftar.</p>
@can('manage-vessels')                            <x-dashboard.add-button :href="route('vessels.create')" wire:navigate class="mt-4">Tambahkan Kapal Baru</x-dashboard.add-button>@endcan
                        @endif
                    </div>
                @endforelse
            </div>
            <div class="mt-5">{{ $this->vessels->links() }}</div>
        </section>
        @endif

        @if ($registrationPage || $editingPage)
        <section aria-labelledby="vessel-form-title" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-xs">
        @else
        <dialog wire:ignore.self x-ref="vesselFormDialog"
            x-effect="if ($wire.showForm) { if (!$refs.vesselFormDialog.open) $refs.vesselFormDialog.showModal() } else { $refs.vesselFormDialog.close() }"
            x-on:cancel.prevent="$wire.closeForm()"
            aria-labelledby="vessel-form-title"
            class="m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto rounded-2xl border-0 bg-white dark:bg-slate-900 p-0 text-slate-800 dark:text-slate-100 shadow-xl backdrop:bg-slate-950/45 backdrop:backdrop-blur-xs">
        @endif
            @if ($showForm)
                @php($completionContext = $this->serviceCompletionContext)
                <form novalidate wire:submit="save" x-data="{ uploading: 0 }"
                    x-on:livewire-upload-start="uploading++"
                    x-on:livewire-upload-finish="uploading = Math.max(0, uploading - 1)"
                    x-on:livewire-upload-error="uploading = Math.max(0, uploading - 1)"
                    x-on:livewire-upload-cancel="uploading = Math.max(0, uploading - 1)">
        <x-form-errors id="vessel-1-errors" />
                    <div class="flex items-center justify-between gap-4 border-b border-slate-100 dark:border-slate-700 px-5 py-4 sm:px-6">
                        <div>
                            <h2 id="vessel-form-title" class="text-lg font-semibold">{{ $documentsOnly ? 'Kelola dokumen vessel' : ($editingVesselId ? 'Edit vessel' : 'Tambahkan Kapal Baru') }}</h2>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ ($completionContext['warning_only'] ?? false) ? 'Dokumen kapal ditampilkan sebagai peringatan dan tidak menghalangi SPB.' : ($completionContext ? 'Lengkapi dokumen sesuai persyaratan layanan asal. Perubahan dapat disimpan bertahap.' : ($documentsOnly ? $form['vessel_name'] : 'Lengkapi informasi kapal. Dokumen bersifat opsional.')) }}</p>
                        </div>
                        @if (! $registrationPage && ! $editingPage)
                            <button type="button" wire:click="closeForm" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" aria-label="Tutup formulir kapal" class="rounded-lg p-2 text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"><x-dashboard.icon name="close" /></button>
                        @endif
                    </div>

                    <div class="flex flex-col gap-6 px-5 py-5 sm:px-6">
                        
                        @if (! $documentsOnly)
                            <x-dashboard.photo-upload :photo="$profilePhoto" :current-url="$currentPhotoUrl" :removed="$removePhoto" :dropzone="true" label="Foto profil vessel" />
                            <x-dashboard.vessel-fields :form="$form" :vessel="$this->editingVessel" />
                        @endif

                        <section id="vessel-documents" aria-labelledby="vessel-documents-heading" class="scroll-mt-6">
                            <h3 id="vessel-documents-heading" class="text-sm font-semibold">Vessel Documents</h3>
                            <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">{{ ($completionContext['warning_only'] ?? false) ? 'Dokumen kapal untuk SPB bersifat peringatan.' : ($completionContext ? 'Persyaratan wajib dan opsional mengikuti layanan asal.' : 'Opsional.') }} PDF, JPG, JPEG, PNG, WebP, GIF, DOC, atau DOCX, maksimal {{ config('vessels.document_max_kb') / 1024 }} MB per file; gambar maksimal 4 MB. Satu dokumen aktif per jenis; upload ulang untuk mengganti file.</p>
                            @if ($completionContext)
                                <div class="mt-4 rounded-xl border border-blue-100 bg-blue-50/60 p-4 dark:border-blue-900 dark:bg-blue-950/30">
                                    <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">Dokumen untuk {{ $completionContext['label'] }} · {{ $completionContext['number'] }}</p>
                                    @if ($completionContext['request_label'])
                                        <p class="mt-1 text-xs font-semibold text-blue-800 dark:text-blue-200">Sertifikat Keselamatan Kapal Penumpang · {{ $completionContext['request_label'] }}</p>
                                        <p class="mt-2 text-xs leading-5 text-slate-600 dark:text-slate-300">Dokumen wajib ditampilkan terlebih dahulu dan harus lengkap sebelum pengajuan. Dokumen opsional tidak menghalangi pengajuan. Surat Laut, Pas Besar, dan Pas Kecil merupakan pilihan alternatif; cukup lengkapi salah satu.</p>
                                    @endif
                                    <p class="mt-2 text-xs leading-5 text-slate-600 dark:text-slate-300">@if ($completionContext['warning_only']) Status dokumen dan perlengkapan kapal hanya berupa peringatan; SPB tetap dapat dilanjutkan. @else Centang hijau menunjukkan dokumen yang sudah lengkap untuk {{ $completionContext['label'] }}. Centang merah menunjukkan dokumen wajib yang belum lengkap. @endif Status mengikuti dokumen yang sudah disimpan, termasuk masa berlaku dan kesiapan alat bila dipersyaratkan.</p>
                                    <a href="{{ $completionContext['url'] }}" class="mt-3 inline-flex text-xs font-semibold text-blue-600 dark:text-blue-400">Kembali ke service</a>
                                </div>
                            @endif
                            
                            <div class="mt-4 flex flex-col gap-3">
                                @foreach ($completionContext['document_types'] ?? \App\Models\VesselDocument::TYPES as $type => $label)
                                    @php($existingDocument = $this->editingVessel?->documents->firstWhere('document_type', $type))
                                    @php($serviceDocument = $completionContext['documents'][$type] ?? null)
                                    <details wire:ignore.self wire:key="document-input-{{ $editingVesselId ?? 'new' }}-{{ $type }}"
                                        x-data="{ selectedFileName: '' }"
                                        x-bind:class="selectedFileName ? 'border-emerald-300 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900'"
                                        @if ($errors->has('documents.'.$type.'.*')) open @endif
                                        class="rounded-xl border p-4 transition-colors">
                                        <summary class="cursor-pointer text-sm font-medium">
                                            {{ $label }}
                                            @if ($existingDocument)<span class="ml-2 text-xs text-emerald-600 dark:text-emerald-400">Tersimpan</span>@endif
                                            @if ($serviceDocument && $serviceDocument['complete'])
                                                <span x-show="!selectedFileName" data-service-document-complete="{{ $type }}" class="ml-2 inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                                    <x-dashboard.icon name="check" class="size-3" />Lengkap untuk {{ $completionContext['label'] }} · {{ $completionContext['warning_only'] ? 'Pemeriksaan' : ($serviceDocument['required'] ? 'Wajib' : 'Opsional') }}
                                                </span>
                                            @elseif ($serviceDocument && $serviceDocument['required'])
                                                <span data-service-document-incomplete="{{ $type }}" @class(['ml-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' => $completionContext['warning_only'], 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300' => ! $completionContext['warning_only']])>
                                                    <x-dashboard.icon name="check" class="size-3" />{{ $completionContext['warning_only'] ? 'Peringatan' : 'Wajib' }} · Belum lengkap untuk {{ $completionContext['label'] }}
                                                </span>
                                            @elseif ($serviceDocument && $serviceDocument['alternative_satisfied'])
                                                <span data-service-document-alternative="{{ $type }}" class="ml-2 inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-xs text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">Alternatif · Sudah dipenuhi</span>
                                            @elseif ($serviceDocument)
                                                <span data-service-document-optional="{{ $type }}" class="ml-2 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">Opsional · Belum lengkap</span>
                                            @elseif ($completionContext && $completionContext['request_label'])
                                                <span class="ml-2 text-xs text-slate-400">Dokumen lainnya</span>
                                            @endif
                                            <span x-cloak x-show="selectedFileName" class="ml-2 inline-flex rounded-full bg-emerald-100 dark:bg-emerald-950/40 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300">File dipilih</span>
                                        </summary>
                                        <div class="mt-4 flex flex-col gap-4" x-effect="if (@js($errors->has('documents.'.$type.'.*'))) $el.closest('details').open = true">
                                            @if ($serviceDocument)
                                                <div @class(['text-xs', 'text-emerald-700 dark:text-emerald-300' => $serviceDocument['complete'], 'text-amber-700 dark:text-amber-300' => ! $serviceDocument['complete'] && $serviceDocument['required'] && $completionContext['warning_only'], 'text-rose-700 dark:text-rose-300' => ! $serviceDocument['complete'] && $serviceDocument['required'] && ! $completionContext['warning_only'], 'text-slate-600 dark:text-slate-300' => ! $serviceDocument['complete'] && ! $serviceDocument['required']])>
                                                    <p>{{ $completionContext['label'] }}: {{ $serviceDocument['status'] }}</p>
                                                    @if ($serviceDocument['alternative'])
                                                        <p class="mt-2">Cukup lengkapi salah satu: {{ $serviceDocument['alternative'] }}.</p>
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($existingDocument)
                                                <p class="text-xs text-slate-500 dark:text-slate-400">File saat ini: <x-dashboard.download-button :href="route('vessel-documents.download', [$editingVesselId, $existingDocument->id])">{{ \App\Services\DocumentFilename::forVessel($existingDocument, $this->editingVessel, $type) }}</x-dashboard.download-button>. Biarkan upload kosong untuk mempertahankan file.</p>
                                            @endif
                                            <div data-file-dropzone tabindex="0" class="rounded-lg border border-dashed border-slate-300 p-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:border-slate-600">
                                                <label for="file-{{ $type }}" class="mb-2 block text-sm font-medium">{{ $existingDocument ? 'Ganti file' : 'Upload file' }} {{ $label }}</label>
                                                <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">Seret file ke kotak ini atau fokus pada kotak lalu paste file (Ctrl+V).</p>
                                                <input id="file-{{ $type }}" type="file" data-max-file-kb="{{ config('vessels.document_max_kb') }}" wire:model="documents.{{ $type }}.file" x-on:change="selectedFileName = $event.target.files[0]?.name ?? ''" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx" aria-describedby="documents.{{ $type }}.file-error" aria-invalid="{{ $errors->has('documents.'.$type.'.file') ? 'true' : 'false' }}" class="block w-full text-xs text-slate-500 dark:text-slate-400 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 dark:file:bg-blue-950/40 file:px-3 file:py-2 file:text-blue-600 dark:file:text-blue-400">
                                                <p x-cloak x-show="selectedFileName" role="status" class="mt-2 break-all text-xs text-emerald-700 dark:text-emerald-300">File dipilih: <span x-text="selectedFileName"></span></p>
                                                <span wire:loading wire:target="documents.{{ $type }}.file" class="text-xs text-blue-600 dark:text-blue-400">Mengunggah...</span>
                                            </div>
                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <x-auth.input name="documents.{{ $type }}.issued_date" :label="$type === 'registry_endorsement' ? 'Tanggal pengukuhan (opsional)' : 'Issued Date / Tanggal terbit (opsional)'" type="date" wire:model="documents.{{ $type }}.issued_date" :value="$documents[$type]['issued_date'] ?? ''" />
                                                <x-auth.input name="documents.{{ $type }}.expired_date" :label="$type === 'registry_endorsement' ? 'Tanggal pengukuhan berikutnya (opsional)' : 'Expired Date / Kedaluwarsa (opsional)'" type="date" wire:model="documents.{{ $type }}.expired_date" :value="$documents[$type]['expired_date'] ?? ''" />
                                            </div>
                                            @if ($type === 'registry_endorsement')
                                                <p class="text-xs leading-5 text-slate-600 dark:text-slate-300">Unggah halaman pengukuhan (endorsement) Surat Laut, Pas Besar, atau Pas Kecil. Isi tanggal pengukuhan berikutnya sesuai dokumen/pengesahan untuk pengingat. Port of Registry pada data kapal tetap mencatat pelabuhan pendaftaran.</p>
                                            @endif
                                            @if (in_array($type, \App\Models\VesselDocument::EQUIPMENT_TYPES, true))
                                                <div class="grid gap-4 rounded-xl bg-blue-50/50 p-4 sm:grid-cols-2 dark:bg-blue-950/30">
                                                    <div class="sm:col-span-2">
                                                        <label for="equipment-condition-{{ $type }}" class="mb-2 block text-sm font-medium">Kondisi / hasil pemeriksaan</label>
                                                        <select id="equipment-condition-{{ $type }}" wire:model="documents.{{ $type }}.equipment.condition" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm dark:border-slate-700 dark:bg-slate-900">
                                                            <option value="">Belum diperiksa</option>
                                                            @foreach (\App\Models\VesselDocument::EQUIPMENT_CONDITIONS as $condition => $conditionLabel)
                                                                <option wire:key="equipment-{{ $type }}-{{ $condition }}" value="{{ $condition }}">{{ $conditionLabel }}</option>
                                                            @endforeach
                                                        </select>
                                                        @error('documents.'.$type.'.equipment.condition')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                                                    </div>
                                                    <x-auth.input name="documents.{{ $type }}.equipment.quantity" label="Jumlah alat (unit)" type="number" min="0" max="1000000" step="1" wire:model="documents.{{ $type }}.equipment.quantity" />
                                                    @if ($type === 'liferaft')
                                                        <x-auth.input name="documents.{{ $type }}.equipment.capacity" label="Total kapasitas Liferaft / ILR (orang)" type="number" min="0" max="1000000" step="1" wire:model="documents.{{ $type }}.equipment.capacity" />
                                                    @endif
                                                    <div class="sm:col-span-2"><x-auth.input name="documents.{{ $type }}.equipment.notes" label="Catatan pemeriksaan / alasan tidak berlaku" maxlength="2000" wire:model="documents.{{ $type }}.equipment.notes" /></div>
                                                    <p class="text-xs leading-5 text-slate-600 sm:col-span-2 dark:text-slate-300">Unggah sertifikat atau bukti/checklist pemeriksaan. Liferaft dan PMK harus memiliki masa berlaku untuk layanan yang mewajibkannya. Status tidak berlaku hanya diterima untuk SPB kapal barang dengan alasan dan bukti terlampir.</p>
                                                </div>
                                            @endif
                                        </div>
                                    </details>
                                @endforeach
                            </div>
                        </section>
                        <p x-cloak x-show="uploading > 0" role="status" class="text-xs text-blue-600 dark:text-blue-400">Tunggu sampai seluruh file selesai diunggah sebelum menyimpan.</p>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-slate-100 dark:border-slate-700 px-5 py-4 sm:px-6">
                        <button type="button" wire:click="closeForm" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="rounded-lg border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">Batal</button>
                        <button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="rounded-lg bg-blue-500 px-4 py-2.5 text-xs font-medium text-white hover:bg-blue-600 disabled:opacity-50">
                            <span wire:loading.remove wire:target="save">{{ $documentsOnly ? 'Simpan dokumen' : ($editingVesselId ? 'Simpan perubahan' : 'Simpan kapal') }}</span>
                            <span wire:loading wire:target="save">Menyimpan...</span>
                        </button>
                    </div>
                </form>
            @endif
        @if ($registrationPage || $editingPage)
        </section>
        @else
        </dialog>
        @endif

        @if (! $registrationPage && ! $editingPage)
        <dialog wire:ignore.self x-ref="vesselDetailDialog"
            x-effect="if ($wire.selectedVesselId !== null) { if (!$refs.vesselDetailDialog.open) $refs.vesselDetailDialog.showModal() } else { $refs.vesselDetailDialog.close() }"
            x-on:cancel.prevent="$wire.closeDetail()"
            aria-labelledby="vessel-detail-title"
            class="m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto rounded-2xl border-0 bg-white dark:bg-slate-900 p-0 text-slate-800 dark:text-slate-100 shadow-xl backdrop:bg-slate-950/45 backdrop:backdrop-blur-xs">
            @if ($this->selectedVessel)
                <x-dashboard.vessel-detail :vessel="$this->selectedVessel" />
            @endif
        </dialog>

        @endif
    </div>
</x-dashboard.shell>
