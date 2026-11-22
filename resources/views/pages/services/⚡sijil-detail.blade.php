<?php

use App\Models\SijilService;
use App\Services\ServiceDeletion;
use App\Services\SijilReadinessService;
use App\Services\SijilWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Detail Sijil Awak Kapal')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?string $errorForm = null;

    #[Locked]
    public int $serviceId;

    /** @var array<string, mixed> */
    public array $form = ['submitted_at' => '', 'submission_reference' => '', 'authority' => '', 'notes' => '', 'result' => 'revision_required', 'revision_notes' => '', 'rejection_reason' => '', 'issued_at' => '', 'result_reference' => '', 'effective_at' => '', 'cancellation_reason' => ''];

    /** @var array<string, mixed> */
    public array $documents = [];

    public function deleteService(ServiceDeletion $deletion): void
    {
        $service = SijilService::findOrFail($this->serviceId);
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
        session()->flash('sijil_status', 'Service beserta dokumen terkait berhasil dihapus. Dokumen Vessel tetap disimpan.');
        $this->redirect(route('sijil.index'), navigate: true);
    }

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function mount(SijilService $service): void
    {
        $this->serviceId = $service->id;
        $this->prefill();
    }

    public function updatedFormResult(): void
    {
        $this->documents = [];
        $this->resetValidation();
    }

    public function ready(SijilWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->ready($this->service), __FUNCTION__);
    }

    public function submit(SijilWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->submit($this->service, $this->form), __FUNCTION__);
    }

    public function recordReview(SijilWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->recordReview($this->service, $this->form, $this->documents), __FUNCTION__);
    }

    public function applyCrewChanges(SijilWorkflow $workflow): void
    {
        $this->perform(fn () => $workflow->applyCrewChanges($this->service, $this->form), __FUNCTION__);
    }

    public function cancel(SijilWorkflow $workflow): void
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
        unset($this->service, $this->readiness, $this->crewReferences);
        $this->prefill();
        session()->flash('sijil_status', 'Proses layanan berhasil diperbarui.');
    }

    private function prefill(): void
    {
        $this->form['effective_at'] = $this->service->issued_at?->toDateString() ?? '';
    }

    #[Computed]
    public function service(): SijilService
    {
        return SijilService::with(['vessel.documents', 'assignedStaff', 'creator', 'documents.uploader', 'histories.actor', 'crews.documents.sijilService.vessel', 'crews.crew.documents.sijilService.vessel', 'allCrews', 'completedBy'])->findOrFail($this->serviceId);
    }

    #[Computed]
    public function crewReferences(): array
    {
        $references = [];
        foreach ($this->service->crews as $row) {
            foreach (array_keys(\App\Models\SijilDocument::CREW_TYPES) as $type) {
                $document = app(SijilReadinessService::class)->crewDocument($row, $type);
                if ($document) {
                    $references[$row->id][$type] = $document;
                }
            }
        }

        return $references;
    }

    #[Computed]
    public function readiness(): array
    {
        return app(SijilReadinessService::class)->check($this->service);
    }
};
?>

<x-dashboard.shell page="Sijil Awak Kapal" :demo="false">
    @php
        $service = $this->service;
    @endphp
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-xs text-slate-500 dark:text-slate-400">Sijil Awak Kapal</p><h1 class="mt-2 text-2xl font-semibold">{{ $service->service_number }}</h1><div class="mt-3"><x-dashboard.sijil-status :status="$service->status" /></div></div>
        <div class="flex flex-wrap items-center gap-3">
            <x-dashboard.add-button :href="route('sijil.index')" variant="soft" icon="arrow" class="whitespace-nowrap [&_svg]:size-3.5 [&_svg]:rotate-180">Kembali ke monitoring</x-dashboard.add-button>
            <x-dashboard.service-archive-download :service="$service" />
        </div>
    </div>
    <x-dashboard.service-delete :service="$service" />
    @if (session('sijil_status'))<p data-form-success role="status" class="mt-5 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 p-4 text-sm text-emerald-700 dark:text-emerald-300">{{ session('sijil_status') }}</p>@endif
    @if ($service->status === 'revision_required')<p class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-4 text-sm text-amber-800 dark:text-amber-300">Perlu revisi: {{ $service->revision_notes }}</p>@endif
    @if ($service->status === 'rejected')<p class="mt-4 rounded-lg bg-rose-50 dark:bg-rose-950/40 p-4 text-sm text-rose-700 dark:text-rose-300">Ditolak: {{ $service->rejection_reason }}</p>@endif
    @if ($service->status === 'cancelled')<p class="mt-4 rounded-lg bg-slate-100 dark:bg-slate-800 p-4 text-sm text-slate-700 dark:text-slate-300">Dibatalkan: {{ $service->cancellation_reason }}</p>@endif

    <div class="mt-6 grid items-start gap-6 xl:grid-cols-2">
        @php
            $timezone = config('sijil.timezone');
            $sections = [
                'Service Information' => ['Service Number' => $service->service_number, 'Process Type' => \App\Models\SijilService::PROCESS_TYPES[$service->process_type], 'Status' => $service->statusLabel(), 'PIC' => $service->assignedStaff->name, 'Created By' => $service->creator->name, 'Notes' => $service->notes],
                'Vessel' => ['Vessel' => $service->vessel->vessel_name, 'Vessel Type' => $service->vessel->typeLabel(), 'Registry Number' => $service->vessel->registry_number],
                'Submission Monitoring' => ['Reference Number' => $service->submission_reference, 'Authority / Office' => $service->authority, 'Submitted (WITA)' => $service->submitted_at?->timezone($timezone)->format('d M Y H:i'), 'Resubmitted (WITA)' => $service->resubmitted_at?->timezone($timezone)->format('d M Y H:i')],
                'Sijil Result' => ['Result Reference' => $service->result_reference, 'Issued / Recorded Date' => $service->issued_at?->format('d M Y')],
                'Crew Update Result' => ['Effective Date' => $service->effective_at?->format('d M Y'), 'Completed By' => $service->completedBy?->name, 'Completed (WITA)' => $service->completed_at?->timezone($timezone)->format('d M Y H:i'), 'Archived (WITA)' => $service->archived_at?->timezone($timezone)->format('d M Y H:i')],
            ];
        @endphp
        @foreach ($sections as $title => $fields)
            <section wire:key="sijil-section-{{ $loop->index }}" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
                <h2 class="text-base font-semibold">{{ $title }}</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">@foreach ($fields as $label => $value)<div wire:key="sijil-field-{{ $loop->parent->index }}-{{ $loop->index }}"><dt class="text-xs text-slate-400">{{ $label }}</dt><dd class="mt-1 wrap-break-word">{{ $value ?? '-' }}</dd></div>@endforeach</dl>
                @if ($title === 'Vessel')<a href="{{ route('dashboard.vessel', ['vessel' => $service->vessel_id]) }}" class="mt-4 inline-block text-xs text-blue-600 dark:text-blue-400">Lihat Vessel</a>@endif
            </section>
        @endforeach
        <x-dashboard.service-vessel-check :checks="$this->readiness['vessel_checks']" :vessel="$service->vessel" service-type="sijil" :service-id="$service->id" />
    </div>
    <section class="mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
        <h2 class="text-base font-semibold">Crew Changes &amp; Crew Document Check</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            @forelse ($service->crews as $row)
                <div wire:key="sijil-crew-{{ $row->id }}" class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                    <p class="text-sm font-semibold">{{ $row->full_name ?? 'Nama belum diisi' }} &middot; {{ $row->rank ?? 'Jabatan belum diisi' }}</p>
                    <p class="mt-2 text-xs text-blue-600 dark:text-blue-400">{{ \App\Models\SijilService::ACTIONS[$row->action_type] }}</p>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Buku Pelaut: {{ $row->seafarer_book_number ?? '-' }} &middot; Kompetensi: {{ $row->competency_certificate_number ?? '-' }}</p>
                    @if ($row->notes)<p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $row->notes }}</p>@endif
                    @if ($service->isEditable())@foreach ($this->readiness['crew_checks'][$row->id] ?? [] as $issue)<p wire:key="crew-issue-{{ $row->id }}-{{ $loop->index }}" class="mt-2 text-xs text-amber-700 dark:text-amber-300">{{ $issue }}</p>@endforeach @endif
                    @foreach ($this->crewReferences[$row->id] ?? [] as $type => $document)
                        <x-dashboard.download-button wire:key="crew-reference-{{ $row->id }}-{{ $type }}" :href="route('sijil.documents.download', [$document->sijil_service_id, $document->id])" class="mt-2">{{ $document->label() }}: {{ \App\Services\DocumentFilename::forService($document, $document->sijilService->vessel) }}</x-dashboard.download-button>
                    @endforeach
                    @if ($row->after_snapshot)<p class="mt-3 text-xs font-semibold text-emerald-700 dark:text-emerald-300">Perubahan diterapkan &middot; {{ $row->after_snapshot['rank'] }} &middot; {{ $row->after_snapshot['sign_off_at'] ? 'Awak sebelumnya' : 'Awak aktif' }}</p>@endif
                    @if ($row->before_snapshot)<p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Sebelumnya: {{ $row->before_snapshot['full_name'] }} &middot; {{ $row->before_snapshot['rank'] }}</p>@endif
                </div>
            @empty<p class="text-sm text-slate-500 dark:text-slate-400">Belum ada awak kapal pada pengajuan.</p>@endforelse
        </div>
    </section>
    <section class="mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5">
        <h2 class="text-base font-semibold">Persyaratan &amp; Arsip Dokumen</h2>
        @if (in_array($service->status, ['draft', 'ready_to_apply', 'revision_required']) && ! $this->readiness['complete'])
            <div class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 p-4 text-sm text-amber-800 dark:text-amber-300"><p class="font-semibold">Persyaratan belum lengkap</p><ul class="mt-2 list-disc pl-4">@foreach ($this->readiness['missing'] as $missing)<li wire:key="sijil-missing-{{ $loop->index }}">{{ $missing }}</li>@endforeach</ul>
                <div class="mt-3 flex flex-wrap gap-2">
                    @can('work', $service)
                        @if ($service->isEditable())
                            <x-dashboard.add-button :href="route('sijil.edit', $service)" variant="soft" compact icon="edit">Lengkapi data dan dokumen awak</x-dashboard.add-button>
                        @endif
                    @endcan
                    @if (collect($this->readiness['vessel_checks'])->contains(fn ($check) => ($check['required'] ?? true) && ! $check['complete']))
                        @can('manage-vessels')<x-dashboard.add-button :href="route('vessels.edit', ['vessel' => $service->vessel, 'service_type' => 'sijil', 'service_id' => $service->id]).'#vessel-documents'" variant="soft" compact icon="attachment">Lengkapi dokumen kapal</x-dashboard.add-button>@endcan
                    @endif
                </div>
            </div>
        @endif
        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Pengajuan dilakukan di luar sistem FLA. Dokumen persetujuan resmi dan seluruh versi lampiran tetap diarsipkan.</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            @forelse ($service->documents->sortByDesc('id') as $document)
                <div wire:key="sijil-document-{{ $document->id }}" class="rounded-xl border border-slate-200 dark:border-slate-700 p-4"><p class="text-sm font-medium">{{ $document->label() }}</p>@if ($document->sijil_service_crew_id)<p class="mt-1 text-xs font-semibold">Crew: {{ $service->allCrews->firstWhere('id', $document->sijil_service_crew_id)?->full_name }}</p>@endif<p class="mt-1 text-xs text-slate-500 dark:text-slate-400 wrap-break-word">{{ \App\Services\DocumentFilename::forService($document, $service->vessel) }}</p><p class="mt-2 text-xs text-slate-400">{{ $document->uploaded_at->timezone($timezone)->format('d M Y H:i') }} · {{ $document->uploader->name }}</p><x-dashboard.download-button :href="route('sijil.documents.download', [$service->id, $document->id])" class="mt-3">Unduh dokumen</x-dashboard.download-button></div>
            @empty<p class="text-sm text-slate-500 dark:text-slate-400">Belum ada dokumen layanan.</p>@endforelse
        </div>
    </section>
    @can('work', $service)
    <section class="mt-6 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5" x-data="{ uploading: 0 }" x-on:livewire-upload-start="uploading++" x-on:livewire-upload-finish="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-error="uploading = Math.max(0, uploading-1)" x-on:livewire-upload-cancel="uploading = Math.max(0, uploading-1)">
        <h2 class="text-base font-semibold">Actions</h2>
        <x-form-errors id="readiness-errors" :show="$errorForm === 'ready'" class="mt-4" />
        @if ($service->isEditable())<a href="{{ route('sijil.edit', $service) }}" class="mt-4 inline-block rounded-lg border border-blue-200 dark:border-blue-900 px-4 py-2.5 text-sm text-blue-600 dark:text-blue-400">Edit / Upload Documents</a>@endif
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
                    <x-dashboard.service-uploads :max-kb="config('sijil.document_max_kb')" :types="['revision_attachment' => 'Revision Attachment (opsional)']" prefix="sijil" />
                @elseif ($form['result'] === 'rejected')
                    <x-auth.input name="rejection_reason" label="Rejection Reason *" wire:model="form.rejection_reason" required maxlength="5000" />
                    <x-dashboard.service-uploads :max-kb="config('sijil.document_max_kb')" :types="['rejection_attachment' => 'Rejection Attachment (opsional)']" prefix="sijil" />
                @elseif ($form['result'] === 'approved')
                    <x-auth.input name="result_reference" label="Approval / Sijil Reference (opsional)" wire:model="form.result_reference" maxlength="255" />
                    <x-auth.input name="issued_at" label="Issued / Recorded Date *" type="date" wire:model="form.issued_at" required />
                    <x-dashboard.service-uploads :max-kb="config('sijil.document_max_kb')" :types="['official_result' => config('sijil.official_result_required') ? 'Official Result Document *' : 'Official Result Document (opsional)']" prefix="sijil" />
                @endif
                <x-auth.input name="notes" label="Notes (opsional)" wire:model="form.notes" maxlength="5000" />
                <button type="submit" wire:loading.attr="disabled" x-bind:disabled="uploading > 0" class="w-fit rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Simpan hasil review</button>
            </form>
        @endif
        @if ($service->status === 'ready_to_update_crew')
            <form novalidate wire:submit="applyCrewChanges" class="mt-5 flex flex-col gap-4">
        <x-form-errors id="applyCrewChanges-errors" :show="$errorForm === 'applyCrewChanges'" />
                <h3 class="text-sm font-semibold">Apply Crew Changes</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Perubahan berlaku untuk seluruh awak kapal pada layanan ini. Riwayat penempatan lama tetap disimpan.</p>
                <x-auth.input name="effective_at" label="Effective Date *" type="date" wire:model="form.effective_at" required />
                <x-auth.input name="apply_notes" label="Notes (opsional)" wire:model="form.notes" maxlength="5000" />
                <button type="submit" wire:confirm="Terapkan perubahan awak kapal ke data kapal dan selesaikan layanan?" wire:loading.attr="disabled" class="w-fit rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Apply Crew Changes / Complete</button>
            </form>
        @endif
        @if (! in_array($service->status, \App\Models\SijilService::TERMINAL))
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
    <x-dashboard.service-timeline :histories="$service->histories" :statuses="\App\Models\SijilService::STATUSES" :timezone="config('sijil.timezone')" prefix="sijil" :fields="['submitted_at' => 'Submission (WITA)', 'submission_reference' => 'Reference', 'issued_at' => 'Issued Date', 'result_reference' => 'Result Reference', 'effective_at' => 'Effective Date']" />
</x-dashboard.shell>
