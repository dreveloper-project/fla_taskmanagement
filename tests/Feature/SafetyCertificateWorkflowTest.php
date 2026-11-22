<?php

use App\Models\SafetyCertificateService;
use App\Models\SafetyCertificateStatusHistory;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\SafetyCertificateReadinessService;
use App\Services\SafetyCertificateWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once dirname(__DIR__).'/SafetyCertificateFixtures.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 16:00:00', 'Asia/Makassar'));
    Storage::fake('local');
});

test('new issue draft derives client creator and unique numbers without a previous certificate', function () {
    $first = safetyDraft();
    $staff = User::findOrFail(auth()->id());
    $second = app(SafetyCertificateWorkflow::class)->saveDraft(null, safetyInput($first->vessel, $staff) + ['status' => 'completed', 'created_by' => 999, 'client_id' => 999], []);
    expect($first->service_number)->toBe('SKK-2026-0001');
    expect($second->service_number)->toBe('SKK-2026-0002');
    expect($second->status)->toBe('draft')->and($second->created_by)->toBe($staff->id)
        ->and($second->client_id)->toBe($first->vessel->client_id)
        ->and($second->current_certificate_document_id)->toBeNull();
    expect($second->histories->sole()->changed_by)->toBe($staff->id);
});

test('draft accepts missing vessel documents and client while retaining required service fields', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $vessel = Vessel::factory()->create();
    $service = app(SafetyCertificateWorkflow::class)->saveDraft(null, safetyInput($vessel, $staff, 'renewal'), []);
    expect($service->client_id)->toBeNull()->and($service->status)->toBe('draft');
    $missing = app(SafetyCertificateReadinessService::class)->check($service)['missing'];
    expect($missing)->toContain('Sertifikat lama belum tersedia pada data Vessel.', 'Lampiran Teknis', 'Surat Permohonan');
});

test('non passenger vessels cannot create a safety service', function (string $type) {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $vessel = Vessel::factory()->create(['vessel_type' => $type]);
    expect(fn () => app(SafetyCertificateWorkflow::class)->saveDraft(null, safetyInput($vessel, $staff)))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('safety_certificate_services', 0);
})->with(['cargo', 'tanker', 'tugboat', 'fishing', 'other']);

test('draft validates vessel request priority and staff assignment', function (string $field, mixed $value) {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $vessel = Vessel::factory()->create();
    try {
        app(SafetyCertificateWorkflow::class)->saveDraft(null, array_replace(safetyInput($vessel, $staff), [$field => $value]));
        $this->fail('Invalid input accepted');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('safety_certificate_services', 0);
})->with([['vessel_id', null], ['request_type', 'other'], ['priority', 'critical'], ['assigned_staff_id', 999999], ['due_at', 'invalid']]);

test('renewal snapshots the expired previous certificate and preserves the original file', function () {
    $service = safetyDraft('renewal');
    $previous = $service->vessel->documents->firstWhere('document_type', 'safety_certificate');
    expect($service->current_certificate_document_id)->toBe($previous->id)
        ->and($service->previous_certificate_snapshot['certificate_number'])->toBe('OLD-2025-001')
        ->and($service->latestDocument('previous_certificate')->metadata['vessel_document_id'])->toBe($previous->id);
    Storage::disk('local')->assertExists([$previous->file_path, $service->latestDocument('previous_certificate')->file_path]);
    expect(app(SafetyCertificateReadinessService::class)->check($service)['complete'])->toBeTrue();
});

test('missing required documents block readiness and adding vessel documents enables renewal', function () {
    $service = safetyDraft();
    $service->update(['request_type' => 'renewal']);
    $workflow = app(SafetyCertificateWorkflow::class);
    expect(fn () => $workflow->ready($service))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('draft');
    $previous = VesselDocument::factory()->for($service->vessel)->create(['document_type' => 'safety_certificate']);
    Storage::disk('local')->put($previous->file_path, '%PDF-1.4 previous');
    $ready = $workflow->ready($service);
    expect($ready->status)->toBe('ready_to_apply')->and($ready->current_certificate_document_id)->toBe($previous->id);
    Storage::disk('local')->delete($ready->latestDocument('technical_attachment')->file_path);
    expect(fn () => $workflow->apply($ready, ['submitted_at' => '2026-10-06T09:00']))->toThrow(ValidationException::class);
    expect($ready->fresh()->status)->toBe('ready_to_apply');
});

test('configured required valid vessel documents block expired unknown or missing files', function (string $condition) {
    $service = safetyDraft();
    config(['safety-certificates.vessel_documents' => [['label' => 'Safe Manning', 'types' => ['safe_manning'], 'required' => true, 'must_be_valid' => true]]]);
    $document = VesselDocument::factory()->for($service->vessel)->create([
        'document_type' => 'safe_manning',
        'expired_date' => $condition === 'unknown' ? null : ($condition === 'expired' ? '2026-10-01' : '2027-10-01'),
    ]);
    if ($condition !== 'missing_file') {
        Storage::disk('local')->put($document->file_path, '%PDF-1.4');
    }
    expect(fn () => app(SafetyCertificateWorkflow::class)->ready($service))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('draft');
})->with(['expired', 'unknown', 'missing_file']);

test('optional documents do not block readiness and configurable service requirements are enforced', function () {
    $service = safetyDraft();
    expect(app(SafetyCertificateReadinessService::class)->check($service)['complete'])->toBeTrue();
    config(['safety-certificates.required_service_documents' => ['application_letter', 'payment_proof']]);
    expect(app(SafetyCertificateReadinessService::class)->check($service)['missing'])->toBe(['Bukti Pembayaran']);
});

test('applied and revision resubmission record immutable history and document versions', function () {
    $workflow = app(SafetyCertificateWorkflow::class);
    $service = safetyApplied();
    expect($service->status)->toBe('under_review')->and($service->submission_reference)->toBe('REF-001');
    $original = $service->latestDocument('technical_attachment');
    $service = $workflow->recordReview($service, ['result' => 'revision_required', 'revision_notes' => 'Perbaiki gambar'], ['revision_attachment' => safetyPdf('revision.pdf')]);
    expect($service->status)->toBe('revision_required');
    $service = $workflow->saveDraft($service, safetyInput($service->vessel, User::findOrFail(auth()->id())), ['technical_attachment' => safetyPdf('updated.pdf')]);
    $service = $workflow->apply($service, ['submitted_at' => '2026-10-06T11:00', 'submission_reference' => 'REF-002']);
    expect($service->status)->toBe('under_review')->and($service->resubmitted_at->format('H:i'))->toBe('03:00')
        ->and($service->documents->where('document_type', 'technical_attachment'))->toHaveCount(2);
    expect($service->histories->pluck('event')->all())->toContain('Revision Required', 'Resubmitted');
    Storage::disk('local')->assertExists($original->file_path);
});

test('review rejection is terminal and requires a reason without deleting records', function () {
    $service = safetyApplied();
    $workflow = app(SafetyCertificateWorkflow::class);
    expect(fn () => $workflow->recordReview($service, ['result' => 'rejected']))->toThrow(ValidationException::class);
    $service = $workflow->recordReview($service, ['result' => 'rejected', 'rejection_reason' => 'Tidak memenuhi syarat'], ['rejection_attachment' => safetyPdf()]);
    expect($service->status)->toBe('rejected')->and($service->rejection_reason)->toBe('Tidak memenuhi syarat')->and($service->archived_at)->not->toBeNull();
    expect(fn () => $workflow->ready($service))->toThrow(ValidationException::class);
    $this->assertModelExists($service);
    Storage::disk('local')->assertExists($service->documents->pluck('file_path')->all());
});

test('inspection outcomes transition only after a recorded inspection and preserve findings', function (string $result, string $status) {
    $workflow = app(SafetyCertificateWorkflow::class);
    $service = $workflow->recordReview(safetyApplied(), [
        'result' => 'inspection_required', 'inspection_scheduled_at' => '2026-10-07T09:00',
        'inspection_location' => 'Pelabuhan Makassar', 'inspector_name' => 'Surveyor',
    ]);
    expect($service->status)->toBe('inspection_scheduled');
    $service = $workflow->recordInspection($service, [
        'inspection_result' => $result, 'inspection_completed_at' => '2026-10-07T10:00',
        'findings' => 'Temuan teknis', 'revision_notes' => 'Perbaikan alat', 'rejection_reason' => 'Tidak laik',
    ], ['inspection_report' => safetyPdf('report.pdf')]);
    expect($service->status)->toBe($status)->and($service->inspection_result)->toBe($result)
        ->and($service->certificate_number)->toBeNull()->and($service->findings)->toBe('Temuan teknis');
    expect($service->histories->pluck('to_status')->all())->toContain('inspection_completed', $status);
    Storage::disk('local')->assertExists($service->latestDocument('inspection_report')->file_path);
})->with([['passed', 'waiting_for_issuance'], ['revision_required', 'revision_required'], ['rejected', 'rejected'], ['awaiting_decision', 'under_review']]);

test('inspection revisions require findings and revision notes', function () {
    $workflow = app(SafetyCertificateWorkflow::class);
    $service = $workflow->scheduleInspection(safetyApplied(), ['inspection_scheduled_at' => '2026-10-07T09:00', 'inspection_location' => 'Makassar']);
    expect(fn () => $workflow->recordInspection($service, ['inspection_result' => 'revision_required', 'inspection_completed_at' => '2026-10-07T10:00']))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('inspection_scheduled');
});

test('certificate issuance requires file number dates and chronological expiry', function (string $field, mixed $value) {
    $service = safetyApplied();
    try {
        app(SafetyCertificateWorkflow::class)->issueCertificate($service, array_replace(safetyIssueInput(), [$field => $value]), ['issued_certificate' => safetyPdf()]);
        $this->fail('Invalid certificate accepted');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect($service->fresh()->status)->toBe('under_review');
    expect($service->vessel->documents()->where('document_type', 'safety_certificate')->exists())->toBeFalse();
})->with([['certificate_number', ''], ['certificate_issued_at', ''], ['certificate_expired_at', ''], ['certificate_expired_at', '2026-10-05'], ['certificate_issued_at', '2026-10-08'], ['certificate_issued_at', '2026-10-05']]);

test('official file is mandatory and cannot be uploaded through the draft form', function () {
    $service = safetyApplied();
    $workflow = app(SafetyCertificateWorkflow::class);
    expect(fn () => $workflow->issueCertificate($service, safetyIssueInput(), []))->toThrow(ValidationException::class);
    $draft = SafetyCertificateService::factory()->create(['assigned_staff_id' => auth()->id()]);
    expect(fn () => $workflow->saveDraft($draft, safetyInput($draft->vessel, User::findOrFail(auth()->id())), ['issued_certificate' => safetyPdf()]))->toThrow(ValidationException::class);
});

test('issuing renewal updates active vessel document and archives previous certificate without deleting its file', function () {
    $service = safetyApplied('renewal');
    $previous = $service->vessel->documents->firstWhere('document_type', 'safety_certificate');
    $oldPath = $previous->file_path;
    $service = app(SafetyCertificateWorkflow::class)->issueCertificate($service, safetyIssueInput(), ['issued_certificate' => safetyPdf()]);
    $active = $previous->fresh();
    expect($service->status)->toBe('ready_for_handover')->and($service->certificate_number)->toBe('CERT-2026-0034')
        ->and($service->certificate_expired_at->toDateString())->toBe('2027-10-06');
    expect($active->certificate_number)->toBe('CERT-2026-0034')->and($active->source_safety_certificate_service_id)->toBe($service->id)
        ->and($active->status())->toBe('valid')->and($active->file_path)->not->toBe($oldPath);
    $version = $active->versions->sole();
    expect($version->certificate_number)->toBe('OLD-2025-001')->and($version->status)->toBe('superseded')->and($version->file_path)->toBe($oldPath);
    Storage::disk('local')->assertExists([$oldPath, $active->file_path, $service->issuedCertificate->file_path]);
    expect($service->histories->pluck('to_status')->all())->toContain('certificate_issued', 'ready_for_handover');
});

test('new issue creates an active certificate and configured expiry can be optional', function () {
    config(['safety-certificates.certificate_requires_expiry' => false]);
    $service = safetyApplied();
    $input = safetyIssueInput();
    unset($input['certificate_expired_at']);
    $issued = app(SafetyCertificateWorkflow::class)->issueCertificate($service, $input, ['issued_certificate' => safetyPdf()]);
    expect($issued->certificate_expired_at)->toBeNull();
    expect($issued->vessel->documents()->where('document_type', 'safety_certificate')->sole()->certificate_number)->toBe('CERT-2026-0034');
});

test('concurrent services cannot overwrite a newer active certificate', function () {
    $first = safetyApplied('renewal');
    $workflow = app(SafetyCertificateWorkflow::class);
    $second = $workflow->saveDraft(null, safetyInput($first->vessel, User::findOrFail(auth()->id()), 'renewal'), ['application_letter' => safetyPdf(), 'technical_attachment' => safetyPdf()]);
    $second = $workflow->apply($workflow->ready($second), ['submitted_at' => '2026-10-06T09:00']);
    $workflow->issueCertificate($first, safetyIssueInput(), ['issued_certificate' => safetyPdf()]);
    expect(fn () => $workflow->issueCertificate($second, safetyIssueInput(), ['issued_certificate' => safetyPdf()]))->toThrow(ValidationException::class);
    expect($second->fresh()->status)->toBe('under_review');
    $this->assertDatabaseCount('vessel_document_versions', 1);
});

test('handover records recipient time staff receipt and completes the service', function () {
    $service = safetyIssued();
    $actorId = auth()->id();
    $service = app(SafetyCertificateWorkflow::class)->handover($service, [
        'handed_over_to' => 'Nakhoda Hasan', 'recipient_role' => 'Nakhoda', 'handed_over_at' => '2026-10-07T13:00',
    ], ['handover_receipt' => safetyPdf('receipt.pdf')]);
    expect($service->status)->toBe('completed')->and($service->handed_over_to)->toBe('Nakhoda Hasan')
        ->and($service->handed_over_by)->toBe($actorId)->and($service->handed_over_at->format('H:i'))->toBe('05:00')
        ->and($service->completed_at)->not->toBeNull()->and($service->archived_at)->not->toBeNull();
    Storage::disk('local')->assertExists($service->latestDocument('handover_receipt')->file_path);
    $this->get(route('safety-certificates.show', $service))->assertSee('Completed')->assertSee('Nakhoda Hasan');
    expect(fn () => app(SafetyCertificateWorkflow::class)->handover($service, ['handed_over_to' => 'Again', 'handed_over_at' => '2026-10-07T14:00']))->toThrow(ValidationException::class);
});

test('handover rejects missing recipient early dates missing official file or changed vessel certificate', function (string $condition) {
    $service = safetyIssued();
    $input = ['handed_over_to' => 'Nakhoda', 'handed_over_at' => '2026-10-07T13:00'];
    match ($condition) {
        'recipient' => $input['handed_over_to'] = '',
        'early' => $input['handed_over_at'] = '2026-10-05T13:00',
        'future' => $input['handed_over_at'] = '2026-10-08T13:00',
        'file' => Storage::disk('local')->delete($service->issuedCertificate->file_path),
        'vessel' => $service->vessel->documents()->where('document_type', 'safety_certificate')->update(['source_safety_certificate_service_id' => null]),
    };
    expect(fn () => app(SafetyCertificateWorkflow::class)->handover($service, $input))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('ready_for_handover')->and($service->fresh()->completed_at)->toBeNull();
})->with(['recipient', 'early', 'future', 'file', 'vessel']);

test('backend rejects invalid state transitions even when the action is called directly', function (string $action) {
    $service = safetyDraft();
    $workflow = app(SafetyCertificateWorkflow::class);
    expect(fn () => match ($action) {
        'apply' => $workflow->apply($service, ['submitted_at' => '2026-10-06T09:00']),
        'review' => $workflow->recordReview($service, ['result' => 'under_review']),
        'schedule' => $workflow->scheduleInspection($service, ['inspection_scheduled_at' => '2026-10-07T09:00', 'inspection_location' => 'Makassar']),
        'inspection' => $workflow->recordInspection($service, ['inspection_result' => 'passed', 'inspection_completed_at' => '2026-10-07T10:00']),
        'issue' => $workflow->issueCertificate($service, safetyIssueInput(), ['issued_certificate' => safetyPdf()]),
        'handover' => $workflow->handover($service, ['handed_over_to' => 'Nakhoda', 'handed_over_at' => '2026-10-07T13:00']),
    })->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('draft')->and($service->histories()->count())->toBe(1);
})->with(['apply', 'review', 'schedule', 'inspection', 'issue', 'handover']);

test('unsafe uploads are rejected without service or file writes', function (string $kind) {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $vessel = Vessel::factory()->create();
    $file = match ($kind) {
        'mime' => UploadedFile::fake()->create('file.pdf', 10, 'text/plain'),
        'extension' => UploadedFile::fake()->create('file.php', 10, 'application/pdf'),
        default => UploadedFile::fake()->create('file.pdf', 2049, 'application/pdf'),
    };
    expect(fn () => app(SafetyCertificateWorkflow::class)->saveDraft(null, safetyInput($vessel, $staff), ['application_letter' => $file]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('safety_certificate_services', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['mime', 'extension', 'size']);

test('failed certificate issuance rolls back vessel replacement archives history and new files', function () {
    $service = safetyApplied('renewal');
    $previous = $service->vessel->documents->firstWhere('document_type', 'safety_certificate');
    $filesBefore = Storage::disk('local')->allFiles();
    $historyBefore = $service->histories()->count();
    SafetyCertificateStatusHistory::creating(function (SafetyCertificateStatusHistory $history): void {
        if ($history->to_status === 'certificate_issued') {
            throw new RuntimeException('Simulated history failure');
        }
    });
    try {
        expect(fn () => app(SafetyCertificateWorkflow::class)->issueCertificate($service, safetyIssueInput(), ['issued_certificate' => safetyPdf()]))->toThrow(RuntimeException::class);
        expect($service->fresh()->status)->toBe('under_review')->and($service->histories()->count())->toBe($historyBefore);
        expect($previous->fresh()->file_path)->toBe($previous->file_path);
        expect(Storage::disk('local')->allFiles())->toEqualCanonicalizing($filesBefore);
        $this->assertDatabaseCount('vessel_document_versions', 0);
    } finally {
        SafetyCertificateStatusHistory::flushEventListeners();
    }
});

test('manual vessel certificate replacement also retains the superseded file and service copy', function () {
    $service = safetyIssued();
    $active = $service->vessel->documents()->where('document_type', 'safety_certificate')->sole();
    $path = $active->file_path;
    $service->vessel->saveWithDocuments([], ['safety_certificate' => ['file' => safetyPdf('manual.pdf'), 'issued_date' => '2026-10-07', 'expired_date' => '2027-10-07']]);
    expect($active->fresh()->versions->sole()->source_safety_certificate_service_id)->toBe($service->id);
    Storage::disk('local')->assertExists([$path, $service->issuedCertificate->file_path]);
});

test('non staff cannot execute workflow mutations or bypass passenger type through vessel edits', function () {
    $service = safetyDraft();
    expect(fn () => $service->vessel->saveWithDocuments(['vessel_type' => 'cargo'], []))->toThrow(ValidationException::class);
    $this->actingAs(User::factory()->create(['role' => 'external']));
    expect(fn () => app(SafetyCertificateWorkflow::class)->ready($service))->toThrow(AuthorizationException::class);
    expect(fn () => app(SafetyCertificateWorkflow::class)->saveDraft($service, []))->toThrow(AuthorizationException::class);
});

test('new issue can replace an existing certificate whose file is missing', function () {
    $service = safetyDraft();
    $old = VesselDocument::factory()->for($service->vessel)->create(['document_type' => 'safety_certificate']);
    $workflow = app(SafetyCertificateWorkflow::class);
    $service = $workflow->apply($workflow->ready($service), ['submitted_at' => '2026-10-06T09:00']);
    $service = $workflow->issueCertificate($service, safetyIssueInput(), ['issued_certificate' => safetyPdf()]);
    expect($service->status)->toBe('ready_for_handover');
    expect($old->fresh()->versions()->sole()->file_path)->toBe($old->file_path);
});
