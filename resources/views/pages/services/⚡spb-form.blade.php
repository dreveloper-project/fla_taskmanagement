<?php

use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\Vessel;
use App\Services\SpbReadinessService;
use App\Services\SpbWorkflow;
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

new #[Title('Form SPB')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $spbId = null;

    /** @var array<string, mixed> */
    public array $form = ['vessel_id' => '', 'total_passengers' => '', 'passengers_none' => false, 'departure_port' => '', 'destination' => '', 'estimated_departure_at' => '', 'notes' => '', 'cargo_status' => 'not_applicable', 'cargo_description' => '', 'cargo_type' => '', 'cargo_quantity' => '', 'cargo_unit' => '', 'port_cargo_activity_applicable' => false, 'billing_code_labuh' => '', 'billing_code_rambu' => '', 'billing_code_mastercable' => '', 'arrival_spb_enabled' => false, 'arrival_billing_code_labuh' => '', 'arrival_billing_code_rambu' => '', 'arrival_billing_code_mastercable' => ''];


    public bool $carriesPassengers = false;

    /** @var array<string, mixed> */
    public array $documents = [];

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function mount(?SpbService $spb = null): void
    {
        if ($spb?->exists) {
            Gate::authorize('work', $spb);
            abort_unless($spb->isEditable(), 403);
            $this->spbId = $spb->id;
            $this->form = $spb->only(array_keys($this->form));
            $this->form['cargo_status'] ??= 'not_applicable';
            $this->form['passengers_none'] ??= false;
            $this->form['arrival_spb_enabled'] ??= false;
            $this->form['port_cargo_activity_applicable'] ??= false;
            $this->carriesPassengers = $spb->total_passengers > 0;
            foreach (['estimated_departure_at'] as $key) {
                $this->form[$key] = $spb->$key->setTimezone(config('spb.timezone'))->format('Y-m-d\TH:i');
            }
        }
    }

    public function updatedFormVesselId(): void
    {
        unset($this->selectedVessel, $this->checks);
        $this->form['total_passengers'] = $this->selectedVessel?->vessel_type === 'passenger' ? '' : 0;
        $this->form['passengers_none'] = false;
        $this->carriesPassengers = false;
    }

    public function updatedCarriesPassengers(): void
    {
        if (! $this->carriesPassengers && $this->selectedVessel?->vessel_type !== 'passenger') {
            $this->form['total_passengers'] = 0;
        }
    }

    public function updatedFormPassengersNone(): void
    {
        if ($this->form['passengers_none']) {
            $this->form['total_passengers'] = 0;
            unset($this->documents['passenger_manifest']);
        }
    }

    public function save(SpbWorkflow $workflow): void
    {
        try {
            $spb = $workflow->saveDraft($this->spbId ? SpbService::findOrFail($this->spbId) : null, $this->form, $this->documents);
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
        session()->flash('spb_status', $this->spbId ? 'Data SPB berhasil disimpan.' : 'SPB berhasil disimpan dan diarsipkan.');
        $this->redirectRoute('spb.show', ['spb' => $spb->id]);
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

    /** @return array<string, string> */
    #[Computed]
    public function documentTypes(): array
    {
        $types = array_diff_key(\App\Models\SpbDocument::APPLICATION_TYPES, array_flip(['spb_request_letter', 'rkbm', 'pbm', 'warta_kapal', 'payment_proof', 'pnbp_proof']));
        foreach ($types as $type => $label) {
            $types[$type] = $label.match ($type) {
                'spb_file' => $this->spbId === null ? ' (wajib)' : ' (unggah jika belum tersedia)',
                'passenger_manifest' => ' (opsional saat pembuatan)',
                default => ' (opsional)',
            };
        }

        return $types;
    }

    /** @return array<string, array{filename: string, url: string, available: bool}> */
    #[Computed]
    public function existingDocuments(): array
    {
        if ($this->spbId === null) {
            return [];
        }
        $spb = SpbService::with('documents')->findOrFail($this->spbId);
        Gate::authorize('work', $spb);
        $documents = [];
        foreach (array_keys(SpbDocument::APPLICATION_TYPES + SpbDocument::ARRIVAL_APPLICATION_TYPES) as $type) {
            $document = $spb->latestDocument($type);
            if ($document !== null) {
                $documents[$type] = [
                    'filename' => $document->original_filename,
                    'url' => route('spb.documents.download', [$spb, $document]),
                    'available' => Storage::disk('local')->exists($document->file_path),
                ];
            }
        }

        return $documents;
    }

    #[Computed]
    public function checks(): array
    {
        $date = $this->form['estimated_departure_at'] ?? null;
        $departure = is_string($date) && Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d\TH:i'])->passes()
            ? CarbonImmutable::parse($date, config('spb.timezone')) : null;

        return $this->selectedVessel
            ? app(SpbReadinessService::class)->vesselCheck($this->selectedVessel, $departure, $this->selectedVessel->currentCrewCount() + max(0, (int) ($this->form['total_passengers'] ?? 0)))
            : [];
    }
};
?>

<x-dashboard.shell page="Surat Persetujuan Berlayar" :demo="false">
    <div class="flex flex-wrap items-center justify-between gap-4"><div><p class="text-xs text-slate-500 dark:text-slate-400">Surat Persetujuan Berlayar</p><h1 class="mt-2 text-2xl font-semibold">{{ $spbId ? 'Edit SPB' : 'Buat SPB Baru' }}</h1></div><x-dashboard.add-button :href="route('spb.index')" variant="soft" icon="arrow" class="whitespace-nowrap [&_svg]:size-3.5 [&_svg]:rotate-180">Kembali ke monitoring</x-dashboard.add-button></div>
    <form novalidate wire:submit="save" class="mt-6 flex flex-col gap-6" x-data="{ uploading: 0 }" x-on:livewire-upload-start="uploading++" x-on:livewire-upload-finish="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-error="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-cancel="uploading = Math.max(0, uploading-1)">
        <x-form-errors id="spb-form-1-errors" />
        
        
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5 sm:p-6">
            <h2 class="text-base font-semibold">Service & Vessel</h2>
            @if ($this->vessels->isEmpty())
                <p class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-3 text-sm text-amber-800 dark:text-amber-300">Belum ada vessel. <a href="{{ route('dashboard.vessel') }}" class="font-semibold underline">Daftarkan kapal di Vessel</a> untuk membuat SPB ini.</p>
            @endif
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <x-dashboard.vessel-selector :vessels="$this->vessels" :selected-vessel="$this->selectedVessel" :disabled="(bool) $spbId" />
            </div>
        </section>
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5 sm:p-6">
            <h2 class="text-base font-semibold">Voyage Information</h2><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Semua tanggal dan jam menggunakan WITA (Asia/Makassar).</p>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <div class="min-w-0"><x-dashboard.port-input name="departure_port" label="Departure Port *" wire:model="form.departure_port" :value="$form['departure_port']" maxlength="255" required /></div>
                <div class="min-w-0"><x-dashboard.port-input name="destination" label="Destination Port / Area *" wire:model="form.destination" :value="$form['destination']" maxlength="255" required /></div>
                <div class="min-w-0"><x-auth.input name="estimated_departure_at" label="Estimated Departure Date & Time *" type="datetime-local" shortcut="tomorrow-morning" wire:model.live="form.estimated_departure_at" :value="$form['estimated_departure_at']" required /></div>
                <div class="min-w-0"><x-auth.input name="notes" label="Notes (opsional)" wire:model="form.notes" :value="$form['notes'] ?? ''" maxlength="5000" /></div>
            </div>
        </section>
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5 sm:p-6">
            <h2 class="text-base font-semibold">Passenger Information</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Vessel Type: {{ $this->selectedVessel?->typeLabel() ?? 'Pilih vessel' }}</p>
            @if ($this->selectedVessel && $this->selectedVessel->vessel_type !== 'passenger')
                <label class="mt-4 flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="carriesPassengers" /> Membawa penumpang</label>
            @endif
            @if ($this->selectedVessel?->vessel_type === 'passenger' || $carriesPassengers || ($form['total_passengers'] ?? 0) > 0)
                <div class="mt-4"><x-auth.input name="total_passengers" label="Total Passenger *" type="number" wire:model.live="form.total_passengers" :value="$form['total_passengers'] ?? ''" min="0" max="1000000" step="1" :disabled="$form['passengers_none'] ?? false" required /></div>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Passenger Manifest bersifat opsional.</p>
            @endif
        </section>
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5 sm:p-6">
            <h2 class="text-base font-semibold">Cargo / Operational Information</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><label for="cargo_status" class="mb-2 block text-sm font-medium">Cargo Status *</label><select id="cargo_status" wire:model.live="form.cargo_status" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-3 text-sm">@foreach (\App\Models\SpbService::CARGO_STATUSES as $key => $label)<option wire:key="cargo-status-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="form.port_cargo_activity_applicable" /> Aktivitas bongkar muat berlaku</label>
                
                @if (($form['cargo_status'] ?? '') === 'carrying_cargo')
                    <x-auth.input name="cargo_description" label="Cargo Description *" wire:model="form.cargo_description" :value="$form['cargo_description'] ?? ''" maxlength="5000" required />
                    <x-auth.input name="cargo_type" label="Cargo Type" wire:model="form.cargo_type" :value="$form['cargo_type'] ?? ''" maxlength="255" />
                    <x-auth.input name="cargo_quantity" label="Cargo Quantity" type="number" wire:model="form.cargo_quantity" :value="$form['cargo_quantity'] ?? ''" min="0" max="999999999999.999" step="0.001" />
                    <x-auth.input name="cargo_unit" label="Cargo Unit" wire:model="form.cargo_unit" :value="$form['cargo_unit'] ?? ''" maxlength="50" />
                @endif
            </div>
        </section>
        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 sm:p-6">
            <h2 class="text-base font-semibold">Kode billing PNBP</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <x-auth.input name="billing_code_labuh" label="Kode billing labuh" wire:model="form.billing_code_labuh" :value="$form['billing_code_labuh'] ?? ''" maxlength="255" />
                <x-auth.input name="billing_code_rambu" label="Kode billing rambu" wire:model="form.billing_code_rambu" :value="$form['billing_code_rambu'] ?? ''" maxlength="255" />
                <x-auth.input name="billing_code_mastercable" label="Kode billing mastercable" wire:model="form.billing_code_mastercable" :value="$form['billing_code_mastercable'] ?? ''" maxlength="255" />
            </div>
        </section>
        @if ($this->selectedVessel)<x-dashboard.spb-vessel-check :checks="$this->checks" :vessel="$this->selectedVessel" :service-id="$spbId" />@endif
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5 sm:p-6">
            <h2 class="text-base font-semibold">SPB Documents</h2><p class="mt-2 mb-4 text-xs leading-5 text-slate-500 dark:text-slate-400">File SPB wajib untuk menyelesaikan layanan. Dokumen lainnya opsional. PDF/JPG/JPEG/PNG/WebP/GIF/DOC/DOCX, maksimal {{ config('spb.document_max_kb') / 1024 }} MB per file; gambar maksimal 4 MB. File baru menjadi versi terkini; versi sebelumnya tetap disimpan. Biarkan kosong untuk mempertahankan dokumen.</p>
            <label class="mb-4 flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="form.passengers_none" /> Penumpang nihil (tidak ada penumpang)</label>
            <x-dashboard.spb-uploads :types="$this->documentTypes" :existing="$this->existingDocuments" :selected="$documents" :disabled-types="($form['passengers_none'] ?? false) ? ['passenger_manifest'] : []" />
        </section>
        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 sm:p-6">
            <h2 class="text-base font-semibold">SPB kedatangan</h2>
            <label class="mt-4 flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="form.arrival_spb_enabled" /> Tambahkan SPB kedatangan</label>
            @if ($form['arrival_spb_enabled'] ?? false)
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Semua kode billing dan dokumen kedatangan bersifat opsional.</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <x-auth.input name="arrival_billing_code_labuh" label="Kode billing labuh kedatangan" wire:model="form.arrival_billing_code_labuh" :value="$form['arrival_billing_code_labuh'] ?? ''" maxlength="255" />
                    <x-auth.input name="arrival_billing_code_rambu" label="Kode billing rambu kedatangan" wire:model="form.arrival_billing_code_rambu" :value="$form['arrival_billing_code_rambu'] ?? ''" maxlength="255" />
                    <x-auth.input name="arrival_billing_code_mastercable" label="Kode billing master cable kedatangan" wire:model="form.arrival_billing_code_mastercable" :value="$form['arrival_billing_code_mastercable'] ?? ''" maxlength="255" />
                </div>
                <div class="mt-4"><x-dashboard.spb-uploads :types="['arrival_spb' => 'File SPB kedatangan (opsional)', 'arrival_entry_approval' => 'Surat Persetujuan Masuk (opsional)', 'arrival_pkk' => 'PKK (opsional)']" :existing="$this->existingDocuments" :selected="$documents" /></div>
            @endif
        </section>
        <div class="flex flex-wrap items-center justify-end gap-4"><span x-cloak x-show="uploading > 0" class="text-xs text-blue-600 dark:text-blue-400">Tunggu upload selesai...</span><button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="rounded-lg bg-blue-500 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-600 disabled:opacity-50"><span wire:loading.remove wire:target="save">{{ $spbId ? 'Simpan perubahan' : 'Simpan SPB' }}</span><span wire:loading.inline-flex wire:target="save" class="items-center gap-2"><span aria-hidden="true" class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent motion-reduce:animate-none"></span>Menyimpan...</span></button></div>
    </form>
</x-dashboard.shell>
