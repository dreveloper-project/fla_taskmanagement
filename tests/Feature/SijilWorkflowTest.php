<?php

use App\Models\Crew;
use App\Models\SijilStatusHistory;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselCrew;
use App\Models\VesselDocument;
use App\Services\SijilReadinessService;
use App\Services\SijilWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../SijilFixtures.php';

beforeEach(function () {
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 7)->setTime(16, 0));
    Storage::fake('local');
});

test('sijil draft permits incomplete crew data and derives client and actor safely', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $vessel = Vessel::factory()->create(['client_id' => null]);
    $service = app(SijilWorkflow::class)->saveDraft(null, sijilInput($vessel) + ['client_id' => 999, 'status' => 'completed']);
    expect($service->status)->toBe('draft')->and($service->client_id)->toBeNull()->and($service->created_by)->toBe($staff->id)
        ->and($service->assigned_staff_id)->toBe($staff->id)->and($service->service_number)->toBe('SJL-2026-0001');
    expect(app(SijilReadinessService::class)->check($service)['missing'])->toContain('Minimal satu awak kapal diperlukan.');
    expect(fn () => app(SijilWorkflow::class)->ready($service))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('crews', 0);
});

test('sijil validates vessel process and assignment without data writes', function (string $field, mixed $value) {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $vessel = Vessel::factory()->create();
    try {
        app(SijilWorkflow::class)->saveDraft(null, array_replace(sijilInput($vessel), [$field => $value]));
        $this->fail('Expected validation');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('sijil_services', 0);
})->with([['vessel_id', null], ['vessel_id', 999], ['process_type', 'unknown'], ['assigned_staff_id', 999], ['notes', str_repeat('x', 5001)]]);

test('sijil uses an internal default priority while retaining legacy values on edit', function () {
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create();
    $workflow = app(SijilWorkflow::class);

    $service = $workflow->saveDraft(null, ['vessel_id' => $vessel->id, 'process_type' => 'sign_on', 'priority' => 'urgent']);
    expect($service->priority)->toBe('medium');

    $service->update(['priority' => 'high']);
    $updated = $workflow->saveDraft($service, ['vessel_id' => $vessel->id, 'process_type' => 'sign_on', 'priority' => 'urgent']);
    expect($updated->priority)->toBe('high');
});

test('sijil ignores completion targets and preserves historical values', function () {
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create();
    $service = app(SijilWorkflow::class)->saveDraft(null, sijilInput($vessel) + ['due_at' => '2026-10-08T10:00']);
    expect($service->due_at)->toBeNull();

    $service->forceFill(['due_at' => '2026-10-08 02:00:00'])->save();
    $service = app(SijilWorkflow::class)->saveDraft($service, sijilInput($vessel) + ['due_at' => 'invalid']);
    expect($service->due_at->format('Y-m-d H:i:s'))->toBe('2026-10-08 02:00:00');
});

test('sijil can become ready without application service documents', function () {
    $service = sijilDraft();
    $service->documents()->whereIn('document_type', ['application_letter', 'crew_list'])->delete();

    expect(app(SijilReadinessService::class)->check($service->fresh())['complete'])->toBeTrue();
    expect(app(SijilWorkflow::class)->ready($service->fresh())->status)->toBe('ready_to_apply');
});

test('historical sijil target does not cause an otherwise ready service to need attention', function () {
    $service = sijilDraft();
    $service->forceFill(['due_at' => now()->subDay()])->save();

    expect(app(SijilReadinessService::class)->needsAttention($service->fresh()))->toBeFalse();
});

test('crew readiness requires names ranks and compatible actions', function (string $case) {
    $service = sijilDraft();
    $row = $service->crews()->sole();
    match ($case) {
        'name' => $row->update(['full_name' => null]),
        'rank' => $row->update(['rank' => null]),
        'action' => $row->update(['action_type' => 'update']),
        'second' => $service->crews()->create(array_replace(sijilCrewInput('Budi', 'SB-002'), ['full_name' => null])),
        'change' => $service->update(['process_type' => 'crew_change']),
    };
    expect(fn () => app(SijilWorkflow::class)->ready($service))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('draft');
})->with(['name', 'rank', 'action', 'second', 'change']);

test('new crew can complete sijil without crew documents', function () {
    $service = sijilDraft();
    $service->crews()->sole()->documents()->delete();

    $service = app(SijilWorkflow::class)->applyCrewChanges(sijilApproved($service), ['effective_at' => '2026-10-07']);
    expect($service->status)->toBe('completed')
        ->and($service->crews()->sole()->documents)->toHaveCount(0);
});

test('existing crew still requires the documents for its action', function () {
    $completed = sijilCompleted();
    $service = sijilExistingDraft($completed, 'sign_off', 'sign_off');
    $crew = $service->crews()->sole()->crew;
    foreach ($crew->documents->where('document_type', 'seafarer_book') as $document) {
        Storage::disk('local')->delete($document->file_path);
    }

    expect(app(SijilReadinessService::class)->check($service->fresh())['complete'])->toBeFalse();
});

test('complete requirements enable submission with a copied client reference', function () {
    $service = sijilDraft();
    $this->assertDatabaseCount('crews', 0);
    expect($service->client_id)->toBe($service->vessel->client_id);
    $service = sijilSubmitted($service);
    expect($service->status)->toBe('under_review')->and($service->submitted_at->format('H:i'))->toBe('01:00');
    expect($service->histories->pluck('to_status')->all())->toBe(['draft', 'ready_to_apply', 'under_review']);
});

test('revision upload replacement and resubmission preserve old documents and removed crew rows', function () {
    $service = sijilSubmitted();
    $workflow = app(SijilWorkflow::class);
    $service = $workflow->recordReview($service, ['result' => 'revision_required', 'revision_notes' => 'Perbaiki PKL'], ['revision_attachment' => sijilPdf()]);
    $row = $service->crews()->sole();
    $old = $row->documents()->where('document_type', 'pkl')->sole()->file_path;
    $input = $row->only(['id', 'crew_id', 'full_name', 'rank', 'seafarer_book_number', 'competency_certificate_number', 'action_type', 'notes']);
    $service = $workflow->saveDraft($service, sijilInput($service->vessel), [$input], [], [['pkl' => sijilPdf('revised.pdf')]]);
    $service = $workflow->submit($service, ['submitted_at' => '2026-10-07T08:00']);
    expect($service->status)->toBe('under_review')->and($service->resubmitted_at)->not->toBeNull();
    Storage::disk('local')->assertExists($old);
    expect($row->documents()->where('document_type', 'pkl')->count())->toBe(2);
    $service = $workflow->recordReview($service, ['result' => 'revision_required', 'revision_notes' => 'Ganti awak']);
    $workflow->saveDraft($service, sijilInput($service->vessel), []);
    expect($service->crews()->count())->toBe(0)->and($row->fresh()->removed_at)->not->toBeNull();
    Storage::disk('local')->assertExists($old);
});

test('review requires reason for rejection and revision', function (string $result, string $field) {
    $service = sijilSubmitted();
    try {
        app(SijilWorkflow::class)->recordReview($service, ['result' => $result]);
        $this->fail('Expected reason');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([['rejected', 'rejection_reason'], ['revision_required', 'revision_notes']]);

test('approval validates official result and chronology', function (string $case) {
    $service = sijilSubmitted();
    $date = match ($case) {
        'early' => '2026-10-05', 'future' => '2026-10-08', 'missing_date' => '', default => '2026-10-07'
    };
    expect(fn () => app(SijilWorkflow::class)->recordReview($service, ['result' => 'approved', 'issued_at' => $date], $case === 'file' ? [] : ['official_result' => sijilPdf()]))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('under_review');
})->with(['early', 'future', 'file', 'missing_date']);

test('sign on applies only after official result and completes atomically', function () {
    $service = sijilApproved();
    expect($service->status)->toBe('ready_to_update_crew');
    $this->assertDatabaseCount('crews', 0);
    $service = app(SijilWorkflow::class)->applyCrewChanges($service, ['effective_at' => '2026-10-07']);
    expect($service->status)->toBe('completed')->and($service->completed_by)->toBe(auth()->id())->and($service->archived_at)->not->toBeNull();
    $assignment = VesselCrew::sole();
    expect($assignment->full_name)->toBe('Andi')->and($assignment->rank)->toBe('Master')->and($assignment->active_crew_id)->toBe(Crew::sole()->id);
    expect(Crew::sole()->documents)->toHaveCount(3);
    foreach ($service->documents as $document) {
        Storage::disk('local')->assertExists($document->file_path);
    }
    expect(fn () => app(SijilWorkflow::class)->applyCrewChanges($service, ['effective_at' => '2026-10-07']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('vessel_crews', 1);
});

test('sign off reuses master crew documents and preserves assignment history', function () {
    $completed = sijilCompleted();
    $off = sijilExistingDraft($completed, 'sign_off', 'sign_off');
    expect($off->documents)->toHaveCount(2);
    $off = sijilApproved($off);
    expect($off->crews()->sole()->documents)->toHaveCount(3);
    $off = app(SijilWorkflow::class)->applyCrewChanges($off, ['effective_at' => '2026-10-07']);
    $assignment = VesselCrew::sole();
    expect($assignment->active_crew_id)->toBeNull()->and($assignment->sign_off_at->toDateString())->toBe('2026-10-07')
        ->and($assignment->sign_on_service_id)->toBe($completed->id)->and($assignment->sign_off_service_id)->toBe($off->id);
    $this->assertDatabaseCount('crews', 1);
    expect($off->crews()->sole()->before_snapshot['rank'])->toBe('Master');
});

test('crew change signs off old crew and signs on new crew in one transaction', function () {
    $completed = sijilCompleted();
    $service = sijilExistingDraft($completed, 'sign_off', 'crew_change');
    $old = $service->crews()->sole()->only(['id', 'crew_id', 'full_name', 'rank', 'seafarer_book_number', 'competency_certificate_number', 'action_type', 'notes']);
    $service = app(SijilWorkflow::class)->saveDraft($service, sijilInput($service->vessel, 'crew_change'), [$old, sijilCrewInput('Budi', 'SB-002')], [], [1 => sijilCrewFiles()]);
    $service = app(SijilWorkflow::class)->applyCrewChanges(sijilApproved($service), ['effective_at' => '2026-10-07']);
    expect(VesselCrew::whereNotNull('active_crew_id')->sole()->full_name)->toBe('Budi');
    expect(VesselCrew::whereNull('active_crew_id')->sole()->full_name)->toBe('Andi');
    expect($service->crews)->toHaveCount(2);
});

test('crew update stores before and after snapshots without replacing identity or sign on history', function () {
    $completed = sijilCompleted();
    $service = sijilExistingDraft($completed, 'update', 'registration_update');
    $row = $service->crews()->sole();
    $row->update(['rank' => 'Chief Officer', 'full_name' => 'Andi Updated']);
    $service = app(SijilWorkflow::class)->applyCrewChanges(sijilApproved($service), ['effective_at' => '2026-10-07']);
    expect(Crew::sole()->full_name)->toBe('Andi Updated');
    expect(VesselCrew::sole()->rank)->toBe('Chief Officer')->and(VesselCrew::sole()->sign_on_service_id)->toBe($completed->id);
    expect($row->fresh()->before_snapshot['rank'])->toBe('Master')->and($row->fresh()->after_snapshot['rank'])->toBe('Chief Officer');
});

test('stale approved changes cannot overwrite a newer crew update', function () {
    $completed = sijilCompleted();
    $first = sijilApproved(sijilExistingDraft($completed, 'update', 'registration_update'));
    $second = sijilApproved(sijilExistingDraft($completed, 'update', 'registration_update'));
    $workflow = app(SijilWorkflow::class);
    $workflow->applyCrewChanges($first, ['effective_at' => '2026-10-07']);
    expect(fn () => $workflow->applyCrewChanges($second, ['effective_at' => '2026-10-07']))->toThrow(ValidationException::class);
    expect($second->fresh()->status)->toBe('ready_to_update_crew')->and(VesselCrew::sole()->revision)->toBe(2);
});

test('sign off and update cannot target another vessel or an unregistered crew', function (string $action) {
    $completed = sijilCompleted();
    $vessel = Vessel::factory()->create();
    $crew = Crew::sole();
    $row = sijilCrewInput() + ['crew_id' => $crew->id];
    $row['action_type'] = $action;
    $service = app(SijilWorkflow::class)->saveDraft(null, sijilInput($vessel, $action === 'update' ? 'registration_update' : 'sign_off'), [$row], ['application_letter' => sijilPdf(), 'crew_list' => sijilPdf()]);
    expect(fn () => app(SijilWorkflow::class)->ready($service))->toThrow(ValidationException::class);
})->with(['sign_off', 'update']);

test('active crew and duplicate book numbers cannot be signed on again', function () {
    $completed = sijilCompleted();
    $row = sijilCrewInput() + ['crew_id' => Crew::sole()->id];
    $workflow = app(SijilWorkflow::class);
    $service = $workflow->saveDraft(null, sijilInput($completed->vessel), [$row], ['application_letter' => sijilPdf(), 'crew_list' => sijilPdf()]);
    expect(fn () => $workflow->ready($service))->toThrow(ValidationException::class);
    $duplicate = $workflow->saveDraft(null, sijilInput($completed->vessel), [sijilCrewInput()], ['application_letter' => sijilPdf(), 'crew_list' => sijilPdf()], [sijilCrewFiles()]);
    expect(fn () => $workflow->ready($duplicate))->toThrow(ValidationException::class);
});

test('service cannot steal a crew row or replace its identity and keep its old files', function () {
    $first = sijilDraft();
    $second = sijilDraft();
    $row = $first->crews()->sole()->only(['id', 'crew_id', 'full_name', 'rank', 'seafarer_book_number', 'competency_certificate_number', 'action_type', 'notes']);
    expect(fn () => app(SijilWorkflow::class)->saveDraft($second, sijilInput($second->vessel), [$row]))->toThrow(ModelNotFoundException::class);
    $this->actingAs($first->assignedStaff);
    $row['crew_id'] = Crew::factory()->create()->id;
    expect(fn () => app(SijilWorkflow::class)->saveDraft($first, sijilInput($first->vessel), [$row]))->toThrow(ValidationException::class);
});

test('unsafe crew documents cannot be stored or supplied as service documents', function (string $case) {
    $service = sijilDraft();
    $row = $service->crews()->sole()->only(['id', 'crew_id', 'full_name', 'rank', 'seafarer_book_number', 'competency_certificate_number', 'action_type', 'notes']);
    $file = match ($case) {
        'mime' => UploadedFile::fake()->create('test.pdf', 1, 'text/plain'),
        'extension' => UploadedFile::fake()->create('test.php', 1, 'application/pdf'),
        default => UploadedFile::fake()->create('test.pdf', 2049, 'application/pdf'),
    };
    expect(fn () => app(SijilWorkflow::class)->saveDraft($service, sijilInput($service->vessel), [$row], [], [['pkl' => $file]]))->toThrow(ValidationException::class);
    expect($service->documents()->count())->toBe(5);
})->with(['mime', 'extension', 'size']);

test('failed completion rolls back identity placement and status changes', function () {
    $service = sijilApproved();
    SijilStatusHistory::creating(function (SijilStatusHistory $history): void {
        if ($history->to_status === 'completed') {
            throw new RuntimeException('History unavailable');
        }
    });
    try {
        expect(fn () => app(SijilWorkflow::class)->applyCrewChanges($service, ['effective_at' => '2026-10-07']))->toThrow(RuntimeException::class);
        $this->assertDatabaseCount('crews', 0);
        $this->assertDatabaseCount('vessel_crews', 0);
        expect($service->fresh()->status)->toBe('ready_to_update_crew');
    } finally {
        SijilStatusHistory::flushEventListeners();
    }
});

test('rejected and cancelled services are terminal without deleting documents', function (string $status) {
    $service = sijilSubmitted();
    $workflow = app(SijilWorkflow::class);
    $service = $status === 'rejected' ? $workflow->recordReview($service, ['result' => 'rejected', 'rejection_reason' => 'Ditolak']) : $workflow->cancel($service, ['cancellation_reason' => 'Dibatalkan']);
    expect($service->status)->toBe($status)->and($service->archived_at)->not->toBeNull();
    expect(fn () => $workflow->ready($service))->toThrow(ValidationException::class);
    expect($service->documents)->toHaveCount(6);
})->with(['rejected', 'cancelled']);

test('backend rejects skipped transitions', function (string $action) {
    $service = sijilDraft();
    $workflow = app(SijilWorkflow::class);
    expect(fn () => match ($action) {
        'submit' => $workflow->submit($service, ['submitted_at' => '2026-10-06T09:00']),
        'review' => $workflow->recordReview($service, ['result' => 'rejected', 'rejection_reason' => 'Reason']),
        'apply' => $workflow->applyCrewChanges($service, ['effective_at' => '2026-10-07']),
    })->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('draft');
})->with(['submit', 'review', 'apply']);

test('non staff cannot execute sijil changes', function () {
    $service = sijilDraft();
    $this->actingAs(User::factory()->create(['role' => 'external']));
    expect(fn () => app(SijilWorkflow::class)->ready($service))->toThrow(AuthorizationException::class);
    expect(fn () => app(SijilWorkflow::class)->saveDraft($service, sijilInput($service->vessel)))->toThrow(AuthorizationException::class);
});

test('document requirements can be configured and official result can be optional', function () {
    config(['sijil.required_service_documents' => [], 'sijil.required_crew_documents.sign_on' => [], 'sijil.official_result_required' => false]);
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create();
    $appointment = VesselDocument::factory()->for($vessel)->create(['document_type' => 'surat_penunjukan_keagenan']);
    Storage::disk('local')->put($appointment->file_path, '%PDF-1.4 appointment');
    $workflow = app(SijilWorkflow::class);
    $service = $workflow->saveDraft(null, sijilInput($vessel), [sijilCrewInput()]);
    $service = $workflow->recordReview(sijilSubmitted($service), ['result' => 'approved', 'issued_at' => '2026-10-07']);
    $service = $workflow->applyCrewChanges($service, ['effective_at' => '2026-10-07']);
    expect($service->status)->toBe('completed')->and($service->documents)->toHaveCount(1);
});

test('effective dates cannot be future or precede the last placement change', function (string $date) {
    $completed = sijilCompleted();
    $off = sijilApproved(sijilExistingDraft($completed, 'sign_off', 'sign_off'));
    expect(fn () => app(SijilWorkflow::class)->applyCrewChanges($off, ['effective_at' => $date]))->toThrow(ValidationException::class);
    expect($off->fresh()->status)->toBe('ready_to_update_crew')->and(VesselCrew::sole()->active_crew_id)->not->toBeNull();
})->with(['2026-10-06', '2026-10-08', 'invalid']);

test('missing official result prevents application without changing crew', function () {
    $service = sijilApproved();
    Storage::disk('local')->delete($service->latestDocument('official_result')->file_path);
    expect(fn () => app(SijilWorkflow::class)->applyCrewChanges($service, ['effective_at' => '2026-10-07']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('crews', 0);
});

test('a previously signed off identity can sign on again without duplicate master data', function () {
    $completed = sijilCompleted();
    $workflow = app(SijilWorkflow::class);
    $workflow->applyCrewChanges(sijilApproved(sijilExistingDraft($completed, 'sign_off', 'sign_off')), ['effective_at' => '2026-10-07']);
    $crew = Crew::sole();
    $row = sijilCrewInput() + ['crew_id' => $crew->id];
    $service = $workflow->saveDraft(null, sijilInput($completed->vessel), [$row], ['application_letter' => sijilPdf(), 'crew_list' => sijilPdf()]);
    $workflow->applyCrewChanges(sijilApproved($service), ['effective_at' => '2026-10-07']);
    $this->assertDatabaseCount('crews', 1);
    $this->assertDatabaseCount('vessel_crews', 2);
    expect(VesselCrew::whereNotNull('active_crew_id')->count())->toBe(1);
});

test('duplicate crew rows and seafarer books fail readiness', function () {
    $service = sijilDraft();
    $row = $service->crews()->sole()->only(['id', 'crew_id', 'full_name', 'rank', 'seafarer_book_number', 'competency_certificate_number', 'action_type', 'notes']);
    $service = app(SijilWorkflow::class)->saveDraft($service, sijilInput($service->vessel), [$row, sijilCrewInput()], [], [1 => sijilCrewFiles()]);
    expect(fn () => app(SijilWorkflow::class)->ready($service))->toThrow(ValidationException::class);
    expect(app(SijilReadinessService::class)->check($service->fresh())['missing'])->toContain('Andi: Awak kapal yang sama tidak boleh dicantumkan dua kali.');
});

test('submission checks files again and rejects earlier resubmission', function () {
    $workflow = app(SijilWorkflow::class);
    $service = $workflow->ready(sijilDraft());
    $file = $service->latestDocument('crew_list');
    $contents = Storage::disk('local')->get($file->file_path);
    Storage::disk('local')->delete($file->file_path);
    expect(fn () => $workflow->submit($service, ['submitted_at' => '2026-10-06T09:00']))->toThrow(ValidationException::class);
    Storage::disk('local')->put($file->file_path, $contents);
    $service = $workflow->submit($service, ['submitted_at' => '2026-10-06T09:00']);
    $service = $workflow->recordReview($service, ['result' => 'revision_required', 'revision_notes' => 'Revisi']);
    expect(fn () => $workflow->submit($service, ['submitted_at' => '2026-10-06T08:59']))->toThrow(ValidationException::class);
});

test('failed draft write cleans uploaded files and all new rows', function () {
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create();
    SijilStatusHistory::creating(function (): void {
        throw new RuntimeException('History failure');
    });
    try {
        expect(fn () => app(SijilWorkflow::class)->saveDraft(null, sijilInput($vessel), [sijilCrewInput()], ['crew_list' => sijilPdf()], [sijilCrewFiles()]))->toThrow(RuntimeException::class);
        $this->assertDatabaseCount('sijil_services', 0);
        $this->assertDatabaseCount('sijil_service_crews', 0);
        expect(Storage::disk('local')->allFiles())->toBe([]);
    } finally {
        SijilStatusHistory::flushEventListeners();
    }
});

test('crew payload validation rejects malformed arrays and reserved document types', function (string $case) {
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create();
    expect(fn () => app(SijilWorkflow::class)->saveDraft(
        null, sijilInput($vessel), $case === 'row' ? ['invalid'] : [sijilCrewInput()],
        $case === 'reserved' ? ['official_result' => sijilPdf()] : [],
        $case === 'file_array' ? ['invalid'] : []
    ))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('sijil_services', 0);
})->with(['row', 'file_array', 'reserved']);

test('new crew cannot bypass an existing master book using different letter case', function () {
    $completed = sijilCompleted();
    $row = sijilCrewInput('Different Name', 'sb-001');
    $service = app(SijilWorkflow::class)->saveDraft(null, sijilInput($completed->vessel), [$row], ['application_letter' => sijilPdf(), 'crew_list' => sijilPdf()], [sijilCrewFiles()]);
    expect(fn () => app(SijilWorkflow::class)->ready($service))->toThrow(ValidationException::class);
});

test('crew number change cannot collide with another crew identity', function () {
    $completed = sijilCompleted();
    Crew::factory()->create(['seafarer_book_number' => 'OTHER-001']);
    $service = sijilExistingDraft($completed, 'update', 'registration_update');
    $service->crews()->sole()->update(['seafarer_book_number' => 'OTHER-001']);
    expect(fn () => app(SijilWorkflow::class)->ready($service))->toThrow(ValidationException::class);
});

test('two approved sign on services cannot register the same book twice', function () {
    $first = sijilApproved();
    $second = sijilApproved();
    $workflow = app(SijilWorkflow::class);
    $this->actingAs($first->assignedStaff);
    $workflow->applyCrewChanges($first, ['effective_at' => '2026-10-07']);
    $this->actingAs($second->assignedStaff);
    expect(fn () => $workflow->applyCrewChanges($second, ['effective_at' => '2026-10-07']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('crews', 1);
    expect($second->fresh()->status)->toBe('ready_to_update_crew');
});
