<?php

use App\Models\BunkerDocument;
use App\Models\BunkerService;
use App\Models\Vessel;
use App\Services\BunkerReadinessService;
use App\Services\BunkerWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Form Izin Bunker')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $serviceId = null;

    /** @var array<string, mixed> */
    public array $form = ['vessel_id' => '', 'supplier' => '', 'bunker_location' => '', 'bunker_type' => '', 'scheduled_at' => '', 'quantity' => '', 'unit' => '', 'unit_other' => '', 'notes' => ''];


    /** @var array<string, mixed> */
    public array $documents = [];

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function mount(?BunkerService $service = null): void
    {
        if ($service?->exists) {
            Gate::authorize('work', $service);
            abort_unless($service->isEditable(), 403);
            $this->serviceId = $service->id;
            $this->form = $service->only(array_keys($this->form));
            $this->form['scheduled_at'] = $service->scheduled_at?->timezone(config('bunker.timezone'))->format('Y-m-d\TH:i') ?? '';
        } elseif (request()->filled('vessel_id')) {
            $data = Validator::make(request()->query(), ['vessel_id' => ['required', 'integer', 'exists:vessels,id']])->validate();
            $this->form['vessel_id'] = $data['vessel_id'];
        }
    }

    public function updatedFormVesselId(): void
    {
        unset($this->selectedVessel, $this->checks);
    }

    public function save(BunkerWorkflow $workflow): void
    {
        try {
            $service = $workflow->saveDraft($this->serviceId ? BunkerService::findOrFail($this->serviceId) : null, $this->form, $this->documents);
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
        session()->flash('bunker_status', 'Draft atau perubahan layanan berhasil disimpan.');
        $this->redirectRoute('bunker.show', ['service' => $service->id]);
    }

    /** @return Collection<int, Vessel> */
    #[Computed]
    public function vessels(): Collection
    {
        return Vessel::query()->orderBy('vessel_name')->get();
    }

    #[Computed]
    public function selectedVessel(): ?Vessel
    {
        return $this->vessels->firstWhere('id', $this->form['vessel_id'] ?? null);
    }

    #[Computed]
    public function checks(): array
    {
        $date = $this->form['scheduled_at'] ?? null;
        $scheduled = is_string($date) && Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d\TH:i'])->passes()
            ? CarbonImmutable::parse($date, config('bunker.timezone')) : null;

        return $this->selectedVessel ? app(BunkerReadinessService::class)->vesselCheck($this->selectedVessel, $scheduled) : [];
    }

    /** @return array<string, array{filename: string, url: string, available: bool}> */
    #[Computed]
    public function existingDocuments(): array
    {
        if ($this->serviceId === null) {
            return [];
        }

        $service = BunkerService::with('documents')->findOrFail($this->serviceId);
        Gate::authorize('work', $service);
        $documents = [];
        foreach (array_keys($this->documentTypes) as $type) {
            $document = $service->latestDocument($type);
            if ($document !== null) {
                $documents[$type] = [
                    'filename' => $document->original_filename,
                    'url' => route('bunker.documents.download', [$service, $document]),
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
        $types = BunkerDocument::APPLICATION_TYPES;
        if ($this->serviceId) {
            $types['revision_attachment'] = 'Lampiran Revisi';
        }
        foreach ($types as $key => $label) {
            $types[$key] = $label.(in_array($key, config('bunker.required_service_documents'), true) ? ' (wajib sebelum pengajuan)' : ' (opsional)');
        }

        return $types;
    }
};
?>

<x-dashboard.shell page="Izin Bunker" :demo="false">
    <div class="flex flex-wrap items-center justify-between gap-4"><div><p class="text-xs text-slate-500 dark:text-slate-400">Izin Bunker</p><h1 class="mt-2 text-2xl font-semibold">{{ $serviceId ? 'Edit Service' : 'Buat Service Baru' }}</h1></div><x-dashboard.add-button :href="route('bunker.index')" variant="soft" icon="arrow" class="whitespace-nowrap [&_svg]:size-3.5 [&_svg]:rotate-180">Kembali ke monitoring</x-dashboard.add-button></div>
    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Pengajuan resmi dilakukan di luar sistem FLA. Data bertanda * wajib lengkap sebelum Ready To Apply; Draft boleh belum lengkap.</p>
    <form novalidate wire:submit="save" class="mt-6 flex flex-col gap-6" x-data="{ uploading: 0 }" x-on:livewire-upload-start="uploading++" x-on:livewire-upload-finish="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-error="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-cancel="uploading = Math.max(0, uploading-1)">
        <x-form-errors id="bunker-form-1-errors" />
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Service Information</h2>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <x-dashboard.vessel-selector :vessels="$this->vessels" :selected-vessel="$this->selectedVessel" :disabled="(bool) $serviceId" />
            </div>
        </section>
        <section class="flex flex-col gap-5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Bunker Information</h2>
            <x-dashboard.bunker-fields />
            <x-auth.input name="bunker_type" label="Fuel / Bunker Type *" wire:model="form.bunker_type" maxlength="255" placeholder="Contoh: HSD / MGO" />
            <x-auth.input name="notes" label="Notes (opsional)" wire:model="form.notes" maxlength="5000" />
        </section>
        @if ($this->selectedVessel)<x-dashboard.service-vessel-check :checks="$this->checks" :vessel="$this->selectedVessel" service-type="bunker" :service-id="$serviceId" />@endif
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Service Documents</h2>
            <p class="mt-2 mb-4 text-xs leading-5 text-slate-500 dark:text-slate-400">PDF/JPG/JPEG/PNG/WebP/GIF/DOC/DOCX, maksimal {{ config('bunker.document_max_kb') / 1024 }} MB (gambar maksimal 4 MB). Dokumen Vessel diambil otomatis. Upload pengganti disimpan sebagai versi baru; file lama tetap tersedia.</p>
            <x-dashboard.service-uploads :max-kb="config('bunker.document_max_kb')" :types="$this->documentTypes" :existing="$this->existingDocuments" :selected="$documents" prefix="bunker" />
        </section>
        <div class="flex justify-end gap-3"><button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="rounded-lg bg-blue-500 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50"><span wire:loading.remove wire:target="save">Simpan Draft / Perubahan</span><span wire:loading.inline-flex wire:target="save" class="items-center gap-2"><span aria-hidden="true" class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent motion-reduce:animate-none"></span>Menyimpan...</span></button></div>
    </form>
</x-dashboard.shell>
