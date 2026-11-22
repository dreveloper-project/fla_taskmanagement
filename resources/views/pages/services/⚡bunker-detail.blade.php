<?php

use App\Models\BunkerService;
use App\Services\BunkerReadinessService;
use App\Services\BunkerWorkflow;
use App\Services\ServiceDeletion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Detail Izin Bunker')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?string $errorForm = null;

    #[Locked]
    public int $serviceId;

    /** @var array<string, mixed> */
    public array $form = ['submitted_at' => '', 'submission_reference' => '', 'authority' => '', 'notes' => '', 'result' => 'revision_required', 'revision_notes' => '', 'rejection_reason' => '', 'approved_at' => '', 'approval_number' => '', 'supplier' => '', 'bunker_location' => '', 'scheduled_at' => '', 'quantity' => '', 'unit' => '', 'unit_other' => '', 'actual_bunker_at' => '', 'actual_quantity' => '', 'actual_unit' => '', 'actual_unit_other' => '', 'activity_notes' => '', 'cancellation_reason' => ''];

    /** @var array<string, mixed> */
    public array $documents = [];

    public function deleteService(ServiceDeletion $deletion): void
    {
        $service = BunkerService::findOrFail($this->serviceId);
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
        session()->flash('bunker_status', 'Service beserta dokumen terkait berhasil dihapus. Dokumen Vessel tetap disimpan.');
        $this->redirect(route('bunker.index'), navigate: true);
    }

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function mount(BunkerService $service): void
    {
        $this->serviceId = $service->id;
        $this->prefill();
    }

    public function updatedFormResult(): void
    {
        $this->documents = [];
        $this->resetValidation();
    }

    public function ready(BunkerWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->ready($this->service), __FUNCTION__);
    }

    public function submit(BunkerWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->submit($this->service, $this->form), __FUNCTION__);
    }

    public function recordReview(BunkerWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->recordReview($this->service, $this->form, $this->documents), __FUNCTION__);
    }

    public function schedule(BunkerWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->schedule($this->service, $this->form), __FUNCTION__);
    }

    public function complete(BunkerWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->complete($this->service, $this->form, $this->documents), __FUNCTION__);
    }

    public function cancel(BunkerWorkflow $workflow): void
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
        $this->prefill();
        session()->flash('bunker_status', 'Proses layanan berhasil diperbarui.');
    }

    private function prefill(): void
    {
        foreach (['supplier', 'bunker_location', 'quantity', 'unit', 'unit_other'] as $field) {
            $this->form[$field] = $this->service->$field ?? '';
        }
        $this->form['scheduled_at'] = $this->service->scheduled_at?->timezone(config('bunker.timezone'))->format('Y-m-d\TH:i') ?? '';
        $this->form['actual_unit'] = $this->service->unit ?? '';
        $this->form['actual_unit_other'] = $this->service->unit_other ?? '';
    }

    #[Computed]
    public function service(): BunkerService
    {
        return BunkerService::with(['vessel.documents', 'assignedStaff', 'creator', 'documents.uploader', 'histories.actor'])->findOrFail($this->serviceId);
    }

    #[Computed]
    public function readiness(): array
    {
        return app(BunkerReadinessService::class)->check($this->service);
    }
};
?>

<x-dashboard.shell page="Izin Bunker" :demo="false">
    @php
        $service = $this->service;
    @endphp
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-xs text-slate-500 dark:text-slate-400">Izin Bunker</p><h1 class="mt-2 text-2xl font-semibold">{{ $service->service_number }}</h1><div class="mt-3"><x-dashboard.bunker-status :status="$service->status" /></div></div>
        <div class="flex flex-wrap items-center gap-3">
            <x-dashboard.add-button :href="route('bunker.index')" variant="soft" icon="arrow" class="whitespace-nowrap [&_svg]:size-3.5 [&_svg]:rotate-180">Kembali ke monitoring</x-dashboard.add-button>
            <x-dashboard.service-archive-download :service="$service" />
        </div>
    </div>
    <x-dashboard.service-delete :service="$service" />
    @if (session('bunker_status'))<p data-form-success role="status" class="mt-5 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 p-4 text-sm text-emerald-700 dark:text-emerald-300">{{ session('bunker_status') }}</p>@endif
    @if (in_array($service->status, \App\Models\BunkerService::ACTIVE) && $service->scheduled_at?->isPast())<p class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-4 text-sm text-amber-800 dark:text-amber-300">Schedule overdue. Jadwal bunker telah lewat dan kegiatan belum tercatat selesai.</p>@endif
    @if ($service->status === 'revision_required')<p class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-4 text-sm text-amber-800 dark:text-amber-300">Perlu revisi: {{ $service->revision_notes }}</p>@endif
    @if ($service->status === 'rejected')<p class="mt-4 rounded-lg bg-rose-50 dark:bg-rose-950/40 p-4 text-sm text-rose-700 dark:text-rose-300">Ditolak: {{ $service->rejection_reason }}</p>@endif
    @if ($service->status === 'cancelled')<p class="mt-4 rounded-lg bg-slate-100 dark:bg-slate-800 p-4 text-sm text-slate-700 dark:text-slate-300">Dibatalkan: {{ $service->cancellation_reason }}</p>@endif

    <div class="mt-6 grid items-start gap-6 xl:grid-cols-2">
        @php
            $timezone = config('bunker.timezone');
            $sections = [
                'Service Information' => ['Service Number' => $service->service_number, 'Status' => $service->statusLabel(), 'PIC' => $service->assignedStaff->name, 'Created By' => $service->creator->name, 'Created At (WITA)' => $service->created_at->timezone($timezone)->format('d M Y H:i'), 'Notes' => $service->notes],
                'Vessel' => ['Vessel' => $service->vessel->vessel_name, 'Vessel Type' => $service->vessel->typeLabel(), 'Registry Number' => $service->vessel->registry_number],
                'Bunker Information' => ['Pemasok' => $service->supplier, 'Bunker Location' => $service->bunker_location, 'Fuel / Bunker Type' => $service->bunker_type, 'Jumlah' => ($service->quantity ?? '-').' '.$service->unitLabel(), 'Scheduled Date (WITA)' => $service->scheduled_at?->timezone($timezone)->format('d M Y H:i')],
                'Submission / Approval' => ['Reference Number' => $service->submission_reference, 'Authority / Office' => $service->authority, 'Submitted (WITA)' => $service->submitted_at?->timezone($timezone)->format('d M Y H:i'), 'Resubmitted (WITA)' => $service->resubmitted_at?->timezone($timezone)->format('d M Y H:i'), 'Approval Number' => $service->approval_number, 'Approved (WITA)' => $service->approved_at?->timezone($timezone)->format('d M Y H:i')],
                'Bunker Activity' => ['Actual Bunker Date (WITA)' => $service->actual_bunker_at?->timezone($timezone)->format('d M Y H:i'), 'Actual Quantity' => ($service->actual_quantity ?? '-').' '.$service->unitLabel(true), 'Activity Notes' => $service->activity_notes, 'Completed (WITA)' => $service->completed_at?->timezone($timezone)->format('d M Y H:i'), 'Archived (WITA)' => $service->archived_at?->timezone($timezone)->format('d M Y H:i')],
            ];
        @endphp
        @foreach ($sections as $title => $fields)
            <section wire:key="bunker-section-{{ $loop->index }}" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
                <h2 class="text-base font-semibold">{{ $title }}</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">@foreach ($fields as $label => $value)<div wire:key="bunker-field-{{ $loop->parent->index }}-{{ $loop->index }}"><dt class="text-xs text-slate-400">{{ $label }}</dt><dd class="mt-1 wrap-break-word">{{ $value ?? '-' }}</dd></div>@endforeach</dl>
                @if ($title === 'Vessel')<a href="{{ route('dashboard.vessel', ['vessel' => $service->vessel_id]) }}" class="mt-4 inline-block text-xs text-blue-600 dark:text-blue-400">Lihat Vessel</a>@endif
            </section>
        @endforeach
        <x-dashboard.service-vessel-check :checks="$this->readiness['vessel_checks']" :vessel="$service->vessel" service-type="bunker" :service-id="$service->id" />
    </div>
    <section class="mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
        <h2 class="text-base font-semibold">Service Documents</h2>
        @if (in_array($service->status, ['draft', 'ready_to_apply', 'revision_required']) && ! $this->readiness['complete'])
            <div class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-4 text-sm text-amber-800 dark:text-amber-300"><p class="font-semibold">Persyaratan belum lengkap</p><ul class="mt-2 list-disc pl-4">@foreach ($this->readiness['missing'] as $missing)<li wire:key="bunker-missing-{{ $loop->index }}">{{ $missing }}</li>@endforeach</ul>
                <div class="mt-3 flex flex-wrap gap-2">
                    @can('work', $service)
                        @if ($service->isEditable())
                            <x-dashboard.add-button :href="route('bunker.edit', $service)" variant="soft" compact icon="edit">Lengkapi dokumen layanan</x-dashboard.add-button>
                        @endif
                    @endcan
                    @if (collect($this->readiness['vessel_checks'])->contains(fn ($check) => ($check['required'] ?? true) && ! $check['complete']))
                        @can('manage-vessels')<x-dashboard.add-button :href="route('vessels.edit', ['vessel' => $service->vessel, 'service_type' => 'bunker', 'service_id' => $service->id]).'#vessel-documents'" variant="soft" compact icon="attachment">Lengkapi dokumen kapal</x-dashboard.add-button>@endcan
                    @endif
                </div>
            </div>
        @endif
        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Pengajuan dilakukan di luar sistem FLA. Dokumen persetujuan resmi dan seluruh versi lampiran tetap diarsipkan.</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            @forelse ($service->documents->sortByDesc('id') as $document)
                <div wire:key="bunker-document-{{ $document->id }}" class="rounded-xl border border-slate-200 dark:border-slate-700 p-4"><p class="text-sm font-medium">{{ $document->label() }}</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400 wrap-break-word">{{ \App\Services\DocumentFilename::forService($document, $service->vessel) }}</p><p class="mt-2 text-xs text-slate-400">{{ $document->uploaded_at->timezone($timezone)->format('d M Y H:i') }} · {{ $document->uploader->name }}</p><x-dashboard.download-button :href="route('bunker.documents.download', [$service->id, $document->id])" class="mt-3">Unduh dokumen</x-dashboard.download-button></div>
            @empty<p class="text-sm text-slate-500 dark:text-slate-400">Belum ada dokumen layanan.</p>@endforelse
        </div>
    </section>
    @can('work', $service)
    <section class="mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5" x-data="{ uploading: 0 }" x-on:livewire-upload-start="uploading++" x-on:livewire-upload-finish="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-error="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-cancel="uploading = Math.max(0, uploading-1)">
        <h2 class="text-base font-semibold">Actions</h2>
        <x-form-errors id="readiness-errors" :show="$errorForm === 'ready'" class="mt-4" />
        @if ($service->isEditable())<a href="{{ route('bunker.edit', $service) }}" class="mt-4 inline-block rounded-lg border border-blue-200 dark:border-blue-900 px-4 py-2.5 text-sm text-blue-600 dark:text-blue-400">Edit / Upload Documents</a>@endif
        @if ($service->status === 'draft')<button type="button" wire:click="ready" wire:loading.attr="disabled" class="mt-4 rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Check Readiness / Ready To Apply</button>@endif
        @if (in_array($service->status, ['ready_to_apply', 'revision_required']))
            <form novalidate wire:submit="submit" class="mt-5 flex flex-col gap-4">
        <x-form-errors id="submit-errors" :show="$errorForm === 'submit'" />
                <h3 class="text-sm font-semibold">{{ $service->status === 'revision_required' ? 'Mark as Resubmitted' : 'Mark as Submitted' }}</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-auth.input name="submitted_at" label="Submission / Resubmission Date & Time (WITA) *" type="datetime-local" wire:model="form.submitted_at" required />
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
                <h3 class="text-sm font-semibold">Update Result</h3>
                <div><label for="result" class="mb-2 block text-sm font-medium">Hasil Review *</label><select id="result" wire:model.live="form.result" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-3 text-sm"><option value="revision_required">Revision Required</option><option value="rejected">Rejected</option><option value="approved">Approved</option></select></div>
                @if ($form['result'] === 'revision_required')
                    <x-auth.input name="revision_notes" label="Revision Notes *" wire:model="form.revision_notes" required maxlength="5000" />
                    <x-dashboard.service-uploads :max-kb="config('bunker.document_max_kb')" :types="['revision_attachment' => 'Revision Attachment (opsional)']" prefix="bunker" />
                @elseif ($form['result'] === 'rejected')
                    <x-auth.input name="rejection_reason" label="Rejection Reason *" wire:model="form.rejection_reason" required maxlength="5000" />
                    <x-dashboard.service-uploads :max-kb="config('bunker.document_max_kb')" :types="['rejection_attachment' => 'Rejection Attachment (opsional)']" prefix="bunker" />
                @elseif ($form['result'] === 'approved')
                    <x-auth.input name="approval_number" label="Approval Number (opsional)" wire:model="form.approval_number" maxlength="255" />
                    <x-auth.input name="approved_at" label="Approval Date & Time (WITA) *" type="datetime-local" wire:model="form.approved_at" required />
                    <x-dashboard.service-uploads :max-kb="config('bunker.document_max_kb')" :types="['approval_document' => 'Approval / Permit Document *']" prefix="bunker" />
                @endif
                <x-auth.input name="notes" label="Notes (opsional)" wire:model="form.notes" maxlength="5000" />
                <button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="w-fit rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Simpan hasil review</button>
            </form>
        @endif
        @if (in_array($service->status, ['approved', 'bunker_scheduled']))
            <form novalidate wire:submit="schedule" class="mt-5 flex flex-col gap-4">
        <x-form-errors id="schedule-errors" :show="$errorForm === 'schedule'" />
                <h3 class="text-sm font-semibold">{{ $service->status === 'approved' ? 'Confirm Bunker Schedule' : 'Update Bunker Schedule' }}</h3>
                <x-dashboard.bunker-fields :required="true" />
                <x-auth.input name="schedule_notes" label="Notes (opsional)" wire:model="form.notes" maxlength="5000" />
                <button type="submit" wire:loading.attr="disabled" class="w-fit rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Simpan jadwal bunker</button>
            </form>
        @endif
        @if ($service->status === 'bunker_scheduled')
            <form novalidate wire:submit="complete" class="mt-6 flex flex-col gap-4 border-t border-slate-100 dark:border-slate-700 pt-5">
        <x-form-errors id="complete-errors" :fields="$errorForm === 'complete' ? [] : ['documents', 'documents.*']" />
                <h3 class="text-sm font-semibold">Mark Bunker Completed</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-auth.input name="actual_bunker_at" label="Actual Bunker Date & Time (WITA) *" type="datetime-local" wire:model="form.actual_bunker_at" required />
                    <x-auth.input name="actual_quantity" label="Actual Quantity *" type="number" min="0.001" step="0.001" max="999999999999.999" wire:model="form.actual_quantity" required />
                    <div><label for="actual_unit" class="mb-2 block text-sm font-medium">Actual Unit *</label><select id="actual_unit" wire:model.live="form.actual_unit" required class="w-full rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-3 text-sm"><option value="">Pilih satuan</option>@foreach (\App\Models\BunkerService::UNITS as $key => $label)<option wire:key="actual-unit-{{ $key }}" value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                    <x-auth.input name="actual_unit_other" label="Nama satuan jika Other" wire:model="form.actual_unit_other" maxlength="50" />
                    <x-auth.input name="activity_notes" label="Activity Notes (opsional)" wire:model="form.activity_notes" maxlength="5000" />
                </div>
                <x-dashboard.service-uploads :max-kb="config('bunker.document_max_kb')" :types="['activity_proof' => 'Activity Proof (opsional)', 'payment_proof' => 'Cost / Payment Proof (opsional)']" prefix="bunker" />
                <button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="w-fit rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Konfirmasi bunker selesai</button>
            </form>
        @endif
        @if (! in_array($service->status, \App\Models\BunkerService::TERMINAL))
            <details class="mt-6 border-t border-slate-100 dark:border-slate-700 pt-4"><summary class="cursor-pointer text-sm text-rose-600 dark:text-rose-400">Batalkan service</summary>
                <form novalidate wire:submit="cancel" class="mt-4 flex flex-col gap-4">
        <x-form-errors id="cancel-errors" :show="$errorForm === 'cancel'" />
                    <x-auth.input name="cancellation_reason" label="Alasan pembatalan *" wire:model="form.cancellation_reason" required maxlength="5000" />
                    <button type="submit" wire:confirm="Batalkan layanan ini? Riwayat dan dokumen tetap disimpan." wire:loading.attr="disabled" class="w-fit rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Konfirmasi pembatalan</button>
                </form>
            </details>
        @else<p class="mt-4 text-sm text-slate-500 dark:text-slate-400">Layanan telah diarsipkan. Dokumen dan riwayat tetap dapat dilihat.</p>@endif
        <p wire:loading class="mt-3 text-xs text-blue-600 dark:text-blue-400">Memproses...</p>
    </section>
    @endcan
    <x-dashboard.service-timeline :histories="$service->histories" :statuses="\App\Models\BunkerService::STATUSES" :timezone="config('bunker.timezone')" prefix="bunker" :fields="['submission_reference' => 'Reference', 'submitted_at' => 'Submission (WITA)', 'approved_at' => 'Approval (WITA)', 'approval_number' => 'Approval Number', 'after.scheduled_at' => 'Schedule (WITA)', 'after.supplier' => 'Pemasok', 'after.bunker_location' => 'Location', 'after.quantity' => 'Jumlah', 'after.unit' => 'Satuan', 'actual_bunker_at' => 'Actual Bunker (WITA)', 'actual_quantity' => 'Actual Quantity', 'actual_unit' => 'Actual Unit']" />
</x-dashboard.shell>
