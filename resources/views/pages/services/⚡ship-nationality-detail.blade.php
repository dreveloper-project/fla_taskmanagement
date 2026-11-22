<?php

use App\Models\ShipNationalityService;
use App\Services\ServiceDeletion;
use App\Services\ShipNationalityReadinessService;
use App\Services\ShipNationalityWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Detail Dokumen Kebangsaan')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?string $errorForm = null;

    #[Locked]
    public int $serviceId;

    /** @var array<string, mixed> */
    public array $form = [
        'result' => 'revision_required', 'submitted_at' => '', 'submission_reference' => '', 'authority' => '', 'notes' => '',
        'revision_notes' => '', 'rejection_reason' => '', 'cancellation_reason' => '',
        'issued_document_number' => '', 'issued_at' => '', 'expired_at' => '',
        'issuing_authority' => '', 'handed_over_to' => '', 'recipient_role' => '', 'handed_over_at' => '',
    ];

    /** @var array<string, mixed> */
    public array $documents = [];

    public function deleteService(ServiceDeletion $deletion): void
    {
        $service = ShipNationalityService::findOrFail($this->serviceId);
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
        session()->flash('nationality_status', 'Service beserta dokumen terkait berhasil dihapus. Dokumen Vessel tetap disimpan.');
        $this->redirect(route('ship-nationality.index'), navigate: true);
    }

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function mount(ShipNationalityService $service): void
    {
        $this->serviceId = $service->id;
    }

    public function updatedFormResult(): void
    {
        $this->documents = [];
        $this->resetValidation();
    }

    public function ready(ShipNationalityWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->ready($this->service), __FUNCTION__);
    }

    public function apply(ShipNationalityWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->apply($this->service, $this->form), __FUNCTION__);
    }

    public function recordReview(ShipNationalityWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->recordReview($this->service, $this->form, $this->documents), __FUNCTION__);
    }

    public function handover(ShipNationalityWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->handover($this->service, $this->form, $this->documents), __FUNCTION__);
    }

    public function cancel(ShipNationalityWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->cancel($this->service, $this->form), __FUNCTION__);
    }

    private function perform(Closure $action, string $form): void
    {
        Gate::authorize('work', $this->service->fresh());
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
        $this->reset('form', 'documents');
        unset($this->service, $this->readiness);
        session()->flash('nationality_status', 'Proses layanan berhasil diperbarui.');
    }

    #[Computed]
    public function service(): ShipNationalityService
    {
        return ShipNationalityService::with(['vessel.documents', 'assignedStaff', 'creator', 'issuedDocument', 'documents.uploader', 'histories.actor'])->findOrFail($this->serviceId);
    }

    #[Computed]
    public function readiness(): array
    {
        return app(ShipNationalityReadinessService::class)->check($this->service);
    }
};
?>

<x-dashboard.shell page="Surat Laut / Pas Besar / Pas Kecil" :demo="false">
    @php($service = $this->service)
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-xs text-slate-500 dark:text-slate-400">Surat Laut / Pas Besar / Pas Kecil</p><h1 class="mt-2 text-2xl font-semibold">{{ $service->service_number }}</h1><div class="mt-3"><x-dashboard.ship-nationality-status :status="$service->status" /></div></div>
        <div class="flex flex-wrap items-center gap-3">
            <x-dashboard.add-button :href="route('ship-nationality.index')" variant="soft" icon="arrow" class="whitespace-nowrap [&_svg]:size-3.5 [&_svg]:rotate-180">Kembali ke monitoring</x-dashboard.add-button>
            <x-dashboard.service-archive-download :service="$service" />
        </div>
    </div>
    <x-dashboard.service-delete :service="$service" />
    @if (session('nationality_status'))<p data-form-success role="status" class="mt-5 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 p-4 text-sm text-emerald-700 dark:text-emerald-300">{{ session('nationality_status') }}</p>@endif
    @if (in_array($service->status, \App\Models\ShipNationalityService::ACTIVE) && $service->due_at?->isPast())<p class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-4 text-sm text-amber-800 dark:text-amber-300">Layanan melewati target penyelesaian.</p>@endif
    @if ($service->status === 'ready_for_handover')<p class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-4 text-sm text-amber-800 dark:text-amber-300">Menunggu serah terima. Dokumen siap diserahkan kepada penerima.</p>@endif

    <div class="mt-6 grid items-start gap-6 xl:grid-cols-2">
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Service Information</h2>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                @foreach (['Service Number' => $service->service_number, 'Document Type' => \App\Models\ShipNationalityService::DOCUMENT_TYPES[$service->document_type], 'Request Type' => \App\Models\ShipNationalityService::REQUEST_TYPES[$service->request_type], 'Status' => $service->statusLabel(), 'PIC' => $service->assignedStaff?->name ?? 'Belum ditentukan', 'Created At (WITA)' => $service->created_at->timezone(config('ship-nationality.timezone'))->format('d M Y H:i'), 'Target (WITA)' => $service->due_at?->timezone(config('ship-nationality.timezone'))->format('d M Y H:i')] as $label => $value)
                    <div wire:key="nationality-info-{{ $loop->index }}"><dt class="text-xs text-slate-400">{{ $label }}</dt><dd class="mt-1 wrap-break-word">{{ $value ?? '-' }}</dd></div>
                @endforeach
            </dl><p class="mt-4 text-sm text-slate-500 dark:text-slate-400">{{ $service->notes }}</p>
        </section>
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Vessel Information</h2>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                @foreach (['Vessel' => $service->vessel->vessel_name, 'Vessel Type' => $service->vessel->typeLabel(), 'Registry Number' => $service->vessel->registry_number] as $label => $value)
                    <div wire:key="nationality-vessel-{{ $loop->index }}"><dt class="text-xs text-slate-400">{{ $label }}</dt><dd class="mt-1">{{ $value ?? '-' }}</dd></div>
                @endforeach
            </dl>
        </section>
        <x-dashboard.service-current-document :certificate="$service->vessel->documents->firstWhere('document_type', $service->document_type)" :vessel="$service->vessel" :renewal="$service->request_type === 'renewal'" :service-id="$service->id" />
        <x-dashboard.service-vessel-check :checks="$this->readiness['vessel_checks']" :vessel="$service->vessel" service-type="ship-nationality" :service-id="$service->id" />
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Submission Monitoring</h2>
            <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">Pengajuan dan penerbitan dilakukan di luar FLA oleh instansi berwenang. Staff mencatat hasil resmi secara manual.</p>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                @foreach (['Reference' => $service->submission_reference, 'Authority / Office' => $service->authority, 'Submitted At (WITA)' => $service->submitted_at?->timezone(config('ship-nationality.timezone'))->format('d M Y H:i'), 'Resubmitted At (WITA)' => $service->resubmitted_at?->timezone(config('ship-nationality.timezone'))->format('d M Y H:i')] as $label => $value)
                    <div wire:key="submission-{{ $loop->index }}"><dt class="text-xs text-slate-400">{{ $label }}</dt><dd>{{ $value ?? '-' }}</dd></div>
                @endforeach
            </dl>
            @if ($service->revision_notes)<p class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-3 text-sm text-amber-800 dark:text-amber-300">Catatan revisi: {{ $service->revision_notes }}</p>@endif
            @if ($service->rejection_reason)<p class="mt-4 rounded-lg bg-rose-50 dark:bg-rose-950/40 p-3 text-sm text-rose-700 dark:text-rose-300">Alasan penolakan: {{ $service->rejection_reason }}</p>@endif
        </section>

    </div>

    <section class="mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
        <h2 class="text-base font-semibold">Service Documents</h2>
        @if (in_array($service->status, ['draft', 'ready_to_apply', 'revision_required']) && ! $this->readiness['complete'])
            <div class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-4 text-sm text-amber-800 dark:text-amber-300"><p class="font-semibold">Persyaratan belum lengkap</p><ul class="mt-2 list-disc pl-4">@foreach ($this->readiness['missing'] as $missing)<li wire:key="missing-{{ $loop->index }}">{{ $missing }}</li>@endforeach</ul>
                <div class="mt-3 flex flex-wrap gap-2">
                    @can('work', $service)
                        @if ($service->isEditable())
                            <x-dashboard.add-button :href="route('ship-nationality.edit', $service)" variant="soft" compact icon="edit">Lengkapi dokumen layanan</x-dashboard.add-button>
                        @endif
                    @endcan
                    @if (collect($this->readiness['vessel_checks'])->contains(fn ($check) => ($check['required'] ?? true) && ! $check['complete']))
                        @can('manage-vessels')<x-dashboard.add-button :href="route('vessels.edit', ['vessel' => $service->vessel, 'service_type' => 'ship-nationality', 'service_id' => $service->id]).'#vessel-documents'" variant="soft" compact icon="attachment">Lengkapi dokumen kapal</x-dashboard.add-button>@endcan
                    @endif
                </div>
            </div>
        @endif
        @if ($service->previous_document_snapshot)
            <p class="mt-4 text-sm text-slate-600 dark:text-slate-300">Referensi saat pengajuan: {{ $service->previous_document_snapshot['certificate_number'] ?? 'Nomor belum diisi' }} · Tanggal kedaluwarsa: {{ $service->previous_document_snapshot['expired_date'] ?? '-' }}</p>
        @endif
        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Versi dokumen lama dan arsip referensi kapal tetap tersedia.</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            @forelse ($service->documents->sortByDesc('id') as $document)
                <div wire:key="nationality-document-{{ $document->id }}" class="rounded-xl border border-slate-200 dark:border-slate-700 p-4"><p class="text-sm font-medium">{{ $document->label() }}</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400 wrap-break-word">{{ \App\Services\DocumentFilename::forService($document, $service->vessel, $service->document_type) }}</p><p class="mt-2 text-xs text-slate-400">{{ $document->uploaded_at->timezone(config('ship-nationality.timezone'))->format('d M Y H:i') }} · {{ $document->uploader->name }}</p><x-dashboard.download-button :href="route('ship-nationality.documents.download', [$service->id, $document->id])" class="mt-3">Unduh dokumen</x-dashboard.download-button></div>
            @empty<p class="text-sm text-slate-500 dark:text-slate-400">Belum ada dokumen layanan.</p>@endforelse
        </div>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Issued Document</h2>
            @if ($service->issuedDocument)
                <p class="mt-4 text-sm font-semibold">{{ $service->issued_document_number }}</p>
                <p class="mt-2 text-sm">Tanggal terbit: {{ $service->issued_at?->format('d M Y') }} · Tanggal kedaluwarsa: {{ $service->expired_at?->format('d M Y') ?? '-' }}</p>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $service->issuing_authority }}</p>
                <x-dashboard.download-button :href="route('ship-nationality.documents.download', [$service->id, $service->issuedDocument->id])" class="mt-4">Unduh dokumen resmi</x-dashboard.download-button>
            @else<p class="mt-4 text-sm text-slate-500 dark:text-slate-400">Belum ada dokumen resmi yang dicatat.</p>@endif
        </section>
        <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
            <h2 class="text-base font-semibold">Handover</h2>
            @if ($service->handed_over_at)
                <p class="mt-4 text-sm">Penerima: {{ $service->handed_over_to }} · {{ $service->recipient_role }}</p>
                <p class="mt-2 text-sm">Tanggal serah terima (WITA): {{ $service->handed_over_at->timezone(config('ship-nationality.timezone'))->format('d M Y H:i') }}</p>
            @else<p class="mt-4 text-sm text-slate-500 dark:text-slate-400">Serah terima belum dicatat.</p>@endif
        </section>
    </div>

    @can('work', $service)
    <section class="mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5" x-data="{ uploading: 0 }" x-on:livewire-upload-start="uploading++" x-on:livewire-upload-finish="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-error="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-cancel="uploading = Math.max(0, uploading-1)">
        <h2 class="text-base font-semibold">Actions</h2>
        <x-form-errors id="readiness-errors" :show="$errorForm === 'ready'" class="mt-4" />
        @if ($service->isEditable())<a href="{{ route('ship-nationality.edit', $service) }}" class="mt-4 inline-block rounded-lg border border-blue-200 dark:border-blue-900 px-4 py-2.5 text-sm text-blue-600 dark:text-blue-400">Edit / Upload Documents</a>@endif
        @if ($service->status === 'draft')<button type="button" wire:click="ready" wire:loading.attr="disabled" class="mt-4 rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Check Readiness / Ready To Apply</button>@endif

        @if (in_array($service->status, ['ready_to_apply', 'revision_required']))
            <form novalidate wire:submit="apply" class="mt-5 flex flex-col gap-4">
        <x-form-errors id="apply-errors" :show="$errorForm === 'apply'" />
                <h3 class="text-sm font-semibold">{{ $service->status === 'revision_required' ? 'Mark as Resubmitted' : 'Mark as Submitted' }}</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-auth.input name="submitted_at" :label="$service->status === 'revision_required' ? 'Resubmission Date & Time (WITA) *' : 'Submission Date & Time (WITA) *'" type="datetime-local" wire:model="form.submitted_at" required />
                    <x-auth.input name="submission_reference" label="Reference Number (opsional)" wire:model="form.submission_reference" maxlength="255" />
                    <x-auth.input name="authority" label="Authority / Office (opsional)" wire:model="form.authority" maxlength="255" />
                    <x-auth.input name="notes" label="Notes (opsional)" wire:model="form.notes" maxlength="5000" />
                </div>
                <button type="submit" wire:loading.attr="disabled" class="w-fit rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Simpan pengajuan manual</button>
            </form>
        @endif

        @if ($service->status === 'under_review')
            <form novalidate wire:submit="recordReview" class="mt-5 flex flex-col gap-4">
        <x-form-errors id="recordReview-errors" :fields="$errorForm === 'recordReview' ? [] : ['documents', 'documents.*']" />
                <h3 class="text-sm font-semibold">Update Review Result</h3>
                <div><label for="result" class="mb-2 block text-sm font-medium">Hasil Review *</label><select id="result" wire:model.live="form.result" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-3 text-sm"><option value="revision_required">Revision Required</option><option value="rejected">Rejected</option><option value="document_issued">Document Issued</option></select></div>
                @if ($form['result'] === 'revision_required')
                    <x-auth.input name="revision_notes" label="Revision Notes *" wire:model="form.revision_notes" required maxlength="5000" />
                    <x-dashboard.service-uploads :max-kb="config('ship-nationality.document_max_kb')" :types="['revision_attachment' => 'Revision Attachment (opsional)']" />
                @elseif ($form['result'] === 'rejected')
                    <x-auth.input name="rejection_reason" label="Rejection Reason *" wire:model="form.rejection_reason" required maxlength="5000" />
                    <x-dashboard.service-uploads :max-kb="config('ship-nationality.document_max_kb')" :types="['rejection_attachment' => 'Rejection Attachment (opsional)']" />
                @elseif ($form['result'] === 'document_issued')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-auth.input name="issued_document_number" label="Document Number *" wire:model="form.issued_document_number" required maxlength="255" />
                        <x-auth.input name="issued_at" label="Issued Date *" type="date" wire:model="form.issued_at" required />
                        <x-auth.input name="expired_at" label="Expired Date (opsional)" type="date" wire:model="form.expired_at" />
                        <x-auth.input name="issuing_authority" label="Issuing Authority (opsional)" wire:model="form.issuing_authority" maxlength="255" />
                    </div>
                    <x-dashboard.service-uploads :max-kb="config('ship-nationality.document_max_kb')" :types="['issued_document' => 'Issued Document File (wajib)']" />
                @endif
                <x-auth.input name="notes" label="Notes (opsional)" wire:model="form.notes" maxlength="5000" />
                <button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="w-fit rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Simpan hasil review</button>
            </form>
        @endif

        @if ($service->status === 'ready_for_handover')
            <form novalidate wire:submit="handover" class="mt-5 flex flex-col gap-4">
        <x-form-errors id="handover-errors" :fields="$errorForm === 'handover' ? [] : ['documents', 'documents.*']" />
                <h3 class="text-sm font-semibold">Mark as Handed Over</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-auth.input name="handed_over_to" label="Recipient Name *" wire:model="form.handed_over_to" required maxlength="255" />
                    <x-auth.input name="recipient_role" label="Recipient Role (opsional)" wire:model="form.recipient_role" maxlength="255" />
                    <x-auth.input name="handed_over_at" label="Handover Date & Time (WITA) *" type="datetime-local" wire:model="form.handed_over_at" required />
                    <x-auth.input name="notes" label="Notes (opsional)" wire:model="form.notes" maxlength="5000" />
                </div>
                <x-dashboard.service-uploads :max-kb="config('ship-nationality.document_max_kb')" :types="['handover_receipt' => 'Receipt / Proof (opsional)']" />
                <button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="w-fit rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Catat serah terima & selesaikan</button>
            </form>
        @endif

        @if (in_array($service->status, ['draft', 'ready_to_apply', 'under_review', 'revision_required']))
            <details class="mt-6"><summary class="cursor-pointer text-sm font-medium text-rose-700 dark:text-rose-300">Cancel Service</summary>
                <form novalidate wire:submit="cancel" class="mt-4 flex flex-col gap-4">
        <x-form-errors id="cancel-errors" :show="$errorForm === 'cancel'" />
                    <x-auth.input name="cancellation_reason" label="Cancellation Reason *" wire:model="form.cancellation_reason" required maxlength="5000" />
                    <button type="submit" wire:loading.attr="disabled" class="w-fit rounded-lg border border-rose-200 dark:border-rose-900 px-4 py-2.5 text-sm text-rose-700 dark:text-rose-300">Batalkan pengurusan</button>
                </form>
            </details>
        @endif
        @if ($service->cancellation_reason)<p class="mt-4 text-sm text-rose-700 dark:text-rose-300">Alasan pembatalan: {{ $service->cancellation_reason }}</p>@endif
        @if (in_array($service->status, \App\Models\ShipNationalityService::TERMINAL))
            <p class="mt-4 text-sm text-slate-600 dark:text-slate-300">Layanan diarsipkan pada {{ $service->archived_at?->timezone(config('ship-nationality.timezone'))->format('d M Y H:i') }} WITA. Dokumen dan riwayat tetap tersedia.</p>
        @endif
        <p wire:loading class="mt-3 text-xs text-blue-600 dark:text-blue-400">Memproses...</p><p x-cloak x-show="uploading > 0" class="mt-3 text-xs text-blue-600 dark:text-blue-400">Tunggu upload selesai...</p>
        <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">File PDF/JPG/JPEG/PNG/WebP/GIF/DOC/DOCX, maksimal {{ config('ship-nationality.document_max_kb') / 1024 }} MB.</p>
    </section>
    @endcan

    <x-dashboard.service-timeline :histories="$service->histories" :statuses="\App\Models\ShipNationalityService::STATUSES" :timezone="config('ship-nationality.timezone')" prefix="nationality" :fields="['submission_reference' => 'Reference', 'submitted_at' => 'Submission (WITA)', 'issued_document_number' => 'Certificate Number', 'handed_over_to' => 'Recipient', 'handed_over_at' => 'Handover (WITA)']" />
</x-dashboard.shell>

