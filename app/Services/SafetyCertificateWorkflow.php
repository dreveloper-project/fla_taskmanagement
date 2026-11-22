<?php

namespace App\Services;

use App\Models\SafetyCertificateDocument;
use App\Models\SafetyCertificateService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Rules\UploadSize;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SafetyCertificateWorkflow
{
    public function __construct(private SafetyCertificateReadinessService $readiness) {}

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function saveDraft(?SafetyCertificateService $existing, array $input, array $documents = []): SafetyCertificateService
    {
        $actor = $this->actor();
        $input = app(ServiceAssignment::class)->prepare($existing, $input);
        $data = Validator::make($this->normalize($input), [
            'vessel_id' => ['required', 'integer', Rule::exists('vessels', 'id')->where('vessel_type', 'passenger')],
            'request_type' => ['required', Rule::in(array_keys(SafetyCertificateService::REQUEST_TYPES))],
            'assigned_staff_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'notes' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ], $this->messages())->validate();
        $uploads = $this->validateUploads($documents, array_keys(SafetyCertificateDocument::APPLICATION_TYPES + ['revision_attachment' => 'Revision']));

        return $this->atomic(function (Collection $paths) use ($existing, $actor, $data, $uploads): SafetyCertificateService {
            $vessel = Vessel::query()->whereKey($data['vessel_id'])->lockForUpdate()->firstOrFail();
            if ($vessel->vessel_type !== 'passenger') {
                throw ValidationException::withMessages(['vessel_id' => 'Pilih kapal bertipe penumpang.']);
            }
            $service = $existing ? SafetyCertificateService::query()->lockForUpdate()->findOrFail($existing->id) : new SafetyCertificateService;
            $data = app(ServiceAssignment::class)->prepare($service, $data);
            $isNew = ! $service->exists;
            if (! $isNew) {
                $this->ensureState($service, ['draft', 'revision_required']);
                if ($service->vessel_id !== $vessel->id || ($service->submitted_at !== null && $service->request_type !== $data['request_type'])) {
                    throw ValidationException::withMessages(['state' => 'Kapal tidak dapat diganti; jenis pengurusan tidak dapat diganti setelah pengajuan.']);
                }
            }
            $before = $isNew ? null : $service->only(array_keys($data));
            $service->fill(array_replace($data, [
                'due_at' => isset($data['due_at']) ? $this->date($data['due_at']) : null,
            ]));
            if ($isNew) {
                $service->fill(['service_number' => ServiceNumber::temporary('SKK'), 'created_by' => $actor->id, 'priority' => 'medium', 'status' => 'draft']);
            }
            $service->save();
            if ($isNew) {
                $service->update(['service_number' => ServiceNumber::forId('SKK', $service->id, config('safety-certificates.timezone'))]);
            }
            $service->setRelation('vessel', $vessel);
            $this->capturePrevious($service, $actor, $paths);
            $this->storeUploads($service, $uploads, $actor, $paths);
            $this->history($service, $actor, $isNew ? null : $service->status, $isNew ? 'Service Created' : 'Data / Documents Updated', $data['notes'] ?? null, ['before' => $before, 'after' => $data]);

            return $service->refresh();
        });
    }

    public function ready(SafetyCertificateService $service): SafetyCertificateService
    {
        Gate::authorize('work', $service->fresh() ?? $service);

        return $this->change($service, function (SafetyCertificateService $locked, User $actor, Collection $paths): void {
            $this->ensureState($locked, ['draft']);
            $this->capturePrevious($locked, $actor, $paths);
            $this->readiness->ensureReady($locked);
            $this->transition($locked, $actor, 'ready_to_apply', 'Documents Complete');
        });
    }

    /** @param array<string, mixed> $input */
    public function apply(SafetyCertificateService $service, array $input): SafetyCertificateService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $data = Validator::make($this->normalize($input), [
            'submitted_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.$this->localNow()],
            'submission_reference' => ['nullable', 'string', 'max:255'],
            'authority' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();

        return $this->change($service, function (SafetyCertificateService $locked, User $actor, Collection $paths) use ($data): void {
            $this->ensureState($locked, ['ready_to_apply', 'revision_required']);
            $this->capturePrevious($locked, $actor, $paths);
            $this->readiness->ensureReady($locked);
            $submitted = $this->date($data['submitted_at']);
            $previous = $locked->resubmitted_at ?? $locked->submitted_at;
            if ($previous !== null && $submitted->lt($previous)) {
                throw ValidationException::withMessages(['submitted_at' => 'Pengajuan ulang tidak boleh sebelum pengajuan sebelumnya.']);
            }
            foreach ($this->readiness->vesselCheck($locked->vessel) as $check) {
                if ($check['complete'] && $check['document'] !== null) {
                    $this->copyReference($locked, $check['document'], 'vessel_reference', $actor, $paths);
                }
            }
            $resubmission = $locked->status === 'revision_required';
            $locked->fill([
                $resubmission ? 'resubmitted_at' : 'submitted_at' => $submitted,
                'submission_reference' => $data['submission_reference'] ?? $locked->submission_reference,
                'authority' => $data['authority'] ?? $locked->authority,
            ]);
            $this->transition($locked, $actor, 'under_review', $resubmission ? 'Resubmitted' : 'Submitted', $data['notes'] ?? null, $data);
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function recordReview(SafetyCertificateService $service, array $input, array $documents = []): SafetyCertificateService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $data = Validator::make($this->normalize($input), [
            'result' => ['required', Rule::in(['under_review', 'revision_required', 'inspection_required', 'rejected', 'approved'])],
            'revision_notes' => ['required_if:result,revision_required', 'nullable', 'string', 'max:5000'],
            'rejection_reason' => ['required_if:result,rejected', 'nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        if ($data['result'] === 'approved') {
            return $this->issueCertificate($service, $input, $documents);
        }
        if ($data['result'] === 'inspection_required') {
            return $this->scheduleInspection($service, $input);
        }
        $allowed = match ($data['result']) {
            'revision_required' => ['revision_attachment'], 'rejected' => ['rejection_attachment'], default => [],
        };
        $uploads = $this->validateUploads($documents, $allowed);

        return $this->change($service, function (SafetyCertificateService $locked, User $actor, Collection $paths) use ($data, $uploads): void {
            $this->ensureState($locked, ['under_review', 'waiting_for_issuance']);
            $this->storeUploads($locked, $uploads, $actor, $paths);
            if ($data['result'] === 'rejected') {
                $locked->fill(['rejection_reason' => $data['rejection_reason'], 'archived_at' => now()]);
            } elseif ($data['result'] === 'revision_required') {
                $locked->revision_notes = $data['revision_notes'];
            }
            $this->transition($locked, $actor, $data['result'], SafetyCertificateService::STATUSES[$data['result']], $data['rejection_reason'] ?? $data['revision_notes'] ?? $data['notes'] ?? null);
        });
    }

    /** @param array<string, mixed> $input */
    public function scheduleInspection(SafetyCertificateService $service, array $input): SafetyCertificateService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $data = Validator::make($this->normalize($input), [
            'inspection_scheduled_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'inspection_location' => ['required', 'string', 'max:255'],
            'inspector_name' => ['nullable', 'string', 'max:255'],
            'inspection_notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();

        return $this->change($service, function (SafetyCertificateService $locked, User $actor) use ($data): void {
            $this->ensureState($locked, ['under_review', 'inspection_scheduled']);
            $scheduled = $this->date($data['inspection_scheduled_at']);
            if ($scheduled->lt($locked->resubmitted_at ?? $locked->submitted_at)) {
                throw ValidationException::withMessages(['inspection_scheduled_at' => 'Pemeriksaan tidak boleh sebelum pengajuan terakhir.']);
            }
            $locked->fill($data);
            $locked->inspection_scheduled_at = $scheduled;
            $locked->inspection_required = true;
            $locked->inspection_result = null;
            $locked->inspection_completed_at = null;
            $this->transition($locked, $actor, 'inspection_scheduled', 'Inspection Scheduled', $data['inspection_notes'] ?? null, $data);
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function recordInspection(SafetyCertificateService $service, array $input, array $documents = []): SafetyCertificateService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $data = Validator::make($this->normalize($input), [
            'inspection_result' => ['required', Rule::in(['passed', 'revision_required', 'rejected', 'awaiting_decision'])],
            'inspection_completed_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.$this->localNow()],
            'findings' => ['required_if:inspection_result,revision_required', 'nullable', 'string', 'max:5000'],
            'revision_notes' => ['required_if:inspection_result,revision_required', 'nullable', 'string', 'max:5000'],
            'rejection_reason' => ['required_if:inspection_result,rejected', 'nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        $uploads = $this->validateUploads($documents, ['inspection_report', 'rejection_attachment']);

        return $this->change($service, function (SafetyCertificateService $locked, User $actor, Collection $paths) use ($data, $uploads): void {
            $this->ensureState($locked, ['inspection_scheduled']);
            $completed = $this->date($data['inspection_completed_at']);
            if ($completed->lt($locked->inspection_scheduled_at)) {
                throw ValidationException::withMessages(['inspection_completed_at' => 'Hasil pemeriksaan tidak boleh sebelum jadwal pemeriksaan.']);
            }
            $this->storeUploads($locked, $uploads, $actor, $paths);
            $locked->fill([
                'inspection_result' => $data['inspection_result'], 'inspection_completed_at' => $completed,
                'findings' => $data['findings'] ?? null,
            ]);
            $this->transition($locked, $actor, 'inspection_completed', 'Inspection Completed', $data['notes'] ?? null, $data);
            $next = match ($data['inspection_result']) {
                'passed' => 'waiting_for_issuance', 'awaiting_decision' => 'under_review',
                'revision_required' => 'revision_required', default => 'rejected',
            };
            if ($next === 'revision_required') {
                $locked->revision_notes = $data['revision_notes'];
            }
            if ($next === 'rejected') {
                $locked->fill(['rejection_reason' => $data['rejection_reason'], 'archived_at' => now()]);
            }
            $this->transition($locked, $actor, $next, SafetyCertificateService::STATUSES[$next], $data['revision_notes'] ?? $data['rejection_reason'] ?? $data['notes'] ?? null);
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function issueCertificate(SafetyCertificateService $service, array $input, array $documents): SafetyCertificateService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $data = Validator::make($this->normalize($input), [
            'certificate_number' => ['required', 'string', 'max:255'],
            'certificate_issued_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('safety-certificates.timezone'))->toDateString()],
            'certificate_expired_at' => [Rule::requiredIf((bool) config('safety-certificates.certificate_requires_expiry')), 'nullable', 'date_format:Y-m-d', 'after:certificate_issued_at'],
            'issuing_authority' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        $uploads = $this->validateUploads($documents, ['issued_certificate'], ['issued_certificate']);

        return $this->change($service, function (SafetyCertificateService $locked, User $actor, Collection $paths) use ($data, $uploads): void {
            $this->ensureState($locked, ['under_review', 'waiting_for_issuance']);
            $submission = ($locked->resubmitted_at ?? $locked->submitted_at)?->setTimezone(config('safety-certificates.timezone'))->toDateString();
            if ($submission === null || $data['certificate_issued_at'] < $submission) {
                throw ValidationException::withMessages(['certificate_issued_at' => 'Tanggal terbit tidak boleh sebelum pengajuan terakhir.']);
            }
            $current = $locked->vessel->documents()->where('document_type', 'safety_certificate')->lockForUpdate()->first();
            $baseline = $locked->previous_certificate_snapshot['file_path'] ?? null;
            if ($current?->file_path !== $baseline) {
                throw ValidationException::withMessages(['state' => 'Sertifikat kapal berubah sejak layanan dibuat. Tinjau sertifikat terbaru dan buat pengurusan baru.']);
            }
            $this->storeUploads($locked, $uploads, $actor, $paths);
            $issued = $locked->latestDocument('issued_certificate');
            if ($issued === null) {
                throw new RuntimeException('File sertifikat tidak tersimpan.');
            }
            if ($current !== null) {
                $current->archiveCurrent($actor->id);
            }
            $document = $current ?? $locked->vessel->documents()->make(['document_type' => 'safety_certificate']);
            $vesselPath = 'vessels/'.$locked->vessel_id.'/'.Str::uuid().'.'.pathinfo($issued->file_path, PATHINFO_EXTENSION);
            $this->copyFile($issued->file_path, $vesselPath, $paths);
            $document->fill([
                'certificate_number' => $data['certificate_number'], 'file_path' => $vesselPath,
                'original_filename' => $issued->original_filename,
                'issued_date' => $data['certificate_issued_at'], 'expired_date' => $data['certificate_expired_at'] ?? null,
                'source_safety_certificate_service_id' => $locked->id,
            ])->save();
            $locked->fill($data);
            $locked->issued_certificate_document_id = $issued->id;
            $this->transition($locked, $actor, 'certificate_issued', 'Certificate Issued', $data['notes'] ?? null, ['vessel_document_id' => $document->id] + $data);
            $this->transition($locked, $actor, 'ready_for_handover', 'Ready For Handover');
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function handover(SafetyCertificateService $service, array $input, array $documents = []): SafetyCertificateService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $data = Validator::make($this->normalize($input), [
            'handed_over_to' => ['required', 'string', 'max:255'],
            'recipient_role' => ['nullable', 'string', 'max:255'],
            'handed_over_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.$this->localNow()],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        $uploads = $this->validateUploads($documents, ['handover_receipt']);

        return $this->change($service, function (SafetyCertificateService $locked, User $actor, Collection $paths) use ($data, $uploads): void {
            $this->ensureState($locked, ['ready_for_handover']);
            $issued = $locked->issuedCertificate;
            $current = $locked->vessel->documents()->where('document_type', 'safety_certificate')->first();
            if ($issued === null || ! Storage::disk('local')->exists($issued->file_path)
                || $current === null || $current->source_safety_certificate_service_id !== $locked->id
                || ! Storage::disk('local')->exists($current->file_path)) {
                throw ValidationException::withMessages(['state' => 'Sertifikat resmi atau dokumen aktif kapal tidak tersedia atau sudah diganti.']);
            }
            $time = $this->date($data['handed_over_at']);
            if ($time->setTimezone(config('safety-certificates.timezone'))->toDateString() < $locked->certificate_issued_at?->toDateString()) {
                throw ValidationException::withMessages(['handed_over_at' => 'Serah terima tidak boleh sebelum tanggal terbit.']);
            }
            $this->storeUploads($locked, $uploads, $actor, $paths);
            $locked->fill([
                'handed_over_to' => $data['handed_over_to'], 'recipient_role' => $data['recipient_role'] ?? null,
                'handed_over_at' => $time, 'handed_over_by' => $actor->id,
                'completed_at' => now(), 'archived_at' => now(),
            ]);
            $this->transition($locked, $actor, 'completed', 'Certificate Handed Over / Completed', $data['notes'] ?? null, $data);
        });
    }

    private function actor(): User
    {
        Gate::authorize('manage-services');

        return User::query()->findOrFail(auth()->id());
    }

    /** @param list<string> $allowed */
    private function ensureState(SafetyCertificateService $service, array $allowed): void
    {
        if (! in_array($service->status, $allowed, true)) {
            throw ValidationException::withMessages(['state' => 'Tindakan tidak tersedia untuk status '.$service->statusLabel().'. Muat ulang halaman.']);
        }
    }

    /** @param Closure(SafetyCertificateService, User, Collection<int, string>): void $action */
    private function change(SafetyCertificateService $service, Closure $action): SafetyCertificateService
    {
        $actor = $this->actor();

        return $this->atomic(function (Collection $paths) use ($service, $actor, $action): SafetyCertificateService {
            $vessel = Vessel::query()->lockForUpdate()->findOrFail($service->vessel_id);
            if ($vessel->vessel_type !== 'passenger') {
                throw ValidationException::withMessages(['vessel_id' => 'Layanan ini hanya untuk kapal penumpang.']);
            }
            $locked = SafetyCertificateService::query()->lockForUpdate()->findOrFail($service->id);
            Gate::authorize('work', $locked);
            $locked->setRelation('vessel', $vessel);
            $action($locked, $actor, $paths);

            return $locked->refresh();
        });
    }

    /** @param Closure(Collection<int, string>): SafetyCertificateService $action */
    private function atomic(Closure $action): SafetyCertificateService
    {
        $paths = new Collection;
        try {
            return DB::transaction(function () use ($action, $paths): SafetyCertificateService {
                $result = $action($paths);

                return $result;
            });
        } catch (Throwable $exception) {
            foreach ($paths as $path) {
                try {
                    if (! Storage::disk('local')->delete($path)) {
                        report(new RuntimeException('Gagal membersihkan file service: '.$path));
                    }
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            throw $exception;
        }
    }

    /** @param array<string, UploadedFile|null> $documents
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     * @return array<string, UploadedFile|null>
     */
    private function validateUploads(array $documents, array $allowed, array $required = []): array
    {
        $rules = ['documents' => $allowed === [] ? ['array', 'size:0'] : ['array:'.implode(',', $allowed)]];
        foreach ($allowed as $type) {
            $rules['documents.'.$type] = [
                in_array($type, $required, true) ? 'required' : 'nullable', 'file',
                'mimes:pdf,jpg,jpeg,png,webp,gif,doc,docx', 'extensions:pdf,jpg,jpeg,png,webp,gif,doc,docx',
                new UploadSize((int) config('safety-certificates.document_max_kb')),
            ];
        }

        return Validator::make(['documents' => $documents], $rules, $this->messages())->validate()['documents'];
    }

    /** @param array<string, UploadedFile|null> $documents
     * @param  Collection<int, string>  $paths
     */
    private function storeUploads(SafetyCertificateService $service, array $documents, User $actor, Collection $paths): void
    {
        foreach ($documents as $type => $file) {
            if ($file === null) {
                continue;
            }
            $path = app(ImageOptimizer::class)->store($file, 'safety-certificates/'.$service->id);
            if ($path === false) {
                throw new RuntimeException('File layanan gagal disimpan.');
            }
            $paths->push($path);
            $service->documents()->create([
                'document_type' => $type, 'file_path' => $path,
                'original_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                'uploaded_by' => $actor->id, 'uploaded_at' => now(),
            ]);
        }
        $service->unsetRelation('documents');
    }

    /** @param Collection<int, string> $paths */
    private function capturePrevious(SafetyCertificateService $service, User $actor, Collection $paths): void
    {
        if ($service->previous_certificate_snapshot !== null) {
            return;
        }
        $current = $service->vessel->documents()->where('document_type', 'safety_certificate')->first();
        if ($current === null) {
            return;
        }
        $fileExists = Storage::disk('local')->exists($current->file_path);
        if (! $fileExists && $service->request_type === 'renewal') {
            return;
        }
        if ($fileExists) {
            $this->copyReference($service, $current, 'previous_certificate', $actor, $paths);
        }
        $service->update([
            'current_certificate_document_id' => $current->id,
            'previous_certificate_snapshot' => [
                'id' => $current->id, 'file_path' => $current->file_path,
                'certificate_number' => $current->certificate_number,
                'issued_date' => $current->issued_date?->toDateString(),
                'expired_date' => $current->expired_date?->toDateString(),
            ],
        ]);
    }

    /** @param Collection<int, string> $paths */
    private function copyReference(SafetyCertificateService $service, VesselDocument $document, string $type, User $actor, Collection $paths): void
    {
        $path = 'safety-certificates/'.$service->id.'/'.Str::uuid().'.'.pathinfo($document->file_path, PATHINFO_EXTENSION);
        $this->copyFile($document->file_path, $path, $paths);
        $service->documents()->create([
            'document_type' => $type, 'file_path' => $path, 'original_filename' => $document->original_filename,
            'uploaded_by' => $actor->id, 'uploaded_at' => now(),
            'metadata' => ['vessel_document_id' => $document->id, 'document_type' => $document->document_type,
                'certificate_number' => $document->certificate_number, 'issued_date' => $document->issued_date?->toDateString(),
                'expired_date' => $document->expired_date?->toDateString(), 'equipment' => $document->equipment],
        ]);
        $service->unsetRelation('documents');
    }

    /** @param Collection<int, string> $paths */
    private function copyFile(string $source, string $target, Collection $paths): void
    {
        $paths->push($target);
        if (! Storage::disk('local')->copy($source, $target)) {
            throw new RuntimeException('Arsip dokumen gagal disimpan.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function transition(SafetyCertificateService $service, User $actor, string $to, string $event, ?string $notes = null, array $metadata = []): void
    {
        $from = $service->status;
        $service->status = $to;
        $service->save();
        $this->history($service, $actor, $from, $event, $notes, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    private function history(SafetyCertificateService $service, User $actor, ?string $from, string $event, ?string $notes = null, array $metadata = []): void
    {
        $service->histories()->create([
            'from_status' => $from, 'to_status' => $service->status, 'event' => $event,
            'changed_by' => $actor->id, 'notes' => $notes, 'metadata' => $metadata,
        ]);
    }

    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, config('safety-certificates.timezone'))->utc();
    }

    private function localNow(): string
    {
        return now(config('safety-certificates.timezone'))->format('Y-m-d H:i');
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim($value) === '' ? null : trim($value);
            }
        }

        return $input;
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.', 'required_if' => ':attribute wajib diisi untuk hasil ini.',
            'exists' => ':attribute tidak tersedia atau tidak sesuai jenis layanan.',
            'in' => ':attribute tidak valid.', 'date_format' => ':attribute harus berupa tanggal / jam yang valid.',
            'mimes' => 'File harus PDF, JPG, JPEG, PNG, WebP, GIF, DOC, atau DOCX.', 'extensions' => 'Ekstensi file harus PDF, JPG, JPEG, PNG, WebP, GIF, DOC, atau DOCX.',
        ];
    }
}
