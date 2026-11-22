<?php

use App\Models\SafetyCertificateDocument;
use App\Models\SafetyCertificateService;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\SafetyCertificateReadinessService;
use App\Services\SafetyCertificateWorkflow;
use Illuminate\Database\Eloquent\Collection;
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

new #[Title('Form Sertifikat Keselamatan')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $serviceId = null;

    #[Locked]
    public ?int $previousDocumentId = null;

    /** @var array<string, mixed> */
    public array $form = ['vessel_id' => '', 'request_type' => 'new_issue', 'due_at' => '', 'notes' => ''];


    /** @var array<string, mixed> */
    public array $documents = [];

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function mount(?SafetyCertificateService $service = null): void
    {
        if ($service?->exists) {
            Gate::authorize('work', $service);
            abort_unless($service->isEditable(), 403);
            $this->serviceId = $service->id;
            $this->form = $service->only(array_keys($this->form));
            $this->form['due_at'] = $service->due_at?->timezone(config('safety-certificates.timezone'))->format('Y-m-d\TH:i') ?? '';
            $this->previousDocumentId = $service->current_certificate_document_id;
        } elseif (request()->filled('vessel_id')) {
            $query = Validator::make(request()->query(), [
                'vessel_id' => ['required', 'integer', Rule::exists('vessels', 'id')->where('vessel_type', 'passenger')],
                'request_type' => ['sometimes', Rule::in(array_keys(SafetyCertificateService::REQUEST_TYPES))],
                'previous_document_id' => ['sometimes', 'integer'],
            ])->validate();
            $this->form['vessel_id'] = $query['vessel_id'];
            $this->form['request_type'] = $query['request_type'] ?? 'new_issue';
            if (isset($query['previous_document_id'])) {
                $previous = VesselDocument::query()->where('vessel_id', $query['vessel_id'])->where('document_type', 'safety_certificate')->findOrFail($query['previous_document_id']);
                $this->previousDocumentId = $previous->id;
            }
        }
    }

    public function updatedFormVesselId(): void
    {
        unset($this->selectedVessel, $this->currentCertificate, $this->checks);
        $this->previousDocumentId = $this->currentCertificate?->id;
    }

    public function save(SafetyCertificateWorkflow $workflow, ?string $vesselId = null): void
    {
        if ($vesselId !== null) {
            $this->form['vessel_id'] = $vesselId;
            $this->updatedFormVesselId();
        }

        try {
            $service = $workflow->saveDraft($this->serviceId ? SafetyCertificateService::findOrFail($this->serviceId) : null, $this->form, $this->documents);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException || $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $exception;
            }
            report($exception);
            $this->addError('save', 'Penyimpanan gagal. Perubahan belum tersimpan. Silakan coba kembali.');

            return;
        }
        session()->flash('safety_status', 'Draft atau perubahan layanan berhasil disimpan.');
        $this->redirectRoute('safety-certificates.show', ['service' => $service->id]);
    }

    /** @return Collection<int, Vessel> */
    #[Computed]
    public function vessels(): Collection
    {
        return Vessel::query()->where('vessel_type', 'passenger')->orderBy('vessel_name')->get();
    }

    #[Computed]
    public function selectedVessel(): ?Vessel
    {
        return $this->vessels->firstWhere('id', $this->form['vessel_id'] ?? null);
    }

    #[Computed]
    public function currentCertificate(): ?VesselDocument
    {
        return $this->selectedVessel?->documents->firstWhere('document_type', 'safety_certificate');
    }

    #[Computed]
    public function previousAvailable(): bool
    {
        return $this->currentCertificate !== null && Storage::disk('local')->exists($this->currentCertificate->file_path);
    }

    #[Computed]
    public function checks(): array
    {
        return $this->selectedVessel ? app(SafetyCertificateReadinessService::class)->vesselCheck($this->selectedVessel) : [];
    }

    /** @return array<string, array{filename: string, url: string, available: bool}> */
    #[Computed]
    public function existingDocuments(): array
    {
        if ($this->serviceId === null) {
            return [];
        }

        $service = SafetyCertificateService::with('documents')->findOrFail($this->serviceId);
        Gate::authorize('work', $service);
        $documents = [];
        foreach (array_keys($this->documentTypes) as $type) {
            $document = $service->latestDocument($type);
            if ($document !== null) {
                $documents[$type] = [
                    'filename' => $document->original_filename,
                    'url' => route('safety-certificates.documents.download', [$service, $document]),
                    'available' => Storage::disk('local')->exists($document->file_path),
                ];
            }
        }

        return $documents;
    }

    /** @return array<string, string> */
    #[Computed]
    public function documentTypes(): array
    {
        $types = SafetyCertificateDocument::APPLICATION_TYPES;
        if ($this->serviceId) {
            $types['revision_attachment'] = 'Lampiran Revisi';
        }
        foreach ($types as $key => $label) {
            $types[$key] = $label.(in_array($key, config('safety-certificates.required_service_documents'), true) ? ' (wajib sebelum pengajuan)' : ' (opsional)');
        }

        return $types;
    }
};
?>

<x-dashboard.shell page="Sertifikat Keselamatan Kapal Penumpang" :demo="false">
    <div class="flex flex-wrap items-center justify-between gap-4"><div><p class="text-xs text-slate-500 dark:text-slate-400">Sertifikat Keselamatan Kapal Penumpang</p><h1 class="mt-2 text-2xl font-semibold">{{ $serviceId ? 'Edit Service' : 'Buat Service Baru' }}</h1></div><x-dashboard.add-button :href="route('safety-certificates.index')" variant="soft" icon="arrow" class="whitespace-nowrap [&_svg]:size-3.5 [&_svg]:rotate-180">Kembali ke monitoring</x-dashboard.add-button></div>
    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">FLA memonitor pengurusan. Sertifikat resmi diterbitkan oleh instansi berwenang.</p>
    <form novalidate wire:submit="save($event.target.elements.namedItem('vessel_id').value)" class="mt-6 flex flex-col gap-6" x-data="{ uploading: 0 }" x-on:livewire-upload-start="uploading++" x-on:livewire-upload-finish="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-error="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-cancel="uploading = Math.max(0, uploading-1)">
        <x-form-errors id="safety-certificate-form-1-errors" />
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Service Information</h2>
            @if ($this->vessels->isEmpty())<p class="mt-4 text-sm text-amber-700 dark:text-amber-300">Belum ada kapal penumpang. <a href="{{ route('dashboard.vessel') }}" class="underline">Daftarkan di Vessel</a>.</p>@endif
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <x-dashboard.vessel-selector :vessels="$this->vessels" :selected-vessel="$this->selectedVessel" :disabled="(bool) $serviceId" :passenger-only="true" />
                <div><label for="request_type" class="mb-2 block text-sm font-medium">Jenis Pengurusan *</label><select id="request_type" wire:model.live="form.request_type" required class="w-full rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-3 text-sm">@foreach (\App\Models\SafetyCertificateService::REQUEST_TYPES as $key => $label)<option wire:key="request-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                <x-auth.input name="due_at" label="Target penyelesaian (WITA, opsional)" type="datetime-local" wire:model="form.due_at" />
                <x-auth.input name="notes" label="Notes (opsional)" wire:model="form.notes" maxlength="5000" />
            </div>
        </section>
        @if ($this->selectedVessel)
            <x-dashboard.safety-current-certificate :certificate="$this->currentCertificate" :vessel="$this->selectedVessel" :renewal="$form['request_type'] === 'renewal'" :service-id="$serviceId" />
            @if ($form['request_type'] === 'renewal' && ! $this->previousAvailable && $this->currentCertificate)
                <p class="rounded-lg bg-amber-50 dark:bg-amber-950/40 p-4 text-sm text-amber-800 dark:text-amber-300">Sertifikat lama belum tersedia pada data Vessel. File tidak tersedia. <a href="{{ route('vessels.edit', ['vessel' => $this->selectedVessel, 'service_type' => 'safety-certificates', 'service_id' => $serviceId, 'request_type' => ! $serviceId ? $form['request_type'] : null]).'#vessel-documents' }}" class="underline">Lengkapi di Vessel</a></p>
            @endif
            <x-dashboard.safety-vessel-check :checks="$this->checks" :vessel="$this->selectedVessel" :service-id="$serviceId" :request-type="$form['request_type']" />
        @endif
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Service Documents</h2>
            <p class="mt-2 mb-4 text-xs leading-5 text-slate-500 dark:text-slate-400">Boleh belum lengkap saat Draft. PDF/JPG/JPEG/PNG/WebP/GIF/DOC/DOCX, maksimal {{ config('safety-certificates.document_max_kb') / 1024 }} MB (gambar maksimal 4 MB). Dokumen Vessel diambil otomatis. Upload baru menjadi versi terkini; versi lama tetap disimpan.</p>
            <x-dashboard.service-uploads prefix="safety" :max-kb="config('safety-certificates.document_max_kb')" :types="$this->documentTypes" :existing="$this->existingDocuments" :selected="$documents" />
        </section>
        <div class="flex justify-end gap-3"><button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="rounded-lg bg-blue-500 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50"><span wire:loading.remove wire:target="save">Simpan Draft / Perubahan</span><span wire:loading.inline-flex wire:target="save" class="items-center gap-2"><span aria-hidden="true" class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent motion-reduce:animate-none"></span>Menyimpan...</span></button></div>
    </form>
</x-dashboard.shell>

