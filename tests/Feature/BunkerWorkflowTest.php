<?php

use App\Models\BunkerService;
use App\Models\BunkerStatusHistory;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\BunkerReadinessService;
use App\Services\BunkerWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../BunkerFixtures.php';

beforeEach(function () {
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 7)->setTime(16, 0));
    Storage::fake('local');
});

test('bunker draft permits missing details and defaults pic to actor', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $vessel = Vessel::factory()->create(['client_id' => null]);
    $service = app(BunkerWorkflow::class)->saveDraft(null, ['vessel_id' => $vessel->id, 'priority' => 'medium', 'status' => 'completed', 'client_id' => 99]);
    expect($service->status)->toBe('draft')->and($service->assigned_staff_id)->toBe($staff->id)->and($service->created_by)->toBe($staff->id)
        ->and($service->client_id)->toBeNull()->and($service->service_number)->toBe('BKR-2026-0001');
    $readiness = app(BunkerReadinessService::class)->check($service);
    expect($readiness['complete'])->toBeFalse()->and($readiness['missing'])->toContain('Pemasok belum diisi.', 'Jadwal Bunker belum diisi.', 'Surat Permohonan Izin Bunker');
    expect(fn () => app(BunkerWorkflow::class)->ready($service))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('draft');
});

test('bunker accepts every vessel type without associating a client', function (string $type) {
    $service = bunkerDraft();
    $vessel = $service->vessel;
    $vessel->update(['vessel_type' => $type]);
    $second = app(BunkerWorkflow::class)->saveDraft(null, bunkerInput($vessel));
    expect($second->client_id)->toBeNull()->and($second->service_number)->not->toBe($service->service_number);
})->with(array_keys(Vessel::TYPES));

test('invalid bunker inputs fail without data writes', function (string $field, mixed $value) {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $vessel = Vessel::factory()->create();
    try {
        app(BunkerWorkflow::class)->saveDraft(null, array_replace(bunkerInput($vessel), [$field => $value]));
        $this->fail('Expected validation failure');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('bunker_services', 0);
})->with([
    ['vessel_id', null], ['vessel_id', 9999], ['supplier', []], ['supplier', str_repeat('x', 256)],
    ['bunker_location', []], ['scheduled_at', 'invalid'], ['scheduled_at', '2026-02-30T12:00'],
    ['quantity', 0], ['quantity', -1], ['quantity', '1.0001'], ['quantity', 'abc'], ['quantity', '1000000000000'],
    ['unit', 'invalid'], ['priority', 'invalid'], ['assigned_staff_id', 9999],
]);

test('other unit requires its name and preserves precision', function () {
    $service = bunkerDraft();
    $workflow = app(BunkerWorkflow::class);
    expect(fn () => $workflow->saveDraft($service, array_replace(bunkerInput($service->vessel), ['unit' => 'other'])))->toThrow(ValidationException::class);
    $updated = $workflow->saveDraft($service, array_replace(bunkerInput($service->vessel), ['unit' => 'other', 'unit_other' => 'Drum']));
    expect($updated->quantity)->toBe('1500.125')->and($updated->unitLabel())->toBe('Drum');
});

test('readiness checks configurable vessel documents validity and physical service files', function () {
    $service = bunkerDraft();
    config(['bunker.vessel_documents' => [['label' => 'Surat Laut', 'types' => ['surat_laut'], 'required' => true, 'must_be_valid' => true]]]);
    $workflow = app(BunkerWorkflow::class);
    expect(fn () => $workflow->ready($service))->toThrow(ValidationException::class);
    $doc = VesselDocument::factory()->for($service->vessel)->create(['document_type' => 'surat_laut', 'expired_date' => '2026-10-06']);
    Storage::disk('local')->put($doc->file_path, 'old file');
    expect(fn () => $workflow->ready($service))->toThrow(ValidationException::class);
    $doc->update(['expired_date' => '2027-10-07']);
    $service = $workflow->ready($service);
    expect($service->status)->toBe('ready_to_apply');
    Storage::disk('local')->delete($service->latestDocument('bunker_request')->file_path);
    expect(fn () => $workflow->submit($service, ['submitted_at' => '2026-10-06T09:00']))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('ready_to_apply');
});

test('submission snapshots vessel files and revisions preserve every document version and history', function () {
    $service = bunkerDraft();
    $doc = VesselDocument::factory()->for($service->vessel)->create(['document_type' => 'surat_ukur']);
    Storage::disk('local')->put($doc->file_path, 'vessel original');
    $workflow = app(BunkerWorkflow::class);
    $service = $workflow->submit($workflow->ready($service), ['submitted_at' => '2026-10-06T09:00']);
    $reference = $service->latestDocument('vessel_reference');
    Storage::disk('local')->delete($doc->file_path);
    Storage::disk('local')->assertExists($reference->file_path);
    $old = $service->latestDocument('application_letter')->file_path;
    $service = $workflow->recordReview($service, ['result' => 'revision_required', 'revision_notes' => 'Perbaiki permohonan'], ['revision_attachment' => bunkerPdf()]);
    $service = $workflow->saveDraft($service, bunkerInput($service->vessel), ['application_letter' => bunkerPdf('revised.pdf')]);
    $service = $workflow->submit($service, ['submitted_at' => '2026-10-07T08:00']);
    expect($service->status)->toBe('under_review')->and($service->resubmitted_at->format('H:i'))->toBe('00:00')
        ->and($service->documents()->where('document_type', 'application_letter')->count())->toBe(2);
    Storage::disk('local')->assertExists($old);
    expect($service->histories->pluck('event'))->toContain('Resubmitted', 'Revision Required');
});

test('revision and rejection require notes', function (string $result, string $field) {
    $service = bunkerSubmitted();
    try {
        app(BunkerWorkflow::class)->recordReview($service, ['result' => $result]);
        $this->fail('Expected notes validation');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect($service->fresh()->status)->toBe('under_review');
})->with([['revision_required', 'revision_notes'], ['rejected', 'rejection_reason']]);

test('rejected and cancelled services are terminal and remain archived', function (string $result) {
    $workflow = app(BunkerWorkflow::class);
    $service = bunkerSubmitted();
    $service = $result === 'rejected'
        ? $workflow->recordReview($service, ['result' => 'rejected', 'rejection_reason' => 'Ditolak instansi'])
        : $workflow->cancel($service, ['cancellation_reason' => 'Kegiatan dibatalkan']);
    expect($service->status)->toBe($result)->and($service->archived_at)->not->toBeNull();
    expect(fn () => $workflow->saveDraft($service, bunkerInput($service->vessel)))->toThrow(ValidationException::class);
    expect(fn () => $workflow->cancel($service, ['cancellation_reason' => 'Ulang']))->toThrow(ValidationException::class);
    expect($service->documents)->toHaveCount(5);
})->with(['rejected', 'cancelled']);

test('approval requires a real file and valid date after submission', function (string $case) {
    $service = bunkerSubmitted();
    $input = ['result' => 'approved', 'approved_at' => match ($case) {
        'early' => '2026-10-05T09:00', 'future' => '2026-10-08T09:00', 'missing_date' => '', default => '2026-10-07T09:00'
    }];
    expect(fn () => app(BunkerWorkflow::class)->recordReview($service, $input, $case === 'file' ? [] : ['approval_document' => bunkerPdf()]))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('under_review')->and($service->documents()->count())->toBe(5);
})->with(['file', 'early', 'future', 'missing_date']);

test('bunker scheduling and completion preserve planned data actual values approval and all files', function () {
    $service = bunkerScheduled();
    $workflow = app(BunkerWorkflow::class);
    $service = $workflow->schedule($service, array_replace(bunkerInput($service->vessel), ['bunker_location' => 'Dermaga 2', 'scheduled_at' => '2026-10-07T12:30']));
    $service = $workflow->complete($service, bunkerActual(), ['activity_proof' => bunkerPdf('activity.pdf'), 'payment_proof' => bunkerPdf('payment.pdf')]);
    expect($service->status)->toBe('completed')->and($service->quantity)->toBe('1500.125')->and($service->actual_quantity)->toBe('1400.250')
        ->and($service->approval_number)->toBe('PERMIT-001')->and($service->bunker_location)->toBe('Dermaga 2')
        ->and($service->completed_at)->not->toBeNull()->and($service->archived_at)->not->toBeNull();
    expect($service->histories->pluck('to_status')->all())->toBe(['draft', 'ready_to_apply', 'under_review', 'approved', 'bunker_scheduled', 'bunker_scheduled', 'bunker_completed', 'completed']);
    foreach ($service->documents as $document) {
        Storage::disk('local')->assertExists($document->file_path);
    }
    expect(fn () => $workflow->complete($service, bunkerActual()))->toThrow(ValidationException::class);
});

test('actual bunker validation protects date quantity and unit', function (string $field, mixed $value) {
    $service = bunkerScheduled();
    try {
        app(BunkerWorkflow::class)->complete($service, array_replace(bunkerActual(), [$field => $value]));
        $this->fail('Expected actual bunker validation');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect($service->fresh()->status)->toBe('bunker_scheduled');
})->with([['actual_bunker_at', '2026-10-06T08:00'], ['actual_bunker_at', '2026-10-08T12:00'], ['actual_quantity', 0], ['actual_quantity', '1.1234'], ['actual_unit', 'invalid']]);

test('backend rejects skipped workflow states', function (string $action) {
    $service = bunkerDraft();
    $workflow = app(BunkerWorkflow::class);
    expect(fn () => match ($action) {
        'submit' => $workflow->submit($service, ['submitted_at' => '2026-10-06T09:00']),
        'review' => $workflow->recordReview($service, ['result' => 'rejected', 'rejection_reason' => 'Reason']),
        'schedule' => $workflow->schedule($service, bunkerInput($service->vessel)),
        'complete' => $workflow->complete($service, bunkerActual()),
    })->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('draft')->and($service->histories()->count())->toBe(1);
})->with(['submit', 'review', 'schedule', 'complete']);

test('unsafe uploads and reserved document types are rejected', function (string $case) {
    $service = bunkerDraft();
    $documents = match ($case) {
        'mime' => ['other' => UploadedFile::fake()->create('test.pdf', 1, 'text/plain')],
        'extension' => ['other' => UploadedFile::fake()->create('test.php', 1, 'application/pdf')],
        'size' => ['other' => UploadedFile::fake()->create('test.pdf', 2049, 'application/pdf')],
        default => ['approval_document' => bunkerPdf()],
    };
    expect(fn () => app(BunkerWorkflow::class)->saveDraft($service, bunkerInput($service->vessel), $documents))->toThrow(ValidationException::class);
    expect($service->documents()->count())->toBe(2);
})->with(['mime', 'extension', 'size', 'reserved']);

test('failed completion rolls back status actual data history and newly uploaded files', function () {
    $service = bunkerScheduled();
    $files = Storage::disk('local')->allFiles();
    $historyCount = $service->histories()->count();
    BunkerStatusHistory::creating(function (BunkerStatusHistory $history): void {
        if ($history->to_status === 'completed') {
            throw new RuntimeException('History failure');
        }
    });
    try {
        expect(fn () => app(BunkerWorkflow::class)->complete($service, bunkerActual(), ['activity_proof' => bunkerPdf()]))->toThrow(RuntimeException::class);
        expect($service->fresh()->status)->toBe('bunker_scheduled')->and($service->fresh()->actual_quantity)->toBeNull()
            ->and($service->histories()->count())->toBe($historyCount);
        expect(Storage::disk('local')->allFiles())->toEqualCanonicalizing($files);
    } finally {
        BunkerStatusHistory::flushEventListeners();
    }
});

test('staff cannot change vessel or assign a non staff user', function () {
    $service = bunkerDraft();
    $other = Vessel::factory()->create();
    expect(fn () => app(BunkerWorkflow::class)->saveDraft($service, bunkerInput($other)))->toThrow(ValidationException::class);
    $external = User::factory()->create(['role' => 'external']);
    expect(fn () => app(BunkerWorkflow::class)->saveDraft($service, array_replace(bunkerInput($service->vessel), ['assigned_staff_id' => $external->id])))->toThrow(ValidationException::class);
    $this->actingAs($external);
    expect(fn () => app(BunkerWorkflow::class)->ready($service))->toThrow(AuthorizationException::class);
    expect(fn () => app(BunkerWorkflow::class)->saveDraft($service, bunkerInput($service->vessel)))->toThrow(AuthorizationException::class);
});

test('schedule rejects incomplete data and dates before approval', function () {
    $service = bunkerApproved();
    $workflow = app(BunkerWorkflow::class);
    try {
        $workflow->schedule($service, []);
        $this->fail('Expected schedule requirements');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKeys(['supplier', 'bunker_location', 'scheduled_at', 'quantity', 'unit']);
    }
    expect(fn () => $workflow->schedule($service, array_replace(bunkerInput($service->vessel), ['scheduled_at' => '2026-10-07T08:59'])))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('approved');
});

test('resubmission cannot predate the previous submission', function () {
    $workflow = app(BunkerWorkflow::class);
    $service = $workflow->recordReview(bunkerSubmitted(), ['result' => 'revision_required', 'revision_notes' => 'Lengkapi dokumen']);
    expect(fn () => $workflow->submit($service, ['submitted_at' => '2026-10-06T08:59']))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('revision_required');
});

test('missing approval file prevents scheduling and completion', function (bool $scheduled) {
    $service = $scheduled ? bunkerScheduled() : bunkerApproved();
    Storage::disk('local')->delete($service->latestDocument('approval_document')->file_path);
    $workflow = app(BunkerWorkflow::class);
    expect(fn () => $scheduled ? $workflow->complete($service, bunkerActual()) : $workflow->schedule($service, bunkerInput($service->vessel)))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe($scheduled ? 'bunker_scheduled' : 'approved');
})->with([true, false]);

test('bunker attention counts issues once and excludes completed cancelled and future schedules', function () {
    $overdue = BunkerService::factory()->create(['status' => 'bunker_scheduled', 'scheduled_at' => now()->subMinute()]);
    $revision = BunkerService::factory()->create(['status' => 'revision_required', 'scheduled_at' => now()->subDay()]);
    $rejected = BunkerService::factory()->create(['status' => 'rejected']);
    foreach (['completed', 'cancelled'] as $status) {
        BunkerService::factory()->create(['status' => $status, 'scheduled_at' => now()->subDay()]);
    }
    BunkerService::factory()->create(['status' => 'bunker_scheduled', 'scheduled_at' => now()->addDay()]);
    expect(BunkerService::needsAttention()->pluck('id')->all())->toEqualCanonicalizing([$overdue->id, $revision->id, $rejected->id]);
});

test('cancellation reason is required and document history is unchanged on invalid cancellation', function () {
    $service = bunkerDraft();
    expect(fn () => app(BunkerWorkflow::class)->cancel($service, ['cancellation_reason' => ' ']))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('draft')->and($service->histories()->count())->toBe(1);
});
