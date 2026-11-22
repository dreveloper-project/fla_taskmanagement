<?php

use App\Models\ShipNationalityStatusHistory;
use App\Models\User;
use App\Models\Vessel;
use App\Services\ShipNationalityReadinessService;
use App\Services\ShipNationalityWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../ShipNationalityFixtures.php';

beforeEach(function () {
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 7)->setTime(16, 0));
    Storage::fake('local');
});

test('draft derives client from vessel and ignores forged workflow fields', function () {
    $draft = nationalityDraft();
    $saved = app(ShipNationalityWorkflow::class)->saveDraft($draft, nationalityInput($draft->vessel) + ['client_id' => 999, 'status' => 'completed', 'created_by' => 999]);

    expect($saved->client_id)->toBe($draft->vessel->client_id);
    expect($saved->status)->toBe('draft');
    expect($saved->created_by)->toBe(auth()->id());
    expect($saved->previous_document_id)->toBeNull();
    expect($saved->service_number)->toStartWith('SN-2026-');
    expect($saved->histories)->toHaveCount(2);
});

test('draft requires vessel document type request type and priority', function (string $field, string $message) {
    $this->actingAs(User::factory()->create());
    $input = nationalityInput(Vessel::factory()->create());
    unset($input[$field]);

    expect(fn () => app(ShipNationalityWorkflow::class)->saveDraft(null, $input))->toThrow(ValidationException::class, $message);
    $this->assertDatabaseCount('ship_nationality_services', 0);
})->with([
    ['vessel_id', 'Kapal wajib diisi.'],
    ['document_type', 'Jenis dokumen wajib diisi.'],
    ['request_type', 'Jenis pengurusan wajib diisi.'],
    ['priority', 'Prioritas wajib diisi.'],
]);

test('renewal without old document saves draft but cannot become ready', function () {
    $draft = nationalityDraft();
    $workflow = app(ShipNationalityWorkflow::class);
    $draft = $workflow->saveDraft($draft, nationalityInput($draft->vessel, 'surat_laut', 'renewal'));

    expect(app(ShipNationalityReadinessService::class)->check($draft)['missing'])->toContain('Dokumen lama belum tersedia.');
    expect(fn () => $workflow->ready($draft))->toThrow(ValidationException::class);
    expect($draft->fresh()->status)->toBe('draft');
});

test('readiness checks physical files and accepts service ownership evidence', function () {
    $draft = nationalityDraft();
    $ownership = $draft->vessel->documents()->where('document_type', 'grosse_akta')->sole();
    Storage::disk('local')->delete($ownership->file_path);
    $workflow = app(ShipNationalityWorkflow::class);

    expect(fn () => $workflow->ready($draft))->toThrow(ValidationException::class);
    $draft = $workflow->saveDraft($draft, nationalityInput($draft->vessel), ['ownership_evidence' => nationalityPdf('ownership.pdf')]);
    expect($workflow->ready($draft)->status)->toBe('ready_to_apply');
    Storage::disk('local')->delete($draft->vessel->documents()->where('document_type', 'surat_ukur')->sole()->file_path);
    expect(fn () => $workflow->apply($draft->fresh(), ['submitted_at' => '2026-10-06T09:00']))->toThrow(ValidationException::class);
    expect($draft->fresh()->status)->toBe('ready_to_apply');
});

test('configurable eligibility and additional requirements block readiness', function () {
    $draft = nationalityDraft();
    config(['ship-nationality.eligibility.surat_laut.allowed_vessel_types' => ['passenger']]);

    expect(fn () => app(ShipNationalityWorkflow::class)->ready($draft))->toThrow(ValidationException::class);
    config(['ship-nationality.eligibility.surat_laut.allowed_vessel_types' => [], 'ship-nationality.required_service_documents' => ['application_letter', 'payment_proof']]);
    expect(app(ShipNationalityReadinessService::class)->check($draft)['missing'])->toContain('Bukti Pembayaran');
});

test('submission revision and resubmission preserve references and history', function () {
    $service = nationalityApplied('renewal');
    $workflow = app(ShipNationalityWorkflow::class);
    expect($service->status)->toBe('under_review');
    expect($service->previous_document_id)->not->toBeNull();
    expect($service->latestDocument('previous_document'))->not->toBeNull();

    $revised = $workflow->recordReview($service, ['result' => 'revision_required', 'revision_notes' => 'Lengkapi bukti pembayaran']);
    $workflow->saveDraft($revised, nationalityInput($service->vessel, 'surat_laut', 'renewal'), ['payment_proof' => nationalityPdf('payment.pdf')]);
    $resubmitted = $workflow->apply($revised, ['submitted_at' => '2026-10-07T09:00']);

    expect($resubmitted->status)->toBe('under_review');
    expect($resubmitted->resubmitted_at->timezone('Asia/Makassar')->format('Y-m-d H:i'))->toBe('2026-10-07 09:00');
    expect($resubmitted->submission_reference)->toBe('REF-NATIONALITY');
    expect($resubmitted->histories()->latest('id')->first()->event)->toBe('Resubmitted');
});

test('rejection and cancellation require reasons and preserve terminal records', function (string $action) {
    $service = nationalityApplied();
    $workflow = app(ShipNationalityWorkflow::class);
    $input = $action === 'cancel' ? ['cancellation_reason' => 'Permintaan client'] : ['result' => 'rejected', 'rejection_reason' => 'Tidak disetujui'];

    expect(fn () => $workflow->{$action}($service, $action === 'cancel' ? [] : ['result' => 'rejected']))->toThrow(ValidationException::class);
    $result = $workflow->{$action}($service, $input);

    expect($result->status)->toBe($action === 'cancel' ? 'cancelled' : 'rejected');
    expect($result->archived_at)->not->toBeNull();
    expect($result->{$action === 'cancel' ? 'cancellation_reason' : 'rejection_reason'})->toBe($action === 'cancel' ? 'Permintaan client' : 'Tidak disetujui');
    expect(fn () => $workflow->apply($result, ['submitted_at' => '2026-10-07T10:00']))->toThrow(ValidationException::class);
    Storage::disk('local')->assertExists($result->latestDocument('application_letter')->file_path);
})->with(['cancel', 'recordReview']);

test('issuance requires official file and rejects invalid dates without changing vessel', function () {
    $service = nationalityApplied();
    $workflow = app(ShipNationalityWorkflow::class);

    expect(fn () => $workflow->issueDocument($service, nationalityIssueInput(), []))->toThrow(ValidationException::class);
    expect(fn () => $workflow->issueDocument($service, array_replace(nationalityIssueInput(), ['issued_at' => '2026-10-05']), ['issued_document' => nationalityPdf()]))->toThrow(ValidationException::class);
    expect(fn () => $workflow->issueDocument($service, array_replace(nationalityIssueInput(), ['expired_at' => '2026-10-05']), ['issued_document' => nationalityPdf()]))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('under_review');
    expect($service->vessel->documents()->where('document_type', 'surat_laut')->exists())->toBeFalse();
});

test('issued renewal archives old document and updates matching active vessel document', function (string $type) {
    $service = nationalityIssued('renewal', $type);
    $current = $service->vessel->documents()->where('document_type', $type)->sole();
    $old = $current->versions()->sole();

    expect($service->status)->toBe('ready_for_handover');
    expect($current->certificate_number)->toBe('NEW-NATIONALITY');
    expect($current->source_ship_nationality_service_id)->toBe($service->id);
    expect($current->status())->toBe('valid');
    expect($old->certificate_number)->toBe('OLD-NATIONALITY');
    expect($old->expired_date->toDateString())->toBe('2026-10-01');
    expect($old->status)->toBe('superseded');
    Storage::disk('local')->assertExists([$current->file_path, $old->file_path, $service->issuedDocument->file_path]);
    expect($service->histories()->orderBy('id')->pluck('to_status')->all())->toBe(['draft', 'ready_to_apply', 'under_review', 'document_issued', 'ready_for_handover']);
})->with(['surat_laut', 'pas_besar', 'pas_kecil']);

test('new issue permits no expiry and handover completes with audit data', function () {
    $service = nationalityApplied();
    $workflow = app(ShipNationalityWorkflow::class);
    $issued = $workflow->issueDocument($service, array_replace(nationalityIssueInput(), ['expired_at' => null]), ['issued_document' => nationalityPdf()]);
    $completed = $workflow->handover($issued, ['handed_over_to' => 'Nakhoda', 'recipient_role' => 'Captain', 'handed_over_at' => '2026-10-07T10:00'], ['handover_receipt' => nationalityPdf('receipt.pdf')]);

    expect($completed->status)->toBe('completed');
    expect($completed->expired_at)->toBeNull();
    expect($completed->handed_over_by)->toBe(auth()->id());
    expect($completed->handed_over_to)->toBe('Nakhoda');
    expect($completed->archived_at)->not->toBeNull();
    expect($completed->completed_at)->not->toBeNull();
    Storage::disk('local')->assertExists($completed->latestDocument('handover_receipt')->file_path);
    expect(fn () => $workflow->handover($completed, ['handed_over_to' => 'Lainnya', 'handed_over_at' => '2026-10-07T11:00']))->toThrow(ValidationException::class);
});

test('concurrent services cannot overwrite an already replaced master document', function () {
    $first = nationalityApplied('renewal');
    $workflow = app(ShipNationalityWorkflow::class);
    $second = $workflow->saveDraft(null, nationalityInput($first->vessel, 'surat_laut', 'renewal'), ['application_letter' => nationalityPdf()]);
    $second = $workflow->apply($workflow->ready($second), ['submitted_at' => '2026-10-06T10:00']);
    $workflow->issueDocument($first, nationalityIssueInput(), ['issued_document' => nationalityPdf()]);
    $files = Storage::disk('local')->allFiles();

    expect(fn () => $workflow->issueDocument($second, nationalityIssueInput(), ['issued_document' => nationalityPdf()]))->toThrow(ValidationException::class);
    expect($second->fresh()->status)->toBe('under_review');
    expect($first->vessel->documents()->where('document_type', 'surat_laut')->sole()->versions)->toHaveCount(1);
    expect(Storage::disk('local')->allFiles())->toBe($files);
});

test('manual vessel replacement preserves nationality versions and prevents stale handover', function () {
    $service = nationalityIssued('renewal');
    $vessel = $service->vessel;
    $vessel->saveWithDocuments([], ['surat_laut' => ['file' => nationalityPdf('manual.pdf'), 'issued_date' => '2026-10-07', 'expired_date' => '2028-10-07']]);
    $document = $vessel->documents()->where('document_type', 'surat_laut')->sole();

    expect($document->versions)->toHaveCount(2);
    expect($document->versions()->latest('id')->first()->source_ship_nationality_service_id)->toBe($service->id);
    expect($document->source_ship_nationality_service_id)->toBeNull();
    expect(fn () => app(ShipNationalityWorkflow::class)->handover($service, ['handed_over_to' => 'Captain', 'handed_over_at' => '2026-10-07T10:00']))->toThrow(ValidationException::class);
});

test('invalid transitions and nonstaff calls cannot mutate draft', function () {
    $service = nationalityDraft();
    $workflow = app(ShipNationalityWorkflow::class);

    expect(fn () => $workflow->apply($service, ['submitted_at' => '2026-10-06T10:00']))->toThrow(ValidationException::class);
    expect(fn () => $workflow->issueDocument($service, nationalityIssueInput(), ['issued_document' => nationalityPdf()]))->toThrow(ValidationException::class);
    $this->actingAs(User::factory()->create(['role' => 'client']));
    expect(fn () => $workflow->ready($service))->toThrow(AuthorizationException::class);
    expect($service->fresh()->status)->toBe('draft');
    expect($service->histories)->toHaveCount(1);
});

test('json snapshot key order does not block an unchanged renewal document', function () {
    $service = nationalityApplied('renewal');
    $service->update(['previous_document_snapshot' => array_reverse($service->previous_document_snapshot, true)]);

    $issued = app(ShipNationalityWorkflow::class)->issueDocument($service, nationalityIssueInput(), ['issued_document' => nationalityPdf()]);

    expect($issued->status)->toBe('ready_for_handover');
});

test('a failed issuance rolls back files master document versions and timeline', function () {
    $service = nationalityApplied('renewal');
    $before = Storage::disk('local')->allFiles();
    ShipNationalityStatusHistory::creating(function (ShipNationalityStatusHistory $history): void {
        if ($history->to_status === 'document_issued') {
            throw new RuntimeException('Simulated history failure');
        }
    });
    try {
        expect(fn () => app(ShipNationalityWorkflow::class)->issueDocument($service, nationalityIssueInput(), ['issued_document' => nationalityPdf()]))->toThrow(RuntimeException::class);
        expect($service->fresh()->status)->toBe('under_review');
        expect($service->histories()->count())->toBe(3);
        expect($service->vessel->documents()->where('document_type', 'surat_laut')->sole()->certificate_number)->toBe('OLD-NATIONALITY');
        expect(Storage::disk('local')->allFiles())->toEqualCanonicalizing($before);
        $this->assertDatabaseCount('vessel_document_versions', 0);
    } finally {
        ShipNationalityStatusHistory::flushEventListeners();
    }
});

test('draft identity cannot be switched and submitted request type cannot change', function () {
    $service = nationalityDraft();
    $workflow = app(ShipNationalityWorkflow::class);
    expect(fn () => $workflow->saveDraft($service, nationalityInput($service->vessel, 'pas_kecil')))->toThrow(ValidationException::class);
    expect(fn () => $workflow->saveDraft($service, nationalityInput(Vessel::factory()->create())))->toThrow(ValidationException::class);
    $service = $workflow->apply($workflow->ready($service), ['submitted_at' => '2026-10-06T09:00']);
    $service = $workflow->recordReview($service, ['result' => 'revision_required', 'revision_notes' => 'Perbaiki data']);

    expect(fn () => $workflow->saveDraft($service, nationalityInput($service->vessel, 'surat_laut', 'renewal')))->toThrow(ValidationException::class);
    expect($service->fresh()->request_type)->toBe('new_issue');
});

test('chronology and physical issued files are checked at handover', function () {
    $service = nationalityIssued();
    $workflow = app(ShipNationalityWorkflow::class);
    expect(fn () => $workflow->handover($service, ['handed_over_to' => 'Captain', 'handed_over_at' => '2026-10-05T10:00']))->toThrow(ValidationException::class);
    Storage::disk('local')->delete($service->issuedDocument->file_path);

    expect(fn () => $workflow->handover($service, ['handed_over_to' => 'Captain', 'handed_over_at' => '2026-10-07T10:00']))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('ready_for_handover');
});

test('upload types and file extensions are enforced without persisting changes', function () {
    $service = nationalityDraft();
    $workflow = app(ShipNationalityWorkflow::class);
    $before = Storage::disk('local')->allFiles();

    expect(fn () => $workflow->saveDraft($service, nationalityInput($service->vessel), ['issued_document' => nationalityPdf()]))->toThrow(ValidationException::class);
    expect(fn () => $workflow->saveDraft($service, nationalityInput($service->vessel), ['application_letter' => nationalityPdf('malicious.php')]))->toThrow(ValidationException::class);
    expect(Storage::disk('local')->allFiles())->toBe($before);
    expect($service->histories)->toHaveCount(1);
});
