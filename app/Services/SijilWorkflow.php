<?php

namespace App\Services;

use App\Models\Crew;
use App\Models\SijilDocument;
use App\Models\SijilService;
use App\Models\SijilServiceCrew;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselCrew;
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

class SijilWorkflow
{
    public function __construct(private SijilReadinessService $readiness) {}

    /** @param array<string, mixed> $input
     * @param  array<int, array<string, mixed>>  $crews
     * @param  array<string, UploadedFile|null>  $documents
     * @param  array<int, array<string, UploadedFile|null>>  $crewDocuments
     */
    public function saveDraft(?SijilService $existing, array $input, array $crews = [], array $documents = [], array $crewDocuments = [], bool $applyImmediately = false): SijilService
    {
        $actor = $this->actor();
        $input = app(ServiceAssignment::class)->prepare($existing, $input);
        $data = Validator::make($this->normalize($input), [
            'vessel_id' => ['required', 'integer', 'exists:vessels,id'],
            'process_type' => ['required', Rule::in(array_keys(SijilService::PROCESS_TYPES))],
            'assigned_staff_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        Validator::make(['crews' => $crews], ['crews' => ['array', 'max:100'], 'crews.*' => ['array']])->validate();
        $rows = Validator::make(['crews' => array_map(fn (array $row): array => $this->normalize($row), $crews)], [
            'crews' => ['array', 'max:100'],
            'crews.*' => ['array:id,crew_id,full_name,rank,seafarer_book_number,competency_certificate_number,action_type,notes'],
            'crews.*.id' => ['nullable', 'integer'],
            'crews.*.crew_id' => ['nullable', 'integer', 'exists:crews,id'],
            'crews.*.full_name' => ['nullable', 'string', 'max:255'],
            'crews.*.rank' => ['nullable', 'string', 'max:255'],
            'crews.*.seafarer_book_number' => ['nullable', 'string', 'max:255'],
            'crews.*.competency_certificate_number' => ['nullable', 'string', 'max:255'],
            'crews.*.action_type' => ['required', Rule::in(array_keys(SijilService::ACTIONS))],
            'crews.*.notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate()['crews'];
        $uploads = $this->validateUploads($documents, array_keys(SijilDocument::APPLICATION_TYPES + ['revision_attachment' => 'Revision']));
        Validator::make(['crewDocuments' => $crewDocuments], ['crewDocuments' => ['array'], 'crewDocuments.*' => ['array']])->validate();
        $crewUploads = [];
        foreach ($crewDocuments as $index => $files) {
            if (! array_key_exists($index, $rows)) {
                throw ValidationException::withMessages(['crewDocuments' => 'Lampiran awak kapal tidak memiliki baris awak yang sesuai.']);
            }
            try {
                $crewUploads[$index] = $this->validateUploads($files, array_keys(SijilDocument::CREW_TYPES));
            } catch (ValidationException $exception) {
                $errors = [];
                foreach ($exception->errors() as $key => $messages) {
                    $errors[str_replace('documents', 'crewDocuments.'.$index, $key)] = $messages;
                }
                throw ValidationException::withMessages($errors);
            }
        }

        return $this->atomic(function (Collection $paths) use ($existing, $data, $rows, $uploads, $crewUploads, $actor, $applyImmediately): SijilService {
            $vessel = Vessel::query()->whereKey($data['vessel_id'])->lockForUpdate()->firstOrFail();
            $service = $existing ? SijilService::lockForUpdate()->findOrFail($existing->id) : new SijilService;
            $data = app(ServiceAssignment::class)->prepare($service, $data);
            $new = ! $service->exists;
            if (! $new) {
                $this->ensureState($service, ['draft', 'revision_required']);
                if ($service->vessel_id !== $vessel->id || ($service->submitted_at !== null && $service->process_type !== $data['process_type'])) {
                    throw ValidationException::withMessages(['state' => 'Kapal tidak dapat diganti; jenis proses tidak dapat diganti setelah pengajuan.']);
                }
            }
            $service->fill(array_replace($data, ['assigned_staff_id' => $data['assigned_staff_id']]));
            if ($new) {
                $service->fill(['service_number' => ServiceNumber::temporary('SJL'), 'created_by' => $actor->id, 'priority' => 'medium', 'status' => 'draft']);
            }
            $service->save();
            if ($new) {
                $service->update(['service_number' => ServiceNumber::forId('SJL', $service->id, config('sijil.timezone'))]);
            }
            $kept = [];
            $before = $service->crews()->get()->toArray();
            foreach ($rows as $index => $attributes) {
                $row = isset($attributes['id']) ? $service->crews()->whereKey($attributes['id'])->firstOrFail() : new SijilServiceCrew;
                if (in_array($row->id, $kept, true)) {
                    throw ValidationException::withMessages(['crews' => 'Baris awak kapal duplikat.']);
                }
                $crewId = isset($attributes['crew_id']) ? (int) $attributes['crew_id'] : null;
                if ($row->exists && ($row->crew_id !== $crewId || $row->action_type !== $attributes['action_type'])) {
                    throw ValidationException::withMessages(['crews' => 'Untuk mengganti identitas atau tindakan, hapus baris lalu tambahkan awak kapal baru.']);
                }
                if (! $row->exists && $crewId !== null) {
                    $assignment = VesselCrew::where('active_crew_id', $crewId)->where('vessel_id', $vessel->id)->first();
                    $row->vessel_crew_id = $assignment?->id;
                    $row->expected_revision = $assignment?->revision;
                }
                unset($attributes['id']);
                $row->fill($attributes);
                $row->seafarer_book_number = $row->seafarer_book_number ? mb_strtoupper($row->seafarer_book_number) : null;
                $service->crews()->save($row);
                $kept[] = $row->id;
                $this->storeUploads($service, $crewUploads[$index] ?? [], $actor, $paths, $row->id);
            }
            $service->crews()->whereNotIn('id', $kept)->update(['removed_at' => now()]);
            $this->storeUploads($service, $uploads, $actor, $paths);
            $this->history($service, $actor, $new ? null : $service->status, $new ? 'Service Created' : 'Data / Crew / Documents Updated', $data['notes'] ?? null, ['before_crews' => $before, 'after_crews' => $service->crews()->get()->toArray()]);

            if ($applyImmediately) {
                $service->setRelation('vessel', $vessel);
                $this->applyToVessel($service, $actor, ['effective_at' => today(config('sijil.timezone'))->toDateString(), 'notes' => $data['notes'] ?? null]);
            }

            return $service->refresh();
        });
    }

    public function ready(SijilService $service): SijilService
    {
        Gate::authorize('work', $service->fresh() ?? $service);

        return $this->change($service, function (SijilService $locked, User $actor): void {
            $this->ensureState($locked, ['draft']);
            $this->readiness->ensureReady($locked);
            $this->transition($locked, $actor, 'ready_to_apply', 'Documents Complete');
        });
    }

    /** @param array<string, mixed> $input */
    public function submit(SijilService $service, array $input): SijilService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), [
            'submitted_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.$this->localNow()],
            'submission_reference' => ['nullable', 'string', 'max:255'], 'authority' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();

        return $this->change($service, function (SijilService $locked, User $actor, Collection $paths) use ($data): void {
            $this->ensureState($locked, ['ready_to_apply', 'revision_required']);
            $this->readiness->ensureReady($locked);
            $date = $this->date($data['submitted_at']);
            $previous = $locked->resubmitted_at ?? $locked->submitted_at;
            if ($previous && $date->lt($previous)) {
                throw ValidationException::withMessages(['submitted_at' => 'Pengajuan ulang tidak boleh sebelum pengajuan sebelumnya.']);
            }
            foreach ($locked->crews as $row) {
                foreach (array_keys(SijilDocument::CREW_TYPES) as $type) {
                    $source = $this->readiness->crewDocument($row, $type);
                    if ($source && $source->sijil_service_id !== $locked->id) {
                        $path = 'sijil/'.$locked->id.'/'.Str::uuid().'.'.pathinfo($source->file_path, PATHINFO_EXTENSION);
                        $this->copyFile($source->file_path, $path, $paths);
                        $locked->documents()->create(['sijil_service_crew_id' => $row->id, 'document_type' => $type, 'file_path' => $path, 'original_filename' => $source->original_filename, 'uploaded_by' => $actor->id, 'uploaded_at' => now(), 'metadata' => ['source_document_id' => $source->id]]);
                    }
                }
            }
            foreach ($this->readiness->check($locked)['vessel_checks'] as $check) {
                if ($check['complete'] && $check['document'] !== null) {
                    $this->copyReference($locked, $check['document'], 'vessel_reference', $actor, $paths);
                }
            }
            $resubmitted = $locked->status === 'revision_required';
            $locked->fill([$resubmitted ? 'resubmitted_at' : 'submitted_at' => $date, 'submission_reference' => $data['submission_reference'] ?? $locked->submission_reference, 'authority' => $data['authority'] ?? $locked->authority]);
            $this->transition($locked, $actor, 'under_review', $resubmitted ? 'Resubmitted' : 'Submitted', $data['notes'] ?? null, $data);
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function recordReview(SijilService $service, array $input, array $documents = []): SijilService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), [
            'result' => ['required', Rule::in(['revision_required', 'rejected', 'approved'])],
            'revision_notes' => ['exclude_unless:result,revision_required', 'required', 'string', 'max:5000'],
            'rejection_reason' => ['exclude_unless:result,rejected', 'required', 'string', 'max:5000'],
            'result_reference' => ['exclude_unless:result,approved', 'nullable', 'string', 'max:255'],
            'issued_at' => ['exclude_unless:result,approved', 'required', 'date_format:Y-m-d', 'before_or_equal:'.today(config('sijil.timezone'))->toDateString()],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        $type = match ($data['result']) {
            'revision_required' => 'revision_attachment', 'rejected' => 'rejection_attachment', default => 'official_result'
        };
        $uploads = $this->validateUploads($documents, [$type], $data['result'] === 'approved' && config('sijil.official_result_required') ? [$type] : []);

        return $this->change($service, function (SijilService $locked, User $actor, Collection $paths) use ($data, $uploads): void {
            $this->ensureState($locked, ['under_review']);
            $result = $data['result'];
            if ($result === 'approved') {
                $submitted = ($locked->resubmitted_at ?? $locked->submitted_at)?->timezone(config('sijil.timezone'))->toDateString();
                if ($submitted === null || $data['issued_at'] < $submitted) {
                    throw ValidationException::withMessages(['issued_at' => 'Tanggal hasil tidak boleh sebelum pengajuan terakhir.']);
                }
                $locked->fill(['result_reference' => $data['result_reference'] ?? null, 'issued_at' => $data['issued_at']]);
            } elseif ($result === 'revision_required') {
                $locked->revision_notes = $data['revision_notes'];
            } else {
                $locked->rejection_reason = $data['rejection_reason'];
                $locked->archived_at = now();
            }
            $this->storeUploads($locked, $uploads, $actor, $paths);
            $this->transition($locked, $actor, $result === 'approved' ? 'ready_to_update_crew' : $result, $result === 'approved' ? 'Sijil Issued / Recorded' : SijilService::STATUSES[$result], $data['revision_notes'] ?? $data['rejection_reason'] ?? $data['notes'] ?? null, $data);
        });
    }

    /** @param array<string, mixed> $input */
    public function applyCrewChanges(SijilService $service, array $input): SijilService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), [
            'effective_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.today(config('sijil.timezone'))->toDateString()],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();

        return $this->change($service, function (SijilService $locked, User $actor) use ($data): void {
            $this->ensureState($locked, ['ready_to_update_crew']);
            $result = $locked->latestDocument('official_result');
            if (config('sijil.official_result_required') && ($result === null || ! Storage::disk('local')->exists($result->file_path))) {
                throw ValidationException::withMessages(['state' => 'Dokumen hasil sijil tidak tersedia.']);
            }
            $this->applyToVessel($locked, $actor, $data);
        });
    }

    /** @param array{effective_at: string, notes?: string|null} $data */
    private function applyToVessel(SijilService $locked, User $actor, array $data): void
    {
        $locked->load('crews');
        Crew::whereIn('id', $locked->crews->pluck('crew_id')->filter())->orderBy('id')->lockForUpdate()->get();
        $this->readiness->ensureReady($locked);
        foreach ($locked->crews as $row) {
            $crew = $row->crew_id ? Crew::findOrFail($row->crew_id) : new Crew;
            $assignment = $crew->exists ? VesselCrew::where('active_crew_id', $crew->id)->lockForUpdate()->first() : null;
            $latest = $crew->exists ? VesselCrew::where('crew_id', $crew->id)->orderByDesc('last_changed_at')->first() : null;
            if ($latest && $data['effective_at'] < $latest->last_changed_at->toDateString()) {
                throw ValidationException::withMessages(['effective_at' => 'Tanggal efektif tidak boleh sebelum perubahan penempatan terakhir: '.$row->full_name]);
            }
            $row->before_snapshot = $assignment?->toArray();
            if ($row->action_type !== 'sign_off') {
                $crew->fill($row->only(['full_name', 'seafarer_book_number', 'competency_certificate_number']))->save();
            }
            if ($row->action_type === 'sign_on') {
                $assignment = VesselCrew::create($row->only(['full_name', 'rank', 'seafarer_book_number', 'competency_certificate_number']) + [
                    'vessel_id' => $locked->vessel_id, 'crew_id' => $crew->id, 'active_crew_id' => $crew->id,
                    'sign_on_at' => $data['effective_at'], 'last_changed_at' => $data['effective_at'], 'sign_on_service_id' => $locked->id, 'revision' => 1,
                ]);
            } elseif ($assignment !== null) {
                if ($row->action_type === 'sign_off') {
                    $assignment->fill(['active_crew_id' => null, 'sign_off_at' => $data['effective_at'], 'sign_off_service_id' => $locked->id]);
                } else {
                    $assignment->fill($row->only(['full_name', 'rank', 'seafarer_book_number', 'competency_certificate_number']));
                }
                $assignment->last_changed_at = CarbonImmutable::parse($data['effective_at']);
                $assignment->revision++;
                $assignment->save();
            }
            $row->crew_id = $crew->id;
            $row->vessel_crew_id = $assignment?->id;
            $row->after_snapshot = $assignment?->refresh()->toArray();
            $row->save();
            $row->documents()->update(['crew_id' => $crew->id]);
        }
        $locked->fill(['effective_at' => $data['effective_at'], 'completed_at' => now(), 'completed_by' => $actor->id, 'archived_at' => now()]);
        $this->transition($locked, $actor, 'completed', 'Crew Changes Applied / Service Completed', $data['notes'] ?? null, ['effective_at' => $data['effective_at']]);
    }

    /** @param array<string, mixed> $input */
    public function cancel(SijilService $service, array $input): SijilService
    {
        Gate::authorize('work', $service->fresh() ?? $service);
        $this->actor();
        $data = Validator::make($this->normalize($input), ['cancellation_reason' => ['required', 'string', 'max:5000']], $this->messages())->validate();

        return $this->change($service, function (SijilService $locked, User $actor) use ($data): void {
            $this->ensureState($locked, SijilService::ACTIVE);
            $locked->cancellation_reason = $data['cancellation_reason'];
            $locked->archived_at = now();
            $this->transition($locked, $actor, 'cancelled', 'Service Cancelled', $data['cancellation_reason']);
        });
    }

    private function actor(): User
    {
        Gate::authorize('manage-services');

        return User::query()->findOrFail(auth()->id());
    }

    /** @param list<string> $allowed */
    private function ensureState(SijilService $service, array $allowed): void
    {
        if (! in_array($service->status, $allowed, true)) {
            throw ValidationException::withMessages(['state' => 'Tindakan tidak tersedia untuk status '.$service->statusLabel().'. Muat ulang halaman.']);
        }
    }

    /** @param Closure(SijilService, User, Collection<int, string>): void $action */
    private function change(SijilService $service, Closure $action): SijilService
    {
        $actor = $this->actor();

        return $this->atomic(function (Collection $paths) use ($service, $actor, $action): SijilService {
            $vessel = Vessel::query()->lockForUpdate()->findOrFail($service->vessel_id);

            $locked = SijilService::query()->lockForUpdate()->findOrFail($service->id);
            Gate::authorize('work', $locked);
            $locked->setRelation('vessel', $vessel);
            $action($locked, $actor, $paths);

            return $locked->refresh();
        });
    }

    /** @param Closure(Collection<int, string>): SijilService $action */
    private function atomic(Closure $action): SijilService
    {
        $paths = new Collection;
        try {
            return DB::transaction(function () use ($action, $paths): SijilService {
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
                new UploadSize((int) config('sijil.document_max_kb')),
            ];
        }

        return Validator::make(['documents' => $documents], $rules, $this->messages())->validate()['documents'];
    }

    /** @param array<string, UploadedFile|null> $documents
     * @param  Collection<int, string>  $paths
     */
    private function storeUploads(SijilService $service, array $documents, User $actor, Collection $paths, ?int $serviceCrewId = null): void
    {
        foreach ($documents as $type => $file) {
            if ($file === null) {
                continue;
            }
            $path = app(ImageOptimizer::class)->store($file, 'sijil/'.$service->id);
            if ($path === false) {
                throw new RuntimeException('File layanan gagal disimpan.');
            }
            $paths->push($path);
            $service->documents()->create([
                'sijil_service_crew_id' => $serviceCrewId, 'document_type' => $type, 'file_path' => $path,
                'original_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                'uploaded_by' => $actor->id, 'uploaded_at' => now(),
            ]);
        }
        $service->unsetRelation('documents');
    }

    /** @param Collection<int, string> $paths */
    private function copyReference(SijilService $service, VesselDocument $document, string $type, User $actor, Collection $paths): void
    {
        $path = 'sijil/'.$service->id.'/'.Str::uuid().'.'.pathinfo($document->file_path, PATHINFO_EXTENSION);
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
    private function transition(SijilService $service, User $actor, string $to, string $event, ?string $notes = null, array $metadata = []): void
    {
        $from = $service->status;
        $service->status = $to;
        $service->save();
        $this->history($service, $actor, $from, $event, $notes, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    private function history(SijilService $service, User $actor, ?string $from, string $event, ?string $notes = null, array $metadata = []): void
    {
        $service->histories()->create([
            'from_status' => $from, 'to_status' => $service->status, 'event' => $event,
            'changed_by' => $actor->id, 'notes' => $notes, 'metadata' => $metadata,
        ]);
    }

    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, config('sijil.timezone'))->utc();
    }

    private function localNow(): string
    {
        return now(config('sijil.timezone'))->format('Y-m-d H:i');
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
