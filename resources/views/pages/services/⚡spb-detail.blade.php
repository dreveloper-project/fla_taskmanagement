<?php

use App\Models\SpbService;
use App\Services\ServiceDeletion;
use App\Services\SpbReadinessService;
use App\Services\SpbWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Detail SPB')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?string $errorForm = null;

    #[Locked]
    public int $spbId;

    /** @var array<string, mixed> */
    public array $form = ['result' => 'revision_required', 'submitted_at' => '', 'inaportnet_reference' => '', 'notes' => '', 'revision_notes' => '', 'revision_items' => '', 'rejection_reason' => '', 'spb_number' => '', 'spb_issued_at' => '', 'sailing_result' => '', 'failure_reason' => '', 'arrival_spb_number' => ''];

    /** @var array<string, mixed> */
    public array $documents = [];

    /** @var array{actual_arrival_at: string, notes: string} */
    public array $arrival = ['actual_arrival_at' => '', 'notes' => ''];

    /** @var array{actual_departure_at: string, expected_arrival_at: string, notes: string} */
    public array $departure = ['actual_departure_at' => '', 'expected_arrival_at' => '', 'notes' => ''];

    public function deleteService(ServiceDeletion $deletion): void
    {
        $service = SpbService::findOrFail($this->spbId);
        Gate::authorize('delete', $service);
        $this->resetValidation();
        try {
            $deletion->delete($service);
        } catch (AuthorizationException|ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('deletion', 'Service belum dapat dihapus. Silakan coba lagi.');

            return;
        }
        session()->flash('spb_status', 'Service beserta dokumen terkait berhasil dihapus. Dokumen Vessel tetap disimpan.');
        $this->redirect(route('spb.index'), navigate: true);
    }

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function mount(SpbService $spb): void
    {
        $this->spbId = $spb->id;
        $this->form['actual_departure_at'] = $spb->actual_departure_at?->timezone(config('spb.timezone'))->format('Y-m-d\TH:i')
            ?? today(config('spb.timezone'))->setTime(8, 0)->format('Y-m-d\TH:i');
    }

    public function updatedFormResult(): void
    {
        $this->documents = [];
        $this->resetValidation();
    }

    public function updatedFormSailingResult(): void
    {
        $this->documents = [];
        $this->resetValidation();
    }

    public function ready(SpbWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->ready($this->spb), __FUNCTION__);
    }

    public function apply(SpbWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->apply($this->spb, $this->form), __FUNCTION__);
    }

    public function recordResult(SpbWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->recordResult($this->spb, $this->form, $this->documents), __FUNCTION__);
    }

    public function restoreIssuedSpb(SpbWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->restoreIssuedSpb($this->spb, $this->documents), __FUNCTION__);
    }

    public function finish(SpbWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->finish($this->spb, $this->form, $this->documents), __FUNCTION__);
    }

    public function confirmArrival(SpbWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->confirmArrival($this->spb, $this->arrival), __FUNCTION__);
    }

    public function confirmDeparture(SpbWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->depart($this->spb, $this->departure), __FUNCTION__);
    }

    private function perform(Closure $action, string $form): void
    {
        Gate::authorize('work', $this->spb->fresh());
        $this->errorForm = $form;
        $this->resetValidation();
        try {
            $action();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException || $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $exception;
            }
            report($exception);
            $this->addError('save', 'Proses gagal. Perubahan belum tersimpan. Silakan coba kembali.');

            return;
        }
        $this->reset('form', 'documents', 'arrival', 'departure');
        $this->form['actual_departure_at'] = $this->spb->actual_departure_at?->timezone(config('spb.timezone'))->format('Y-m-d\TH:i')
            ?? today(config('spb.timezone'))->setTime(8, 0)->format('Y-m-d\TH:i');
        unset($this->spb, $this->readiness);
        if (in_array($form, ['confirmDeparture', 'confirmArrival', 'finish'], true)) {
            $this->dispatch('vessel-status-updated');
        }
        session()->flash('spb_status', match ($form) {
            'confirmArrival' => 'Kedatangan dikonfirmasi. Pelayaran selesai dan kapal berstatus Sandar.',
            'confirmDeparture' => 'Keberangkatan dikonfirmasi. Vessel berstatus Berlayar.',
            default => 'Proses SPB berhasil diperbarui.',
        });
    }

    #[Computed]
    public function spb(): SpbService
    {
        return SpbService::with(['vessel.documents', 'creator', 'assignedStaff', 'documents.uploader', 'histories.actor'])->findOrFail($this->spbId);
    }

    #[Computed]
    public function readiness(): array
    {
        return app(SpbReadinessService::class)->check($this->spb);
    }
};
?>

<x-dashboard.shell page="Surat Persetujuan Berlayar" :demo="false">
    @php($spb = $this->spb)
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-xs text-slate-500 dark:text-slate-400">Surat Persetujuan Berlayar</p><h1 class="mt-2 text-2xl font-semibold">{{ $spb->service_number }}</h1><div class="mt-3"><x-dashboard.spb-status :status="$spb->status" /></div></div>
        <div class="flex flex-wrap items-center gap-3">
            <x-dashboard.add-button :href="route('spb.index')" variant="soft" icon="arrow" class="whitespace-nowrap [&_svg]:size-3.5 [&_svg]:rotate-180">Kembali ke monitoring</x-dashboard.add-button>
            <x-dashboard.service-archive-download :service="$spb" />
        </div>
    </div>
    <x-dashboard.service-delete :service="$spb" />
    @if (session('spb_status'))<p data-form-success role="status" class="mt-5 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 p-4 text-sm text-emerald-700 dark:text-emerald-300">{{ session('spb_status') }}</p>@endif
    @if (in_array($spb->status, \App\Models\SpbService::TERMINAL, true) && in_array($spb->sailing_result, ['success', 'not_sailing', 'failed'], true))
        <section class="mt-6 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
            <h2 class="text-base font-semibold">Hasil SPB</h2>
            @if ($spb->sailing_result === 'not_sailing')<p class="mt-3 font-semibold text-amber-700 dark:text-amber-300">NOT SAILING</p>@endif
            @if ($spb->sailing_result === 'success')<p class="mt-3 font-semibold text-emerald-700 dark:text-emerald-300">BERHASIL BERLAYAR</p>@if ($spb->actual_departure_at)<p class="mt-1 text-sm">Keberangkatan: {{ $spb->actual_departure_at->timezone(config('spb.timezone'))->format('d M Y H:i') }} WITA</p>@endif @endif
            @if ($spb->sailing_result === 'failed')<p class="mt-3 font-semibold text-rose-700 dark:text-rose-300">GAGAL BERLAYAR</p><p class="mt-1 text-sm text-rose-700 dark:text-rose-300">{{ $spb->failure_reason }}</p>@endif
        </section>
    @endif

    @can('work', $spb)
    <section class="mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5" x-data="{ uploading: 0 }" x-on:livewire-upload-start="uploading++" x-on:livewire-upload-finish="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-error="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-cancel="uploading = Math.max(0, uploading-1)">
        <h2 class="text-base font-semibold">Action Area</h2>
        <x-form-errors id="readiness-errors" :show="$errorForm === 'ready'" class="mt-4" />
        @if ($spb->isEditable())
            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('spb.edit', $spb) }}" class="rounded-lg border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-sm text-blue-600 dark:text-blue-400">Edit / Upload Documents</a>
                @if (in_array($spb->status, ['draft', 'revision_required'], true))
                    <button type="button" wire:click="ready" wire:loading.attr="disabled" class="rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ $spb->status === 'draft' ? 'Check Readiness / Ready To Apply' : 'Ready to Resubmit' }}</button>
                @endif
            </div>
        @endif

        @if (in_array($spb->status, ['ready_to_apply', 'applied'], true) || ($spb->status === 'revision_required' && $spb->revision_ready_at))
            <form novalidate wire:submit="recordResult" class="mt-5 flex flex-col gap-4">
        <x-form-errors id="recordResult-errors" :fields="$errorForm === 'recordResult' ? [] : ['documents', 'documents.*']" />
                <h3 class="text-sm font-semibold">Reply From Inaportnet</h3>
                <div><label for="result" class="mb-2 block text-sm font-medium">Hasil *</label><select id="result" wire:model.live="form.result" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-3 text-sm"><option value="revision_required">Revision Required</option><option value="rejected">Rejected</option><option value="approved">Approved / SPB Issued</option></select></div>
                @if ($form['result'] === 'revision_required')
                    <x-auth.input name="revision_notes" label="Revision Notes *" wire:model="form.revision_notes" required maxlength="5000" />
                    <x-auth.input name="revision_items" label="Bagian / dokumen yang harus diperbaiki *" wire:model="form.revision_items" required maxlength="5000" />
                    <x-dashboard.spb-uploads :types="['revision_attachment' => 'Bukti revisi (opsional)']" />
                @elseif ($form['result'] === 'rejected')
                    <x-auth.input name="rejection_reason" label="Rejection Reason *" wire:model="form.rejection_reason" required maxlength="5000" />
                    <x-dashboard.spb-uploads :types="['rejection_attachment' => 'Bukti penolakan (opsional)']" />
                @elseif ($form['result'] === 'approved')
                    <x-auth.input name="spb_number" label="SPB Number (opsional)" wire:model="form.spb_number" maxlength="255" />
                    <x-auth.input name="spb_issued_at" label="SPB Issued Date & Time (WITA) *" type="datetime-local" wire:model="form.spb_issued_at" required />
                    <x-dashboard.spb-uploads :types="['issued_spb' => 'SPB PDF / File resmi (wajib)', 'lk3_nakhoda' => 'LK3 Nakhoda (opsional)']" />
                @endif
                <p class="text-xs text-slate-500 dark:text-slate-400">PDF/JPG/JPEG/PNG/WebP/GIF/DOC/DOCX, maksimal {{ config('spb.document_max_kb') / 1024 }} MB per file; gambar maksimal 4 MB.</p>
                <x-auth.input name="notes" label="Notes (opsional)" wire:model="form.notes" maxlength="5000" />
                <button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="w-fit rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Simpan hasil Inaportnet</button>
            </form>
        @endif

        @if (in_array($spb->status, ['ready_to_sail', 'sailing'], true))
            @php($issuedSpb = $spb->latestDocument('issued_spb'))
            @if ($issuedSpb === null || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($issuedSpb->file_path))
                <form novalidate wire:submit="restoreIssuedSpb" class="mt-5 flex flex-col gap-4 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                    <h3 class="text-sm font-semibold">Unggah ulang SPB resmi</h3>
                    <p class="text-xs text-slate-600 dark:text-slate-300">File SPB resmi tidak tersedia. Unggah kembali dokumen sebelum memilih Sail Completed.</p>
                    <x-form-errors id="restore-spb-errors" :show="$errorForm === 'restoreIssuedSpb'" />
                    <x-dashboard.spb-uploads :types="['issued_spb' => 'SPB resmi *']" :selected="$documents" />
                    <button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="w-fit rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Simpan SPB resmi</button>
                </form>
            @endif
            <a href="{{ route('dashboard.vessel', ['vessel' => $spb->vessel_id]) }}" class="mt-4 inline-block rounded-lg bg-blue-500 px-4 py-3 text-sm font-semibold text-white">Lihat Vessel</a>
            <form novalidate wire:submit="finish" class="mt-5 flex flex-col gap-4">
        <x-form-errors id="finish-errors" :fields="$errorForm === 'finish' ? [] : ['documents', 'documents.*']" />
                <h3 class="text-sm font-semibold">Hasil SPB</h3>
                <div><label for="sailing_result" class="mb-2 block text-sm font-medium">Hasil *</label><select id="sailing_result" wire:model.live="form.sailing_result" required class="w-full rounded-xl border border-slate-200 px-3 py-3 text-sm dark:border-slate-700"><option value="">Pilih hasil SPB</option><option value="success">Sail Completed</option><option value="not_sailing">Not Sailing</option><option value="failed">Fail to Sail</option></select></div>
                @if ($form['sailing_result'] === 'success')
                    <x-auth.input name="actual_departure_at" label="Tanggal dan waktu keberangkatan (WITA) *" type="datetime-local" wire:model="form.actual_departure_at" :value="$form['actual_departure_at']" required />
                    <h4 class="text-sm font-semibold">SPB kedatangan (opsional)</h4>
                    <x-auth.input name="arrival_spb_number" label="Nomor SPB kedatangan (opsional)" wire:model="form.arrival_spb_number" maxlength="255" />
                    <x-dashboard.spb-uploads :types="['arrival_spb' => 'File SPB kedatangan (opsional)']" :selected="$documents" />
                @endif
                @if ($form['sailing_result'] === 'failed')
                    <x-auth.input name="failure_reason" label="Alasan gagal berlayar *" wire:model="form.failure_reason" required maxlength="5000" />
                    <x-dashboard.spb-uploads :types="['sailing_failure_attachment' => 'Bukti pendukung (opsional)']" />
                @endif
                <x-auth.input name="notes" label="Notes (opsional)" wire:model="form.notes" maxlength="5000" />
                <button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="w-fit rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Simpan hasil & arsipkan</button>
            </form>
        @elseif (in_array($spb->status, \App\Models\SpbService::TERMINAL))
            <p class="mt-4 text-sm text-slate-600 dark:text-slate-300">Pengajuan diarsipkan pada {{ $spb->archived_at?->setTimezone(config('spb.timezone'))->format('d M Y H:i') }} WITA. Data, dokumen, dan riwayat tetap tersedia.</p>

        @endif
        <p wire:loading class="mt-3 text-xs text-blue-600 dark:text-blue-400">Memproses...</p>
        <p x-cloak x-show="uploading > 0" class="mt-3 text-xs text-blue-600 dark:text-blue-400">Tunggu upload selesai...</p>
    </section>
    @endcan

    @if ($spb->actual_arrival_at)
        <section aria-label="Kedatangan kapal" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30">
            <div class="flex items-center gap-2 text-sm font-semibold text-emerald-700 dark:text-emerald-300"><x-dashboard.icon name="check" class="size-5" />Selesai Berlayar</div>
            <p class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">Kedatangan di {{ $spb->destination }} tercatat pada <time datetime="{{ $spb->actual_arrival_at->toIso8601String() }}">{{ $spb->actual_arrival_at->timezone(config('spb.timezone'))->format('d M Y H:i') }} WITA</time>.</p>
        </section>
    @endif
    <div class="mt-6 grid items-start gap-6 xl:grid-cols-2">
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Service Information</h2>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                @foreach (['Service Number' => $spb->service_number, 'PIC' => $spb->assignedStaff?->name ?? $spb->creator->name, 'Created At (WITA)' => $spb->created_at->setTimezone(config('spb.timezone'))->format('d M Y H:i')] as $label => $value)
                    <div wire:key="spb-info-{{ $loop->index }}"><dt class="text-xs text-slate-400">{{ $label }}</dt><dd class="mt-1 wrap-break-word">{{ $value }}</dd></div>
                @endforeach
            </dl>
            <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">{{ $spb->notes }}</p>
        </section>
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Vessel Information</h2>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                @foreach (['Vessel' => $spb->vessel->vessel_name, 'Vessel Type' => $spb->vessel->typeLabel(), 'Owner / Operator' => $spb->vessel->ownerName()] as $label => $value)
                    <div wire:key="spb-vessel-info-{{ $loop->index }}"><dt class="text-xs text-slate-400">{{ $label }}</dt><dd class="mt-1 wrap-break-word">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Voyage Information</h2>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                @foreach (['Departure Port' => $spb->departure_port, 'Destination' => $spb->destination, 'Total Crew' => $spb->vessel->currentCrewCount(), 'Estimated Departure (WITA)' => $spb->estimated_departure_at->setTimezone(config('spb.timezone'))->format('d M Y H:i')] as $label => $value)
                    <div wire:key="spb-voyage-{{ $loop->index }}"><dt class="text-xs text-slate-400">{{ $label }}</dt><dd class="mt-1">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>
        @if ($spb->hasPassengerInformation())
            <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
                <h2 class="text-base font-semibold">Passenger Information</h2>
                <p class="mt-4 text-sm">Total Passenger: {{ $spb->total_passengers }}</p>
                @if ($spb->passengers_none)<p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Penumpang nihil · Passenger Manifest tidak diperlukan.</p>@endif
            </section>
        @endif
        @if ($spb->cargo_status !== null || $spb->port_cargo_activity_applicable)
            <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
                <h2 class="text-base font-semibold">Cargo / Operational Information</h2>
                <p class="mt-4 text-sm">Cargo Status: {{ \App\Models\SpbService::CARGO_STATUSES[$spb->cargo_status] ?? 'Belum diisi' }}</p>
                @if ($spb->cargo_status === 'carrying_cargo')
                    <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                        @foreach (['Deskripsi muatan' => $spb->cargo_description, 'Cargo Type' => $spb->cargo_type, 'Cargo Quantity' => $spb->cargo_quantity, 'Cargo Unit' => $spb->cargo_unit] as $label => $value)
                            <div wire:key="cargo-info-{{ $loop->index }}"><dt class="text-xs text-slate-400">{{ $label }}</dt><dd class="mt-1">{{ $value ?? '-' }}</dd></div>
                        @endforeach
                    </dl>
                @endif
                <p class="mt-4 text-sm">Aktivitas bongkar muat: {{ $spb->port_cargo_activity_applicable ? 'Berlaku' : 'Tidak berlaku' }}</p>
            </section>
        @endif
        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
            <h2 class="text-base font-semibold">Kode billing PNBP</h2>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                @foreach (['Labuh' => $spb->billing_code_labuh, 'Rambu' => $spb->billing_code_rambu, 'Mastercable' => $spb->billing_code_mastercable] as $label => $code)
                    <div><dt class="text-xs text-slate-400">{{ $label }}</dt><dd class="mt-1 break-all">{{ $code ?? 'Belum dicatat' }}</dd></div>
                @endforeach
            </dl>
        </section>
        @if ($spb->arrival_spb_number !== null)
            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                <h2 class="text-base font-semibold">SPB kedatangan</h2>
                <p class="mt-4 text-sm">{{ $spb->arrival_spb_number }}</p>
            </section>
        @endif
        <x-dashboard.spb-vessel-check :checks="$this->readiness['vessel_checks']" :vessel="$spb->vessel" :service-id="$spb->id" />
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Inaportnet Monitoring</h2>
            <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">Pengajuan dan penerbitan SPB dilakukan melalui Inaportnet di luar FLA. Hasil dicatat manual oleh staf.</p>
            <dl class="mt-4 flex flex-col gap-3 text-sm">
                <div><dt class="text-xs text-slate-400">Reference</dt><dd>{{ $spb->inaportnet_reference ?? 'Belum diisi' }}</dd></div>
                <div><dt class="text-xs text-slate-400">Submitted At (WITA)</dt><dd>{{ $spb->submitted_at?->setTimezone(config('spb.timezone'))->format('d M Y H:i') ?? 'Tidak dicatat' }}</dd></div>
                @if ($spb->resubmitted_at)<div><dt class="text-xs text-slate-400">Resubmitted At (WITA)</dt><dd>{{ $spb->resubmitted_at->setTimezone(config('spb.timezone'))->format('d M Y H:i') }}</dd></div>@endif
            </dl>
            @if ($spb->revision_notes)<div class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-3 text-sm"><p class="font-medium">Catatan revisi</p><p class="mt-1 whitespace-pre-line">{{ $spb->revision_notes }}</p><p class="mt-2 whitespace-pre-line">{{ $spb->revision_items }}</p></div>@endif
            @if ($spb->rejection_reason)<p class="mt-4 rounded-lg bg-rose-50 dark:bg-rose-950/40 p-3 text-sm text-rose-700 dark:text-rose-300">Alasan penolakan: {{ $spb->rejection_reason }}</p>@endif
        </section>
    </div>

    <section class="mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
        <h2 class="text-base font-semibold">SPB Documents & Archive</h2>
        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Versi terbaru digunakan untuk pemeriksaan kelengkapan. Versi lama dan salinan dokumen kapal saat pengajuan tetap dapat diunduh.</p>
        @if (in_array($spb->status, \App\Models\SpbService::PENDING) && ! $this->readiness['complete'])
            <div class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-4 text-xs text-amber-800 dark:text-amber-300"><p class="font-semibold">BELUM LENGKAP · Masih perlu dilengkapi:</p><ul class="mt-2 list-disc pl-4">@foreach ($this->readiness['missing'] as $missing)<li wire:key="missing-{{ $loop->index }}">{{ $missing }}</li>@endforeach</ul>
                <div class="mt-3 flex flex-wrap gap-2">
                    @can('work', $spb)
                        @if ($spb->isEditable())
                            <x-dashboard.add-button :href="route('spb.edit', $spb)" variant="soft" compact icon="edit">Lengkapi dokumen layanan</x-dashboard.add-button>
                        @endif
                    @endcan
                </div>
            </div>
        @elseif ($this->readiness['complete'])
            <p class="mt-4 text-sm text-emerald-700 dark:text-emerald-300">Persyaratan proses SPB terpenuhi. Pemeriksaan dokumen kapal ditampilkan sebagai peringatan.</p>
        @endif
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            @forelse ($spb->documents->sortByDesc('id') as $document)
                <div wire:key="spb-doc-{{ $document->id }}" class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                    <p class="text-sm font-medium">{{ $document->label() }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 wrap-break-word">{{ \App\Services\DocumentFilename::forService($document, $spb->vessel) }}</p>
                    <p class="mt-2 text-[11px] text-slate-400">{{ $document->uploaded_at->setTimezone(config('spb.timezone'))->format('d M Y H:i') }} WITA · {{ $document->uploader->name }}</p>
                    @if ($document->document_type === 'issued_spb' && ! \Illuminate\Support\Facades\Storage::disk('local')->exists($document->file_path))
                        <p class="mt-3 text-xs text-amber-700 dark:text-amber-300">File tidak tersedia</p>
                    @else
                        <x-dashboard.download-button :href="route('spb.documents.download', [$spb->id, $document->id])" class="mt-3">Unduh dokumen</x-dashboard.download-button>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-500 dark:text-slate-400">Belum ada dokumen SPB.</p>
            @endforelse
        </div>
    </section>

    @if ($spb->arrival_spb_enabled || $spb->arrival_billing_code_labuh || $spb->arrival_billing_code_rambu || $spb->arrival_billing_code_mastercable)
        <section class="mt-6 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
            <h2 class="text-base font-semibold">Kode billing SPB kedatangan</h2>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                @foreach (['Labuh' => $spb->arrival_billing_code_labuh, 'Rambu' => $spb->arrival_billing_code_rambu, 'Master cable' => $spb->arrival_billing_code_mastercable] as $label => $code)
                    <div><dt class="text-xs text-slate-400">{{ $label }}</dt><dd class="mt-1 break-all">{{ $code ?? 'Belum dicatat' }}</dd></div>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($spb->spb_issued_at)
        <section class="mt-6 rounded-xl border border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 p-5">
            <h2 class="font-semibold text-emerald-800 dark:text-emerald-300">SPB Issued{{ $spb->status === 'ready_to_sail' ? ' · Vessel Ready to Sail' : '' }}</h2>
            <p class="mt-2 text-sm">SPB Number: {{ $spb->spb_number ?? 'Belum dicatat' }} · {{ $spb->spb_issued_at->setTimezone(config('spb.timezone'))->format('d M Y H:i') }} WITA</p>
            <p class="mt-2 text-sm">{{ $spb->vessel->vessel_name }} → {{ $spb->destination }}</p>
            <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">Estimated Departure: {{ $spb->estimated_departure_at->setTimezone(config('spb.timezone'))->format('d M Y H:i') }} WITA</p>
            @if ($spb->actual_departure_at)
                <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">Keberangkatan aktual: {{ $spb->actual_departure_at->timezone(config('spb.timezone'))->format('d M Y H:i') }} WITA</p>
            @endif
            @if ($spb->expected_arrival_at)
                <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">Perkiraan tiba (ETA): {{ $spb->expected_arrival_at->timezone(config('spb.timezone'))->format('d M Y H:i') }} WITA</p>
            @endif
            @if (($issuedDocument = $spb->latestDocument('issued_spb')) && \Illuminate\Support\Facades\Storage::disk('local')->exists($issuedDocument->file_path))<x-dashboard.download-button :href="route('spb.documents.download', [$spb->id, $issuedDocument->id])" class="mt-3">Unduh SPB resmi</x-dashboard.download-button>@endif
        </section>
    @endif

    <section class="mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
        <h2 class="text-base font-semibold">Status Timeline / History</h2>
        <ol class="mt-5 flex flex-col gap-5">
            @foreach ($spb->histories->sortByDesc('id') as $history)
                <li wire:key="spb-history-{{ $history->id }}" class="border-l-2 border-blue-100 dark:border-blue-900 pl-4">
                    <p class="text-xs text-slate-400">{{ $history->created_at->setTimezone(config('spb.timezone'))->format('d M Y H:i') }} WITA · {{ $history->actor->name }}</p>
                    <p class="mt-1 text-sm font-semibold">{{ __($history->event) }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $history->from_status ? \App\Models\SpbService::STATUSES[$history->from_status].' → ' : '' }}{{ \App\Models\SpbService::STATUSES[$history->to_status] }}</p>
                    @if ($history->notes)<p class="mt-2 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ __($history->notes) }}</p>@endif
                    @foreach (['submitted_at' => 'Submission (WITA)', 'inaportnet_reference' => 'Reference', 'revision_items' => 'Perbaikan', 'actual_departure_at' => 'Departure (WITA)'] as $key => $label)
                        @if (filled($history->metadata[$key] ?? null))<p wire:key="history-{{ $history->id }}-{{ $key }}" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $label }}: {{ $history->metadata[$key] }}</p>@endif
                    @endforeach
                </li>
            @endforeach
        </ol>
    </section>
</x-dashboard.shell>
