<?php

namespace App\Services;

use App\Models\BunkerDocument;
use App\Models\BunkerService;
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

class BunkerWorkflow
{
    public function __construct(private BunkerReadinessService $readiness) {}

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function saveDraft(?BunkerService $existing, array $input, array $documents = []): BunkerService
    {
        $actor = $this->actor();
        $input = app(ServiceAssignment::class)->prepare($existing, $input);
        $data = Validator::make($this->normalize($input), [
            'vessel_id' => ['required', 'integer', 'exists:vessels,id'],
            'assigned_staff_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'bunker_type' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ] + $this->scheduleRules(false), $this->messages())->validate();
        $uploads = $this->validateUploads($documents, array_keys(BunkerDocument::APPLICATION_TYPES + ['revision_attachment' => 'Revision']));

        return $this->atomic(function (Collection $paths) use ($existing, $data, $uploads, $actor): BunkerService {
            $vessel = Vessel::query()->whereKey($data['vessel_id'])->lockForUpdate()->firstOrFail();
            $service = $existing ? BunkerService::query()->lockForUpdate()->findOrFail($existing->id) : new BunkerService;
            $data = app(ServiceAssignment::class)->prepare($service, $data);
            $isNew = ! $service->exists;
            if (! $isNew) {
                $this->ensureState($service, ['draft', 'revision_required']);
                if ($service->vessel_id !== $vessel->id) {
                    throw ValidationException::withMessages(['vessel_id' => 'Kapal tidak dapat diganti pada layanan yang sudah dibuat.']);
                }
            }
            $before = $isNew ? null : $service->only(array_keys($data));
            $service->fill(array_replace($data, [
                'assigned_staff_id' => $data['assigned_staff_id'],
                'scheduled_at' => isset($data['scheduled_at']) ? $this->date($data['scheduled_at']) : null,
                'unit_other' => ($data['unit'] ?? null) === 'other' ? ($data['unit_other'] ?? null) : null,
            ]));
            if ($isNew) {
                $service->fill(['service_number' => ServiceNumber::temporary('BKR'), 'created_by' => $actor->id, 'priority' => 'medium', 'status' => 'draft']);
            }
            $service->save();
            if ($isNew) {
                $service->update(['service_number' => ServiceNumber::forId('BKR', $service->id, config('bunker.timezone'))]);
            }
            $this->storeUploads($service, $uploads, $actor, $paths);
            $this->history($service, $actor, $isNew ? null : $service->status, $isNew ? 'Service Created' : 'Data / Documents Updated', $data['notes'] ?? null, ['before' => $before, 'after' => $data]);

            return $service->refresh();
        });
    }

    public function ready(BunkerService $service): BunkerService
    {
        Gate::authorize('work', $service->fresh() ?? $service);

        return $this->change($service, function (BunkerService $locked, User $actor): void {
            $this->ensureState($locked, ['draft']);
            $this->readiness->ensureReady($locked);
            $this->transition($locked, $actor, 'ready_to_apply', 'Documents Complete');
        });
    }

    /** @param array<string, mixed> $input */
    public function submit(BunkerService $service, array $input): BunkerService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), [
            'submitted_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.$this->localNow()],
            'submission_reference' => ['nullable', 'string', 'max:255'],
            'authority' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();

        return $this->change($service, function (BunkerService $locked, User $actor, Collection $paths) use ($data): void {
            $this->ensureState($locked, ['ready_to_apply', 'revision_required']);
            $this->readiness->ensureReady($locked);
            $date = $this->date($data['submitted_at']);
            $previous = $locked->resubmitted_at ?? $locked->submitted_at;
            if ($previous !== null && $date->lt($previous)) {
                throw ValidationException::withMessages(['submitted_at' => 'Pengajuan ulang tidak boleh sebelum pengajuan sebelumnya.']);
            }
            foreach ($this->readiness->vesselCheck($locked->vessel) as $check) {
                if ($check['complete'] && $check['document'] !== null) {
                    $this->copyReference($locked, $check['document'], 'vessel_reference', $actor, $paths);
                }
            }
            $resubmission = $locked->status === 'revision_required';
            $locked->fill([
                $resubmission ? 'resubmitted_at' : 'submitted_at' => $date,
                'submission_reference' => $data['submission_reference'] ?? $locked->submission_reference,
                'authority' => $data['authority'] ?? $locked->authority,
            ]);
            $this->transition($locked, $actor, 'under_review', $resubmission ? 'Resubmitted' : 'Submitted', $data['notes'] ?? null, $data);
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function recordReview(BunkerService $service, array $input, array $documents = []): BunkerService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), [
            'result' => ['required', Rule::in(['revision_required', 'rejected', 'approved'])],
            'revision_notes' => ['exclude_unless:result,revision_required', 'required', 'string', 'max:5000'],
            'rejection_reason' => ['exclude_unless:result,rejected', 'required', 'string', 'max:5000'],
            'approval_number' => ['exclude_unless:result,approved', 'nullable', 'string', 'max:255'],
            'approved_at' => ['exclude_unless:result,approved', 'required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.$this->localNow()],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        $type = match ($data['result']) {
            'revision_required' => 'revision_attachment', 'rejected' => 'rejection_attachment', default => 'approval_document',
        };
        $uploads = $this->validateUploads($documents, [$type], $data['result'] === 'approved' ? [$type] : []);

        return $this->change($service, function (BunkerService $locked, User $actor, Collection $paths) use ($data, $uploads): void {
            $this->ensureState($locked, ['under_review']);
            $result = $data['result'];
            $notes = $data['notes'] ?? null;
            if ($result === 'approved') {
                $date = $this->date($data['approved_at']);
                $submitted = $locked->resubmitted_at ?? $locked->submitted_at;
                if ($submitted === null || $date->lt($submitted)) {
                    throw ValidationException::withMessages(['approved_at' => 'Persetujuan tidak boleh sebelum pengajuan terakhir.']);
                }
                $locked->fill(['approval_number' => $data['approval_number'] ?? null, 'approved_at' => $date]);
            } elseif ($result === 'revision_required') {
                $locked->revision_notes = $data['revision_notes'];
                $notes = $data['revision_notes'];
            } else {
                $locked->rejection_reason = $data['rejection_reason'];
                $locked->archived_at = now();
                $notes = $data['rejection_reason'];
            }
            $this->storeUploads($locked, $uploads, $actor, $paths);
            $this->transition($locked, $actor, $result, BunkerService::STATUSES[$result], $notes, $data);
        });
    }

    /** @param array<string, mixed> $input */
    public function schedule(BunkerService $service, array $input): BunkerService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), $this->scheduleRules(true) + [
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();

        return $this->change($service, function (BunkerService $locked, User $actor) use ($data): void {
            $this->ensureState($locked, ['approved', 'bunker_scheduled']);
            $this->ensureApproval($locked);
            $date = $this->date($data['scheduled_at']);
            if ($locked->approved_at === null || $date->lt($locked->approved_at)) {
                throw ValidationException::withMessages(['scheduled_at' => 'Jadwal bunker tidak boleh sebelum persetujuan.']);
            }
            $before = $locked->only(['supplier', 'bunker_location', 'scheduled_at', 'quantity', 'unit', 'unit_other']);
            $locked->fill(collect($data)->except('notes')->all());
            $locked->scheduled_at = $date;
            $this->readiness->ensureReady($locked, $date);
            $locked->unit_other = $data['unit'] === 'other' ? $data['unit_other'] : null;
            $this->transition($locked, $actor, 'bunker_scheduled', $locked->status === 'approved' ? 'Bunker Scheduled' : 'Bunker Rescheduled', $data['notes'] ?? null, ['before' => $before, 'after' => $data]);
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function complete(BunkerService $service, array $input, array $documents = []): BunkerService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), [
            'actual_bunker_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.$this->localNow()],
            'actual_quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999.999', 'decimal:0,3'],
            'actual_unit' => ['required', Rule::in(array_keys(BunkerService::UNITS))],
            'actual_unit_other' => ['required_if:actual_unit,other', 'nullable', 'string', 'max:50'],
            'activity_notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        $uploads = $this->validateUploads($documents, ['activity_proof', 'payment_proof']);

        return $this->change($service, function (BunkerService $locked, User $actor, Collection $paths) use ($data, $uploads): void {
            $this->ensureState($locked, ['bunker_scheduled']);
            $this->ensureApproval($locked);
            $date = $this->date($data['actual_bunker_at']);
            if ($locked->approved_at === null || $date->lt($locked->approved_at)) {
                throw ValidationException::withMessages(['actual_bunker_at' => 'Kegiatan bunker tidak boleh sebelum persetujuan.']);
            }
            $locked->fill($data);
            $locked->actual_bunker_at = $date;
            $this->readiness->ensureReady($locked, $date);
            $locked->actual_unit_other = $data['actual_unit'] === 'other' ? $data['actual_unit_other'] : null;
            $this->storeUploads($locked, $uploads, $actor, $paths);
            $this->transition($locked, $actor, 'bunker_completed', 'Bunker Activity Completed', $data['activity_notes'] ?? null, $data);
            $locked->completed_at = now();
            $locked->archived_at = now();
            $this->transition($locked, $actor, 'completed', 'Service Completed / Archived');
        });
    }

    /** @param array<string, mixed> $input */
    public function cancel(BunkerService $service, array $input): BunkerService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), ['cancellation_reason' => ['required', 'string', 'max:5000']], $this->messages())->validate();

        return $this->change($service, function (BunkerService $locked, User $actor) use ($data): void {
            $this->ensureState($locked, ['draft', 'ready_to_apply', 'under_review', 'revision_required', 'approved', 'bunker_scheduled']);
            $locked->cancellation_reason = $data['cancellation_reason'];
            $locked->archived_at = now();
            $this->transition($locked, $actor, 'cancelled', 'Service Cancelled', $data['cancellation_reason']);
        });
    }

    private function ensureApproval(BunkerService $service): void
    {
        $document = $service->latestDocument('approval_document');
        if ($document === null || ! Storage::disk('local')->exists($document->file_path)) {
            throw ValidationException::withMessages(['state' => 'Dokumen persetujuan tidak tersedia.']);
        }
    }

    /** @return array<string, array<int, mixed>> */
    private function scheduleRules(bool $required): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            'supplier' => [$presence, 'string', 'max:255'],
            'bunker_location' => [$presence, 'string', 'max:255'],
            'scheduled_at' => [$presence, 'date_format:Y-m-d\TH:i'],
            'quantity' => [$presence, 'numeric', 'gt:0', 'max:999999999999.999', 'decimal:0,3'],
            'unit' => [$presence, Rule::in(array_keys(BunkerService::UNITS))],
            'unit_other' => ['required_if:unit,other', 'nullable', 'string', 'max:50'],
        ];
    }

    private function actor(): User
    {
        Gate::authorize('manage-services');

        return User::query()->findOrFail(auth()->id());
    }

    /** @param list<string> $allowed */
    private function ensureState(BunkerService $service, array $allowed): void
    {
        if (! in_array($service->status, $allowed, true)) {
            throw ValidationException::withMessages(['state' => 'Tindakan tidak tersedia untuk status '.$service->statusLabel().'. Muat ulang halaman.']);
        }
    }

    /** @param Closure(BunkerService, User, Collection<int, string>): void $action */
    private function change(BunkerService $service, Closure $action): BunkerService
    {
        $actor = $this->actor();

        return $this->atomic(function (Collection $paths) use ($service, $actor, $action): BunkerService {
            $vessel = Vessel::query()->lockForUpdate()->findOrFail($service->vessel_id);

            $locked = BunkerService::query()->lockForUpdate()->findOrFail($service->id);
            Gate::authorize('work', $locked);
            $locked->setRelation('vessel', $vessel);
            $action($locked, $actor, $paths);

            return $locked->refresh();
        });
    }

    /** @param Closure(Collection<int, string>): BunkerService $action */
    private function atomic(Closure $action): BunkerService
    {
        $paths = new Collection;
        try {
            return DB::transaction(function () use ($action, $paths): BunkerService {
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
                new UploadSize((int) config('bunker.document_max_kb')),
            ];
        }

        return Validator::make(['documents' => $documents], $rules, $this->messages())->validate()['documents'];
    }

    /** @param array<string, UploadedFile|null> $documents
     * @param  Collection<int, string>  $paths
     */
    private function storeUploads(BunkerService $service, array $documents, User $actor, Collection $paths): void
    {
        foreach ($documents as $type => $file) {
            if ($file === null) {
                continue;
            }
            $path = app(ImageOptimizer::class)->store($file, 'bunker/'.$service->id);
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
    private function copyReference(BunkerService $service, VesselDocument $document, string $type, User $actor, Collection $paths): void
    {
        $path = 'bunker/'.$service->id.'/'.Str::uuid().'.'.pathinfo($document->file_path, PATHINFO_EXTENSION);
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
    private function transition(BunkerService $service, User $actor, string $to, string $event, ?string $notes = null, array $metadata = []): void
    {
        $from = $service->status;
        $service->status = $to;
        $service->save();
        $this->history($service, $actor, $from, $event, $notes, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    private function history(BunkerService $service, User $actor, ?string $from, string $event, ?string $notes = null, array $metadata = []): void
    {
        $service->histories()->create([
            'from_status' => $from, 'to_status' => $service->status, 'event' => $event,
            'changed_by' => $actor->id, 'notes' => $notes, 'metadata' => $metadata,
        ]);
    }

    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, config('bunker.timezone'))->utc();
    }

    private function localNow(): string
    {
        return now(config('bunker.timezone'))->format('Y-m-d H:i');
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
