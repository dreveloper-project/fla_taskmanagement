<?php

namespace App\Services;

use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
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

class SpbWorkflow
{
    public function __construct(private SpbReadinessService $readiness) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function saveDraft(?SpbService $existing, array $input, array $documents): SpbService
    {
        $actor = $this->actor();
        $input = app(ServiceAssignment::class)->prepare($existing, $input);
        $data = Validator::make($this->normalize($input), [
            'vessel_id' => ['required', 'integer', Rule::exists('vessels', 'id')],
            'assigned_staff_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'total_passengers' => ['nullable', 'integer', 'between:0,1000000'],
            'passengers_none' => ['sometimes', 'boolean'],
            'cargo_status' => ['required', Rule::in(array_keys(SpbService::CARGO_STATUSES))],
            'cargo_description' => ['required_if:cargo_status,carrying_cargo', 'nullable', 'string', 'max:5000'],
            'cargo_type' => ['nullable', 'string', 'max:255'],
            'cargo_quantity' => ['nullable', 'numeric', 'between:0,999999999999.999', 'decimal:0,3'],
            'cargo_unit' => ['nullable', 'string', 'max:50'],
            'port_cargo_activity_applicable' => ['required', 'boolean'],
            'departure_port' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'estimated_departure_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'billing_code_labuh' => ['nullable', 'string', 'max:255'],
            'billing_code_rambu' => ['nullable', 'string', 'max:255'],
            'billing_code_mastercable' => ['nullable', 'string', 'max:255'],
            'arrival_spb_enabled' => ['sometimes', 'boolean'],
            'arrival_billing_code_labuh' => ['exclude_unless:arrival_spb_enabled,true', 'nullable', 'string', 'max:255'],
            'arrival_billing_code_rambu' => ['exclude_unless:arrival_spb_enabled,true', 'nullable', 'string', 'max:255'],
            'arrival_billing_code_mastercable' => ['exclude_unless:arrival_spb_enabled,true', 'nullable', 'string', 'max:255'],
        ], $this->messages())->validate();
        if (! ($data['arrival_spb_enabled'] ?? false)) {
            $documents = array_diff_key($documents, SpbDocument::ARRIVAL_APPLICATION_TYPES);
        }
        $uploads = $this->validateUploads(
            $documents,
            array_values(array_diff(array_keys(SpbDocument::APPLICATION_TYPES + SpbDocument::ARRIVAL_APPLICATION_TYPES), ['spb_request_letter', 'payment_proof', 'pnbp_proof'])),
            $existing?->exists ? [] : ['spb_file'],
        );

        return $this->atomic(function (Collection $paths) use ($actor, $existing, $data, $uploads): SpbService {
            $vessel = Vessel::query()->whereKey($data['vessel_id'])->lockForUpdate()->firstOrFail();
            $data['total_crew'] = $vessel->currentCrewCount();
            if ($vessel->vessel_type === 'passenger' && ($data['total_passengers'] ?? null) === null) {
                throw ValidationException::withMessages(['total_passengers' => 'Total Passenger wajib diisi untuk kapal penumpang.']);
            }
            $data['total_passengers'] = $data['total_passengers'] ?? 0;
            $data['passengers_none'] = (bool) ($data['passengers_none'] ?? false);
            if ($data['passengers_none'] && ((int) $data['total_passengers'] !== 0 || ($uploads['passenger_manifest'] ?? null) !== null)) {
                throw ValidationException::withMessages(['passengers_none' => 'Penumpang nihil hanya berlaku untuk jumlah penumpang 0 tanpa unggahan Passenger Manifest baru.']);
            }
            if ($data['cargo_status'] !== 'carrying_cargo') {
                foreach (['cargo_description', 'cargo_type', 'cargo_quantity', 'cargo_unit'] as $field) {
                    $data[$field] = null;
                }
            }
            $spb = $existing ? SpbService::query()->lockForUpdate()->findOrFail($existing->id) : new SpbService;
            $data = app(ServiceAssignment::class)->prepare($spb, $data);
            if ($spb->exists) {
                $this->ensureState($spb, ['draft', 'revision_required', 'completed']);
                if ($spb->vessel_id !== $vessel->id) {
                    throw ValidationException::withMessages(['vessel_id' => 'Kapal pada pengajuan yang sudah dibuat tidak dapat diganti. Buat pengajuan baru.']);
                }
            }
            $before = $spb->exists ? $spb->only(array_keys($data)) : null;
            $isNew = ! $spb->exists;
            $previousStatus = $isNew ? null : $spb->status;
            $spb->fill(array_replace($data, [
                'estimated_departure_at' => $this->date($data['estimated_departure_at']),
                'revision_ready_at' => null,
            ]));
            if ($isNew) {
                $spb->fill([
                    'service_number' => ServiceNumber::temporary('SPB'), 'service_type' => 'spb',
                    'created_by' => $actor->id, 'priority' => 'medium',
                    'sailing_result' => 'pending',
                ]);
            }
            $spb->save();
            if ($isNew) {
                $spb->update(['service_number' => ServiceNumber::forId('SPB', $spb->id, config('spb.timezone'))]);
            }
            $this->storeUploads($spb, $uploads, $actor, $paths);
            if ($previousStatus !== 'completed') {
                $spb->load('documents');
                $spbFile = $spb->latestDocument('spb_file');
                if ($spbFile === null || ! Storage::disk('local')->exists($spbFile->file_path)) {
                    throw ValidationException::withMessages(['documents.spb_file' => 'File SPB wajib tersedia untuk menyelesaikan layanan.']);
                }
                $spb->forceFill(['status' => 'completed', 'completed_at' => now(), 'archived_at' => now()])->save();
            }
            $this->history($spb, $actor, $previousStatus, $previousStatus === 'completed' ? 'Data / Documents Updated' : 'SPB Completed', $data['notes'] ?? null, ['before' => $before, 'after' => $data]);

            return $spb->refresh()->load(['vessel', 'documents']);
        });
    }

    public function ready(SpbService $spb): SpbService
    {
        Gate::authorize('work', $spb->fresh() ?? $spb);

        return $this->change($spb, function (SpbService $locked, User $actor): void {
            $this->ensureState($locked, ['draft', 'revision_required']);
            $this->readiness->ensureReady($locked);
            $from = $locked->status;
            if ($from === 'revision_required') {
                $locked->update(['revision_ready_at' => now()]);
                $this->history($locked, $actor, $from, 'Ready to Resubmit');
            } else {
                $locked->update(['status' => 'ready_to_apply']);
                $this->history($locked, $actor, $from, 'Documents Completed');
            }
        });
    }

    /** @param array<string, mixed> $input */
    public function apply(SpbService $spb, array $input): SpbService
    {
        Gate::authorize('work', $spb->fresh() ?? $spb);
        $data = Validator::make($this->normalize($input), [
            'submitted_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.$this->localNow()],
            'inaportnet_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();

        return $this->change($spb, function (SpbService $locked, User $actor, Collection $paths) use ($data): void {
            $this->ensureState($locked, ['ready_to_apply', 'revision_required']);
            if ($locked->status === 'revision_required' && $locked->revision_ready_at === null) {
                throw ValidationException::withMessages(['state' => 'Jalankan Ready to Resubmit setelah revisi selesai.']);
            }
            $this->readiness->ensureReady($locked);
            $submitted = $this->date($data['submitted_at']);
            if ($locked->submitted_at !== null && $submitted->lt($locked->resubmitted_at ?? $locked->submitted_at)) {
                throw ValidationException::withMessages(['submitted_at' => 'Tanggal pengajuan ulang tidak boleh sebelum pengajuan sebelumnya.']);
            }
            $from = $locked->status;
            $this->snapshotVesselDocuments($locked, $actor, $paths);
            $locked->fill([
                'inaportnet_reference' => $data['inaportnet_reference'] ?? null,
                $from === 'revision_required' ? 'resubmitted_at' : 'submitted_at' => $submitted,
                'status' => 'applied', 'revision_ready_at' => null,
            ])->save();
            $this->history($locked, $actor, $from, $from === 'revision_required' ? 'Resubmitted to Inaportnet' : 'Submitted to Inaportnet', $data['notes'] ?? null, $data);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function recordResult(SpbService $spb, array $input, array $documents): SpbService
    {
        Gate::authorize('work', $spb->fresh() ?? $spb);
        $data = Validator::make($this->normalize($input), [
            'result' => ['required', Rule::in(['revision_required', 'rejected', 'approved'])],
            'revision_notes' => ['required_if:result,revision_required', 'nullable', 'string', 'max:5000'],
            'revision_items' => ['required_if:result,revision_required', 'nullable', 'string', 'max:5000'],
            'rejection_reason' => ['required_if:result,rejected', 'nullable', 'string', 'max:5000'],
            'spb_number' => ['nullable', 'string', 'max:255'],
            'spb_issued_at' => ['required_if:result,approved', 'nullable', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.$this->localNow()],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        $type = match ($data['result']) {
            'approved' => 'issued_spb', 'rejected' => 'rejection_attachment', default => 'revision_attachment',
        };
        $allowed = $data['result'] === 'approved' ? [$type, 'lk3_nakhoda'] : [$type];
        $uploads = $this->validateUploads($documents, $allowed, $data['result'] === 'approved' ? [$type] : []);

        return $this->change($spb, function (SpbService $locked, User $actor, Collection $paths) use ($data, $uploads): void {
            $this->ensureState($locked, ['ready_to_apply', 'applied', 'revision_required']);
            $from = $locked->status;
            if ($from === 'revision_required' && $locked->revision_ready_at === null) {
                throw ValidationException::withMessages(['state' => 'Lengkapi revisi dan jalankan Ready to Resubmit terlebih dahulu.']);
            }
            if ($from !== 'applied') {
                $this->readiness->ensureReady($locked);
                $this->snapshotVesselDocuments($locked, $actor, $paths);
            }
            if ($data['result'] === 'approved') {
                $issued = $this->date($data['spb_issued_at']);
                $submittedAt = $locked->resubmitted_at ?? $locked->submitted_at;
                if ($submittedAt !== null && $issued->lt($submittedAt)) {
                    throw ValidationException::withMessages(['spb_issued_at' => 'Tanggal terbit tidak boleh sebelum pengajuan terakhir.']);
                }
                if ($locked->vessel->spbServices()->whereIn('status', ['ready_to_sail', 'sailing'])->whereKeyNot($locked->id)->exists()) {
                    throw ValidationException::withMessages(['state' => 'Kapal sudah memiliki SPB yang siap berlayar dan belum selesai.']);
                }
                $this->storeUploads($locked, $uploads, $actor, $paths);
                $locked->fill(['spb_number' => $data['spb_number'] ?? null, 'spb_issued_at' => $issued, 'ready_to_sail_at' => now(), 'status' => 'ready_to_sail', 'revision_ready_at' => null])->save();
                $this->history($locked, $actor, $from, 'SPB Issued · Ready To Sail', $data['notes'] ?? null, ['spb_number' => $data['spb_number'] ?? null, 'spb_issued_at' => $data['spb_issued_at']]);
            } elseif ($data['result'] === 'rejected') {
                $this->storeUploads($locked, $uploads, $actor, $paths);
                $locked->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'archived_at' => now(), 'revision_ready_at' => null]);
                $this->history($locked, $actor, $from, 'Rejected by Inaportnet', $data['rejection_reason']);
            } else {
                $this->storeUploads($locked, $uploads, $actor, $paths);
                $locked->update(['status' => 'revision_required', 'revision_notes' => $data['revision_notes'], 'revision_items' => $data['revision_items'], 'revision_ready_at' => null]);
                $this->history($locked, $actor, $from, 'Revision Required', $data['revision_notes'], ['revision_items' => $data['revision_items']]);
            }
        });
    }

    /** @param array<string, mixed> $input */
    public function depart(SpbService $spb, array $input): SpbService
    {
        Gate::authorize('work', $spb->fresh() ?? $spb);

        throw ValidationException::withMessages(['state' => 'Konfirmasi keberangkatan tidak lagi digunakan. Catat hasil SPB pada halaman layanan.']);
    }

    /** @param array<string, UploadedFile|null> $documents */
    public function restoreIssuedSpb(SpbService $spb, array $documents): SpbService
    {
        Gate::authorize('work', $spb->fresh() ?? $spb);
        $uploads = $this->validateUploads($documents, ['issued_spb'], ['issued_spb']);

        return $this->change($spb, function (SpbService $locked, User $actor, Collection $paths) use ($uploads): void {
            $this->ensureState($locked, ['ready_to_sail', 'sailing']);
            $locked->loadMissing('documents');
            $previous = $locked->latestDocument('issued_spb');
            if ($previous !== null && Storage::disk('local')->exists($previous->file_path)) {
                throw ValidationException::withMessages(['state' => 'File SPB resmi masih tersedia dan tidak perlu diunggah ulang.']);
            }
            $this->storeUploads($locked, $uploads, $actor, $paths);
            $this->history($locked, $actor, $locked->status, 'SPB resmi diunggah ulang', null, ['previous_document_id' => $previous?->id]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function finish(SpbService $spb, array $input, array $documents = []): SpbService
    {
        Gate::authorize('work', $spb->fresh() ?? $spb);
        $data = Validator::make($this->normalize($input), [
            'sailing_result' => ['required', Rule::in(['success', 'not_sailing', 'failed'])],
            'actual_departure_at' => ['exclude_unless:sailing_result,success', 'required', 'date_format:Y-m-d\TH:i'],
            'arrival_spb_number' => ['exclude_unless:sailing_result,success', 'nullable', 'string', 'max:255'],
            'failure_reason' => ['required_if:sailing_result,failed', 'nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], $this->messages())->validate();
        $allowed = match ($data['sailing_result']) {
            'success' => ['arrival_spb'],
            'failed' => ['sailing_failure_attachment'],
            default => [],
        };
        $uploads = $this->validateUploads($documents, $allowed);

        return $this->change($spb, function (SpbService $locked, User $actor, Collection $paths) use ($data, $uploads): void {
            $this->ensureState($locked, ['ready_to_sail', 'sailing']);
            $success = $data['sailing_result'] === 'success';
            if ($success) {
                $locked->loadMissing('documents');
                $issuedSpb = $locked->latestDocument('issued_spb');
                if ($issuedSpb === null || ! Storage::disk('local')->exists($issuedSpb->file_path)) {
                    throw ValidationException::withMessages(['state' => 'File SPB resmi tidak tersedia.']);
                }
            }
            $this->storeUploads($locked, $uploads, $actor, $paths);
            $from = $locked->status;
            $locked->forceFill([
                'status' => 'completed',
                'sailing_result' => $data['sailing_result'], 'failure_reason' => $data['sailing_result'] === 'failed' ? $data['failure_reason'] : null,
                'actual_departure_at' => $success ? $this->date($data['actual_departure_at']) : $locked->actual_departure_at,
                'arrival_spb_number' => $success ? ($data['arrival_spb_number'] ?? null) : null,
                'confirmed_by' => $actor->id, 'completed_at' => now(), 'archived_at' => now(),
            ])->save();
            $event = match ($data['sailing_result']) {
                'success' => 'Sail Completed',
                'not_sailing' => 'Not Sailing',
                'failed' => 'Fail to Sail',
            };
            $this->history($locked, $actor, $from, $event, $data['sailing_result'] === 'failed' ? $data['failure_reason'] : ($data['notes'] ?? null),
                ['notes' => $data['notes'] ?? null, 'actual_departure_at' => $data['actual_departure_at'] ?? null]);
        });
    }

    /** @param array<string, mixed> $input */
    public function confirmArrival(SpbService $spb, array $input): SpbService
    {
        Gate::authorize('work', $spb->fresh() ?? $spb);

        throw ValidationException::withMessages(['state' => 'Konfirmasi kedatangan tidak lagi digunakan. Catat hasil SPB pada halaman layanan.']);
    }

    private function actor(): User
    {
        Gate::authorize('manage-services');

        return User::query()->findOrFail(auth()->id());
    }

    /** @param list<string> $allowed */
    private function ensureState(SpbService $spb, array $allowed): void
    {
        if (! in_array($spb->status, $allowed, true)) {
            throw ValidationException::withMessages(['state' => 'Tindakan tidak tersedia untuk status '.$spb->statusLabel().'. Muat ulang halaman.']);
        }
    }

    /** @param Closure(SpbService, User, Collection<int, string>): void $action */
    private function change(SpbService $spb, Closure $action): SpbService
    {
        $actor = $this->actor();

        return $this->atomic(function (Collection $paths) use ($spb, $actor, $action): SpbService {
            $vessel = Vessel::query()->lockForUpdate()->findOrFail($spb->vessel_id);
            $locked = SpbService::query()->lockForUpdate()->findOrFail($spb->id);
            Gate::authorize('work', $locked);
            $locked->setRelation('vessel', $vessel);
            $action($locked, $actor, $paths);

            return $locked->refresh();
        });
    }

    /** @param Closure(Collection<int, string>): SpbService $action */
    private function atomic(Closure $action): SpbService
    {
        $paths = new Collection;
        try {
            return DB::transaction(function () use ($action, $paths): SpbService {
                $result = $action($paths);

                return $result;
            });
        } catch (Throwable $exception) {
            foreach ($paths as $path) {
                try {
                    if (! Storage::disk('local')->delete($path)) {
                        report(new RuntimeException('Gagal membersihkan file SPB: '.$path));
                    }
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            throw $exception;
        }
    }

    /**
     * @param  array<string, UploadedFile|null>  $documents
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     * @return array<string, UploadedFile|null>
     */
    private function validateUploads(array $documents, array $allowed, array $required = []): array
    {
        $rules = [];
        $rules['documents'] = $allowed === [] ? ['array', 'size:0'] : ['array:'.implode(',', $allowed)];
        foreach ($allowed as $type) {
            $rules['documents.'.$type] = [
                in_array($type, $required, true) ? 'required' : 'nullable',
                'file', 'mimes:pdf,jpg,jpeg,png,webp,gif,doc,docx', 'extensions:pdf,jpg,jpeg,png,webp,gif,doc,docx', new UploadSize((int) config('spb.document_max_kb')),
            ];
        }

        return Validator::make(['documents' => $documents], $rules, $this->messages())->validate()['documents'];
    }

    /**
     * @param  array<string, UploadedFile|null>  $documents
     * @param  Collection<int, string>  $paths
     */
    private function storeUploads(SpbService $spb, array $documents, User $actor, Collection $paths): void
    {
        foreach ($documents as $type => $file) {
            if ($file === null) {
                continue;
            }
            $path = app(ImageOptimizer::class)->store($file, 'spb/'.$spb->id);
            if ($path === false) {
                throw new RuntimeException('File SPB gagal disimpan.');
            }
            $paths->push($path);
            $spb->documents()->create([
                'document_type' => $type, 'file_path' => $path,
                'original_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                'uploaded_by' => $actor->id, 'uploaded_at' => now(),
            ]);
        }
        $spb->unsetRelation('documents');
    }

    /** @param Collection<int, string> $paths */
    private function snapshotVesselDocuments(SpbService $spb, User $actor, Collection $paths): void
    {
        foreach ($this->readiness->check($spb)['vessel_checks'] as $check) {
            $document = $check['document'];
            if (! $check['complete'] || $document === null) {
                continue;
            }
            $path = 'spb/'.$spb->id.'/'.Str::uuid().'.'.pathinfo($document->file_path, PATHINFO_EXTENSION);
            if (! Storage::disk('local')->copy($document->file_path, $path)) {
                throw new RuntimeException('Arsip dokumen vessel gagal disimpan.');
            }
            $paths->push($path);
            $spb->documents()->create([
                'document_type' => 'vessel_reference', 'source_document_type' => $document->document_type,
                'file_path' => $path, 'original_filename' => $document->original_filename,
                'uploaded_by' => $actor->id, 'uploaded_at' => now(),
                'metadata' => ['vessel_document_id' => $document->id, 'issued_date' => $document->issued_date?->toDateString(), 'expired_date' => $document->expired_date?->toDateString(), 'equipment' => $document->equipment],
            ]);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function history(SpbService $spb, User $actor, ?string $from, string $event, ?string $notes = null, array $metadata = []): void
    {
        $spb->histories()->create(['event' => $event, 'from_status' => $from, 'to_status' => $spb->status, 'changed_by' => $actor->id, 'notes' => $notes, 'metadata' => $metadata]);
    }

    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, config('spb.timezone'))->utc();
    }

    private function localNow(): string
    {
        return now(config('spb.timezone'))->format('Y-m-d H:i');
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
            'required' => ':attribute wajib diisi.',
            'required_if' => ':attribute wajib diisi untuk hasil ini.',
            'exists' => ':attribute harus terdaftar dan dapat dipilih.',
            'in' => ':attribute tidak valid.',
            'date_format' => ':attribute harus berupa tanggal dan jam yang valid.',
            'before_or_equal' => ':attribute tidak boleh melewati :date.',
            'mimes' => 'File harus PDF, JPG, JPEG, PNG, WebP, GIF, DOC, atau DOCX.',
            'extensions' => 'Ekstensi file harus PDF, JPG, JPEG, PNG, WebP, GIF, DOC, atau DOCX.',
            'max' => ':attribute melebihi batas :max.',
        ];
    }
}
