<?php

namespace App\Services;

use App\Models\ShipNationalityDocument;
use App\Models\ShipNationalityService;
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

class ShipNationalityWorkflow
{
    public function __construct(private ShipNationalityReadinessService $readiness) {}

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function saveDraft(?ShipNationalityService $existing, array $input, array $documents = []): ShipNationalityService
    {
        $actor = $this->actor();
        $input = app(ServiceAssignment::class)->prepare($existing, $input);
        $data = Validator::make($this->normalize($input), [
            'vessel_id' => ['required', 'integer', Rule::exists('vessels', 'id')],
            'document_type' => ['required', Rule::in(array_keys(ShipNationalityService::DOCUMENT_TYPES))],
            'request_type' => ['required', Rule::in(array_keys(ShipNationalityService::REQUEST_TYPES))],
            'assigned_staff_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'notes' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ], $this->messages())->validate();
        $uploads = $this->validateUploads($documents, array_keys(ShipNationalityDocument::APPLICATION_TYPES + ['revision_attachment' => 'Revision']));

        return $this->atomic(function (Collection $paths) use ($existing, $actor, $data, $uploads): ShipNationalityService {
            $vessel = Vessel::query()->whereKey($data['vessel_id'])->lockForUpdate()->firstOrFail();
            $service = $existing ? ShipNationalityService::query()->lockForUpdate()->findOrFail($existing->id) : new ShipNationalityService;
            $data = app(ServiceAssignment::class)->prepare($service, $data);
            $isNew = ! $service->exists;
            if (! $isNew) {
                $this->ensureState($service, ['draft', 'revision_required']);
                if ($service->vessel_id !== $vessel->id || $service->document_type !== $data['document_type'] || ($service->submitted_at !== null && $service->request_type !== $data['request_type'])) {
                    throw ValidationException::withMessages(['state' => 'Kapal dan jenis dokumen tidak dapat diganti; jenis pengurusan tidak dapat diganti setelah pengajuan.']);
                }
            }
            $before = $isNew ? null : $service->only(array_keys($data));
            $service->fill(array_replace($data, [
                'due_at' => isset($data['due_at']) ? $this->date($data['due_at']) : null,
            ]));
            if ($isNew) {
                $service->fill(['service_number' => ServiceNumber::temporary('SN'), 'created_by' => $actor->id, 'priority' => 'medium', 'status' => 'draft']);
            }
            $service->save();
            if ($isNew) {
                $service->update(['service_number' => ServiceNumber::forId('SN', $service->id, config('ship-nationality.timezone'))]);
            }
            $service->setRelation('vessel', $vessel);
            $this->capturePrevious($service, $actor, $paths);
            $this->storeUploads($service, $uploads, $actor, $paths);
            $this->history($service, $actor, $isNew ? null : $service->status, $isNew ? 'Service Created' : 'Data / Documents Updated', $data['notes'] ?? null, ['before' => $before, 'after' => $data]);

            return $service->refresh();
        });
    }

    public function ready(ShipNationalityService $service): ShipNationalityService
    {
        Gate::authorize('work', $service->fresh() ?? $service);

        return $this->change($service, function (ShipNationalityService $locked, User $actor, Collection $paths): void {
            $this->ensureState($locked, ['draft']);
            $this->capturePrevious($locked, $actor, $paths);
            $this->readiness->ensureReady($locked);
            $this->transition($locked, $actor, 'ready_to_apply', 'Documents Complete');
        });
    }

    /** @param array<string, mixed> $input */
    public function apply(ShipNationalityService $service, array $input): ShipNationalityService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), [
            'submitted_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.$this->localNow()],
            'submission_reference' => ['nullable', 'string', 'max:255'],
            'authority' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();

        return $this->change($service, function (ShipNationalityService $locked, User $actor, Collection $paths) use ($data): void {
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
    public function recordReview(ShipNationalityService $service, array $input, array $documents = []): ShipNationalityService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), [
            'result' => ['required', Rule::in(['revision_required', 'rejected', 'document_issued'])],
            'revision_notes' => ['required_if:result,revision_required', 'nullable', 'string', 'max:5000'],
            'rejection_reason' => ['required_if:result,rejected', 'nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        if ($data['result'] === 'document_issued') {
            return $this->issueDocument($service, $input, $documents);
        }
        $allowed = match ($data['result']) {
            'revision_required' => ['revision_attachment'], 'rejected' => ['rejection_attachment'], default => [],
        };
        $uploads = $this->validateUploads($documents, $allowed);

        return $this->change($service, function (ShipNationalityService $locked, User $actor, Collection $paths) use ($data, $uploads): void {
            $this->ensureState($locked, ['under_review']);
            $this->storeUploads($locked, $uploads, $actor, $paths);
            if ($data['result'] === 'rejected') {
                $locked->fill(['rejection_reason' => $data['rejection_reason'], 'archived_at' => now()]);
            } elseif ($data['result'] === 'revision_required') {
                $locked->revision_notes = $data['revision_notes'];
            }
            $this->transition($locked, $actor, $data['result'], ShipNationalityService::STATUSES[$data['result']], $data['rejection_reason'] ?? $data['revision_notes'] ?? $data['notes'] ?? null);
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function issueDocument(ShipNationalityService $service, array $input, array $documents): ShipNationalityService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), [
            'issued_document_number' => ['required', 'string', 'max:255'],
            'issued_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('ship-nationality.timezone'))->toDateString()],
            'expired_at' => ['nullable', 'date_format:Y-m-d', 'after:issued_at'],
            'issuing_authority' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        $uploads = $this->validateUploads($documents, ['issued_document'], ['issued_document']);

        return $this->change($service, function (ShipNationalityService $locked, User $actor, Collection $paths) use ($data, $uploads): void {
            $this->ensureState($locked, ['under_review']);
            $submission = ($locked->resubmitted_at ?? $locked->submitted_at)?->setTimezone(config('ship-nationality.timezone'))->toDateString();
            if ($submission === null || $data['issued_at'] < $submission) {
                throw ValidationException::withMessages(['issued_at' => 'Tanggal terbit tidak boleh sebelum pengajuan terakhir.']);
            }
            $current = $locked->vessel->documents()->where('document_type', $locked->document_type)->lockForUpdate()->first();
            if ($this->documentSnapshot($current) != $locked->previous_document_snapshot) {
                throw ValidationException::withMessages(['state' => 'Dokumen kapal berubah sejak layanan dibuat. Tinjau dokumen terbaru dan buat pengurusan baru.']);
            }
            $this->storeUploads($locked, $uploads, $actor, $paths);
            $issued = $locked->latestDocument('issued_document');
            if ($issued === null) {
                throw new RuntimeException('File dokumen tidak tersimpan.');
            }
            if ($current !== null) {
                $current->archiveCurrent($actor->id);
            }
            $document = $current ?? $locked->vessel->documents()->make(['document_type' => $locked->document_type]);
            $vesselPath = 'vessels/'.$locked->vessel_id.'/'.Str::uuid().'.'.pathinfo($issued->file_path, PATHINFO_EXTENSION);
            $this->copyFile($issued->file_path, $vesselPath, $paths);
            $document->fill([
                'certificate_number' => $data['issued_document_number'], 'file_path' => $vesselPath,
                'original_filename' => $issued->original_filename,
                'issued_date' => $data['issued_at'], 'expired_date' => $data['expired_at'] ?? null,
                'source_ship_nationality_service_id' => $locked->id,
            ])->save();
            $locked->fill(collect($data)->except('notes')->all());
            $locked->issued_document_id = $issued->id;
            $this->transition($locked, $actor, 'document_issued', 'Document Issued', $data['notes'] ?? null, ['vessel_document_id' => $document->id] + $data);
            $this->transition($locked, $actor, 'ready_for_handover', 'Ready For Handover');
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function handover(ShipNationalityService $service, array $input, array $documents = []): ShipNationalityService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), [
            'handed_over_to' => ['required', 'string', 'max:255'],
            'recipient_role' => ['nullable', 'string', 'max:255'],
            'handed_over_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.$this->localNow()],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        $uploads = $this->validateUploads($documents, ['handover_receipt']);

        return $this->change($service, function (ShipNationalityService $locked, User $actor, Collection $paths) use ($data, $uploads): void {
            $this->ensureState($locked, ['ready_for_handover']);
            $issued = $locked->issuedDocument;
            $current = $locked->vessel->documents()->where('document_type', $locked->document_type)->first();
            if ($issued === null || ! Storage::disk('local')->exists($issued->file_path)
                || $current === null || $current->source_ship_nationality_service_id !== $locked->id
                || ! Storage::disk('local')->exists($current->file_path)) {
                throw ValidationException::withMessages(['state' => 'Dokumen resmi atau dokumen aktif kapal tidak tersedia atau sudah diganti.']);
            }
            $time = $this->date($data['handed_over_at']);
            if ($time->setTimezone(config('ship-nationality.timezone'))->toDateString() < $locked->issued_at?->toDateString()) {
                throw ValidationException::withMessages(['handed_over_at' => 'Serah terima tidak boleh sebelum tanggal terbit.']);
            }
            $this->storeUploads($locked, $uploads, $actor, $paths);
            $locked->fill([
                'handed_over_to' => $data['handed_over_to'], 'recipient_role' => $data['recipient_role'] ?? null,
                'handed_over_at' => $time, 'handed_over_by' => $actor->id,
                'completed_at' => now(), 'archived_at' => now(),
            ]);
            $this->transition($locked, $actor, 'completed', 'Document Handed Over / Completed', $data['notes'] ?? null, $data);
        });
    }

    /** @param array<string, mixed> $input */
    public function cancel(ShipNationalityService $service, array $input): ShipNationalityService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), ['cancellation_reason' => ['required', 'string', 'max:5000']], $this->messages())->validate();

        return $this->change($service, function (ShipNationalityService $locked, User $actor) use ($data): void {
            $this->ensureState($locked, ['draft', 'ready_to_apply', 'under_review', 'revision_required']);
            $locked->fill(['cancellation_reason' => $data['cancellation_reason'], 'archived_at' => now()]);
            $this->transition($locked, $actor, 'cancelled', 'Service Cancelled', $data['cancellation_reason']);
        });
    }

    private function actor(): User
    {
        Gate::authorize('manage-services');

        return User::query()->findOrFail(auth()->id());
    }

    /** @param list<string> $allowed */
    private function ensureState(ShipNationalityService $service, array $allowed): void
    {
        if (! in_array($service->status, $allowed, true)) {
            throw ValidationException::withMessages(['state' => 'Tindakan tidak tersedia untuk status '.$service->statusLabel().'. Muat ulang halaman.']);
        }
    }

    /** @param Closure(ShipNationalityService, User, Collection<int, string>): void $action */
    private function change(ShipNationalityService $service, Closure $action): ShipNationalityService
    {
        $actor = $this->actor();

        return $this->atomic(function (Collection $paths) use ($service, $actor, $action): ShipNationalityService {
            $vessel = Vessel::query()->lockForUpdate()->findOrFail($service->vessel_id);
            $locked = ShipNationalityService::query()->lockForUpdate()->findOrFail($service->id);
            Gate::authorize('work', $locked);
            $locked->setRelation('vessel', $vessel);
            $action($locked, $actor, $paths);

            return $locked->refresh();
        });
    }

    /** @param Closure(Collection<int, string>): ShipNationalityService $action */
    private function atomic(Closure $action): ShipNationalityService
    {
        $paths = new Collection;
        try {
            return DB::transaction(function () use ($action, $paths): ShipNationalityService {
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
                new UploadSize((int) config('ship-nationality.document_max_kb')),
            ];
        }

        return Validator::make(['documents' => $documents], $rules, $this->messages())->validate()['documents'];
    }

    /** @param array<string, UploadedFile|null> $documents
     * @param  Collection<int, string>  $paths
     */
    private function storeUploads(ShipNationalityService $service, array $documents, User $actor, Collection $paths): void
    {
        foreach ($documents as $type => $file) {
            if ($file === null) {
                continue;
            }
            $path = app(ImageOptimizer::class)->store($file, 'ship-nationality/'.$service->id);
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
    private function capturePrevious(ShipNationalityService $service, User $actor, Collection $paths): void
    {
        if ($service->previous_document_snapshot !== null || $service->submitted_at !== null) {
            return;
        }
        $current = $service->vessel->documents()->where('document_type', $service->document_type)->first();
        if ($current === null) {
            return;
        }
        if (Storage::disk('local')->exists($current->file_path)) {
            $this->copyReference($service, $current, 'previous_document', $actor, $paths);
        } elseif ($service->request_type === 'renewal') {
            return;
        }
        $service->update(['previous_document_id' => $current->id, 'previous_document_snapshot' => $this->documentSnapshot($current)]);
    }

    /** @return array{id: int, file_path: string, certificate_number: string|null, issued_date: string|null, expired_date: string|null}|null */
    private function documentSnapshot(?VesselDocument $document): ?array
    {
        return $document === null ? null : [
            'id' => $document->id, 'file_path' => $document->file_path, 'certificate_number' => $document->certificate_number,
            'issued_date' => $document->issued_date?->toDateString(), 'expired_date' => $document->expired_date?->toDateString(),
        ];
    }

    /** @param Collection<int, string> $paths */
    private function copyReference(ShipNationalityService $service, VesselDocument $document, string $type, User $actor, Collection $paths): void
    {
        $path = 'ship-nationality/'.$service->id.'/'.Str::uuid().'.'.pathinfo($document->file_path, PATHINFO_EXTENSION);
        $this->copyFile($document->file_path, $path, $paths);
        $service->documents()->create([
            'document_type' => $type, 'file_path' => $path, 'original_filename' => $document->original_filename,
            'uploaded_by' => $actor->id, 'uploaded_at' => now(),
            'metadata' => ['vessel_document_id' => $document->id, 'document_type' => $document->document_type,
                'certificate_number' => $document->certificate_number, 'issued_date' => $document->issued_date?->toDateString(),
                'expired_date' => $document->expired_date?->toDateString()],
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
    private function transition(ShipNationalityService $service, User $actor, string $to, string $event, ?string $notes = null, array $metadata = []): void
    {
        $from = $service->status;
        $service->status = $to;
        $service->save();
        $this->history($service, $actor, $from, $event, $notes, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    private function history(ShipNationalityService $service, User $actor, ?string $from, string $event, ?string $notes = null, array $metadata = []): void
    {
        $service->histories()->create([
            'from_status' => $from, 'to_status' => $service->status, 'event' => $event,
            'changed_by' => $actor->id, 'notes' => $notes, 'metadata' => $metadata,
        ]);
    }

    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, config('ship-nationality.timezone'))->utc();
    }

    private function localNow(): string
    {
        return now(config('ship-nationality.timezone'))->format('Y-m-d H:i');
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
