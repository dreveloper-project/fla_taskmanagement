<?php

use App\Models\Client;
use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\SpbStatusHistory;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\SpbReadinessService;
use App\Services\SpbWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function spbVoyage(Vessel $vessel, array $overrides = []): array
{
    return array_replace([
        'assigned_staff_id' => auth()->id(), 'cargo_status' => 'not_applicable', 'port_cargo_activity_applicable' => false,
        'client_id' => $vessel->client_id ?? Client::factory()->create()->id,
        'vessel_id' => $vessel->id, 'priority' => 'high', 'total_crew' => 15,
        'total_passengers' => 100, 'departure_port' => 'Makassar', 'destination' => 'Surabaya',
        'estimated_departure_at' => '2026-10-07T10:00',
        'estimated_port_activity_completion_at' => '2026-10-07T09:00',
        'agent_fee' => '100000', 'pnbp_labuh' => '10000', 'pnbp_mastercable' => '1000',
        'notes' => 'Pengujian SPB',
    ], $overrides);
}

function spbUpload(string $name = 'document.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 12, 'application/pdf');
}

function spbCompleteDraft(): SpbService
{
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger', 'operational_status' => 'berthed']);
    foreach (['liferaft', 'fire_fighting', 'life_jacket', 'emergency_equipment'] as $type) {
        $document = VesselDocument::factory()->for($vessel)->readyEquipment($type)->create();
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 equipment');
    }
    foreach (config('spb.required_vessel_documents') as $requirement) {
        $document = VesselDocument::factory()->for($vessel)->create([
            'document_type' => $requirement['types'][0],
            'expired_date' => $requirement['requires_expiry'] ? '2027-10-01' : null,
        ]);
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 fixture');
    }
    $uploads = [];
    foreach (array_diff(array_keys(SpbDocument::APPLICATION_TYPES), ['spb_request_letter']) as $type) {
        $uploads[$type] = spbUpload($type.'.pdf');
    }

    $uploads['pnbp_labuh_transfer'] = spbUpload('labuh.pdf');

    return app(SpbWorkflow::class)->saveDraft(null, spbVoyage($vessel), $uploads);
}

function spbApplied(): SpbService
{
    $workflow = app(SpbWorkflow::class);

    return $workflow->apply($workflow->ready(spbCompleteDraft()), ['submitted_at' => '2026-10-06T09:00', 'inaportnet_reference' => 'INAP-01']);
}

function spbIssued(): SpbService
{
    return app(SpbWorkflow::class)->recordResult(spbApplied(), [
        'result' => 'approved', 'spb_number' => 'OFFICIAL-001', 'spb_issued_at' => '2026-10-06T10:00',
    ], ['issued_spb' => spbUpload('issued-spb.pdf')]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Asia/Makassar'));
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $this->workflow = app(SpbWorkflow::class);
});

test('draft derives client and staff while optional uploads remain empty', function () {
    $client = Client::factory()->create();
    $vessel = Vessel::factory()->for($client)->create(['vessel_type' => 'passenger']);
    $spb = $this->workflow->saveDraft(null, spbVoyage($vessel, ['client_id' => 999, 'created_by' => 999, 'status' => 'completed']), ['pnbp_labuh_transfer' => spbUpload('labuh.pdf')]);
    expect($spb->status)->toBe('draft')
        ->and($spb->client_id)->toBe($client->id)
        ->and($spb->created_by)->toBe(auth()->id())
        ->and($spb->assigned_staff_id)->toBe(auth()->id())
        ->and($spb->documents->where('document_type', '!=', 'pnbp_labuh_transfer'))->toHaveCount(0)
        ->and($spb->estimated_departure_at->format('Y-m-d H:i'))->toBe('2026-10-07 02:00')
        ->and($spb->histories->first()->changed_by)->toBe(auth()->id());
});

test('draft accepts a selected client for an unlinked vessel and produces unique service numbers', function () {
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger', 'client_id' => null]);
    $first = $this->workflow->saveDraft(null, spbVoyage($vessel), ['pnbp_labuh_transfer' => spbUpload('labuh.pdf')]);
    $second = $this->workflow->saveDraft(null, spbVoyage($vessel), ['pnbp_labuh_transfer' => spbUpload('labuh.pdf')]);
    expect($first->client_id)->not->toBeNull()->and($first->service_number)->not->toBe($second->service_number);
});

test('voyage inputs are required and validated', function (string $field, mixed $value) {
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger']);
    try {
        $this->workflow->saveDraft(null, spbVoyage($vessel, [$field => $value]), []);
        $this->fail('Invalid voyage accepted');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect(SpbService::count())->toBe(0);
})->with([
    ['vessel_id', null], ['priority', 'unknown'], ['total_passengers', '1.5'],
    ['departure_port', ''], ['destination', ''], ['estimated_departure_at', 'invalid'],
    ['notes', str_repeat('x', 5001)],
]);

test('cargo vessels can use the universal SPB workflow', function () {
    $vessel = Vessel::factory()->create(['vessel_type' => 'cargo']);
    expect($this->workflow->saveDraft(null, spbVoyage($vessel), ['pnbp_labuh_transfer' => spbUpload('labuh.pdf')])->vessel_id)->toBe($vessel->id);
});

test('optional uploads are stored privately and new versions preserve old files', function () {
    $spb = spbCompleteDraft();
    $old = $spb->latestDocument('cargo_manifest');
    $spb = $this->workflow->saveDraft($spb, spbVoyage($spb->vessel), ['cargo_manifest' => spbUpload('revised.pdf')]);
    expect($spb->documents->where('document_type', 'cargo_manifest'))->toHaveCount(2)
        ->and($spb->latestDocument('cargo_manifest')->original_filename)->toBe('revised.pdf');
    Storage::disk('local')->assertExists($old->file_path);
    Storage::disk('local')->assertExists($spb->latestDocument('cargo_manifest')->file_path);
});

test('unsafe and oversized files are rejected without creating a service', function (string $kind) {
    $file = match ($kind) {
        'size' => UploadedFile::fake()->create('big.pdf', 2049, 'application/pdf'),
        'extension' => UploadedFile::fake()->create('attack.php', 10, 'application/pdf'),
        default => UploadedFile::fake()->create('not-pdf.pdf', 10, 'text/plain'),
    };
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger']);
    expect(fn () => $this->workflow->saveDraft(null, spbVoyage($vessel), ['cargo_manifest' => $file]))->toThrow(ValidationException::class);
    expect(SpbService::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['size', 'extension', 'mime']);

test('readiness reads vessel documents and reports missing requirements', function () {
    $spb = SpbService::factory()->create(['assigned_staff_id' => auth()->id()]);
    $result = app(SpbReadinessService::class)->check($spb);
    expect($result['complete'])->toBeFalse()->and($result['vessel_checks'][0]['status'])->toBe('Belum tersedia');
    expect(fn () => $this->workflow->ready($spb))->toThrow(ValidationException::class);
    expect($spb->fresh()->status)->toBe('draft');
});

test('expired or unavailable vessel documents warn without blocking readiness', function (string $condition) {
    $spb = SpbService::factory()->create(['assigned_staff_id' => auth()->id(), 'total_passengers' => 0, 'passengers_none' => true]);
    $document = VesselDocument::factory()->for($spb->vessel)->create([
        'document_type' => 'safety_certificate', 'expired_date' => '2027-10-01',
    ]);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 fixture');
    match ($condition) {
        'expired' => $document->update(['expired_date' => '2026-10-05']),
        'before_departure' => $document->update(['expired_date' => '2026-10-06']),
        'unknown' => $document->update(['expired_date' => null]),
        'file_missing' => Storage::disk('local')->delete($document->file_path),
    };
    $checks = app(SpbReadinessService::class)->check($spb->fresh())['vessel_checks'];
    $certificate = collect($checks)->first(fn (array $check) => in_array('safety_certificate', $check['types'], true));
    expect($certificate['complete'])->toBeFalse();
    expect($this->workflow->ready($spb)->status)->toBe('ready_to_apply');
})->with(['expired', 'before_departure', 'unknown', 'file_missing']);

test('complete SPB becomes ready and application rechecks current documents', function () {
    $spb = $this->workflow->ready(spbCompleteDraft());
    expect($spb->status)->toBe('ready_to_apply');
    Storage::disk('local')->delete($spb->latestDocument('passenger_manifest')->file_path);
    expect(fn () => $this->workflow->apply($spb, ['submitted_at' => '2026-10-06T09:00']))->toThrow(ValidationException::class);
    expect($spb->fresh()->status)->toBe('ready_to_apply');
});

test('SPB can be prepared and submitted without a usable classification certificate', function (string $condition) {
    $spb = spbCompleteDraft();
    $document = $spb->vessel->documents()->where('document_type', 'classification_certificate')->firstOrFail();
    match ($condition) {
        'missing' => $document->delete(),
        'expired' => $document->update(['expired_date' => '2026-10-05']),
        'before_departure' => $document->update(['expired_date' => '2026-10-06']),
        'unknown' => $document->update(['expired_date' => null]),
        'file_missing' => Storage::disk('local')->delete($document->file_path),
    };

    $spb = $this->workflow->apply($this->workflow->ready($spb), ['submitted_at' => '2026-10-06T09:00']);

    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'status' => 'applied']);
    expect($spb->documents->where('document_type', 'vessel_reference'))->toHaveCount(9);
    expect($spb->documents->firstWhere('source_document_type', 'classification_certificate'))->toBeNull();
    foreach ($spb->documents->where('document_type', 'vessel_reference') as $snapshot) {
        Storage::disk('local')->assertExists($snapshot->file_path);
    }
})->with(['missing', 'expired', 'before_departure', 'unknown', 'file_missing']);

test('SPB document check labels classification as optional without an incomplete documents warning', function () {
    $spb = spbCompleteDraft();
    $spb->vessel->documents()->where('document_type', 'classification_certificate')->firstOrFail()->delete();

    $this->get(route('spb.show', $spb))
        ->assertOk()
        ->assertSee('Classification Certificate · Opsional')
        ->assertSee('Sertifikat Keselamatan Kapal Penumpang · Wajib')
        ->assertDontSee('Dokumen kapal belum lengkap.');
});

test('application stores external reference and snapshots of vessel documents', function () {
    $spb = spbApplied();
    expect($spb->status)->toBe('applied')->and($spb->inaportnet_reference)->toBe('INAP-01')
        ->and($spb->documents->where('document_type', 'vessel_reference'))->toHaveCount(10);
    expect($spb->documents->firstWhere('source_document_type', 'liferaft')->metadata['equipment']['condition'])->toBe('ready');
    $snapshot = $spb->documents->firstWhere('source_document_type', 'surat_ukur');
    $original = $spb->vessel->documents->firstWhere('document_type', 'surat_ukur');
    $spb->vessel->saveWithDocuments([], ['surat_ukur' => ['file' => spbUpload('new-ukur.pdf')]]);
    Storage::disk('local')->assertMissing($original->file_path);
    Storage::disk('local')->assertExists($snapshot->file_path);
    expect($snapshot->metadata['vessel_document_id'])->toBe($original->id);
});

test('revision requires notes and items', function () {
    expect(fn () => $this->workflow->recordResult(spbApplied(), ['result' => 'revision_required'], []))->toThrow(ValidationException::class);
});

test('revision can be edited checked and resubmitted preserving history', function () {
    $spb = $this->workflow->recordResult(spbApplied(), ['result' => 'revision_required', 'revision_notes' => 'Perbaiki manifest', 'revision_items' => 'Jumlah penumpang'], ['revision_attachment' => spbUpload()]);
    expect($spb->status)->toBe('revision_required');
    expect(fn () => $this->workflow->apply($spb, ['submitted_at' => '2026-10-06T11:00']))->toThrow(ValidationException::class);
    $spb = $this->workflow->saveDraft($spb, spbVoyage($spb->vessel, ['total_passengers' => 90]), ['cargo_manifest' => spbUpload()]);
    $spb = $this->workflow->apply($this->workflow->ready($spb), ['submitted_at' => '2026-10-06T11:00', 'inaportnet_reference' => 'INAP-02']);
    expect($spb->status)->toBe('applied')->and($spb->resubmitted_at)->not->toBeNull()
        ->and($spb->histories->pluck('event')->all())->toContain('Revision Required', 'Resubmitted to Inaportnet')
        ->and($spb->documents->where('document_type', 'cargo_manifest'))->toHaveCount(2);
});

test('rejection requires a reason and archives without deleting documents', function () {
    $spb = spbApplied();
    expect(fn () => $this->workflow->recordResult($spb, ['result' => 'rejected'], []))->toThrow(ValidationException::class);
    $spb = $this->workflow->recordResult($spb, ['result' => 'rejected', 'rejection_reason' => 'Tidak memenuhi syarat'], []);
    expect($spb->status)->toBe('rejected')->and($spb->rejection_reason)->toBe('Tidak memenuhi syarat')
        ->and($spb->archived_at)->not->toBeNull()->and($spb->documents)->toHaveCount(20);
    expect(fn () => $this->workflow->ready($spb))->toThrow(ValidationException::class);
});

test('approval requires an official file', function () {
    expect(fn () => $this->workflow->recordResult(spbApplied(), ['result' => 'approved', 'spb_number' => '123', 'spb_issued_at' => '2026-10-06T10:00'], []))->toThrow(ValidationException::class);
});

test('issuing SPB marks ready to sail and leaves vessel berthed', function () {
    $spb = spbIssued();
    expect($spb->status)->toBe('ready_to_sail')->and($spb->vessel->operational_status)->toBe('berthed')
        ->and($spb->actual_departure_at)->toBeNull()->and($spb->latestDocument('issued_spb'))->not->toBeNull();
});

test('departure confirmation cannot change an issued SPB or vessel', function () {
    $spb = spbIssued();
    $historyCount = $spb->histories()->count();
    expect(fn () => $this->workflow->depart($spb, ['actual_departure_at' => '2026-10-06T11:00', 'expected_arrival_at' => '2026-10-07T15:00']))->toThrow(ValidationException::class);
    expect($spb->fresh()->status)->toBe('ready_to_sail')->and($spb->fresh()->actual_departure_at)->toBeNull()
        ->and($spb->vessel->fresh()->operational_status)->toBe('berthed')->and($spb->histories()->count())->toBe($historyCount);
});

test('departure confirmation stays disabled regardless of file or date', function (string $condition) {
    $spb = spbIssued();
    if ($condition === 'file_missing') {
        Storage::disk('local')->delete($spb->latestDocument('issued_spb')->file_path);
    }
    expect(fn () => $this->workflow->depart($spb, ['expected_arrival_at' => '2026-10-07T15:00', 'actual_departure_at' => $condition === 'early' ? '2026-10-06T09:00' : ($condition === 'future' ? '2026-10-06T13:00' : '2026-10-06T11:00')]))->toThrow(ValidationException::class);
    expect($spb->vessel->fresh()->operational_status)->toBe('berthed');
})->with(['file_missing', 'early', 'future']);

test('disabled departure rejects any ETA without changing the vessel', function (?string $arrival, string $message) {
    $spb = spbIssued();
    $historyCount = $spb->histories()->count();

    try {
        $this->workflow->depart($spb, ['actual_departure_at' => '2026-10-06T11:00', 'expected_arrival_at' => $arrival]);
        $this->fail('Invalid ETA should prevent departure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('state');
    }

    expect($spb->fresh())->status->toBe('ready_to_sail')
        ->actual_departure_at->toBeNull()->expected_arrival_at->toBeNull();
    expect($spb->vessel->fresh()->operational_status)->toBe('berthed');
    expect($spb->histories()->count())->toBe($historyCount);
})->with([
    'missing' => [null, 'Perkiraan waktu tiba (ETA) wajib diisi.'],
    'invalid format' => ['tomorrow', 'Format perkiraan waktu tiba (ETA) tidak valid.'],
    'before departure' => ['2026-10-06T10:59', 'Perkiraan waktu tiba (ETA) harus setelah waktu keberangkatan.'],
    'same as departure' => ['2026-10-06T11:00', 'Perkiraan waktu tiba (ETA) harus setelah waktu keberangkatan.'],
]);

test('successful sailing completes and archives every document and history', function () {
    $spb = spbIssued();
    $paths = $spb->documents->pluck('file_path');
    $spb = $this->workflow->finish($spb, ['sailing_result' => 'success', 'actual_departure_at' => '2026-10-06T11:00']);
    expect($spb->status)->toBe('completed')->and($spb->sailing_result)->toBe('success')
        ->and($spb->completed_at)->not->toBeNull()->and($spb->archived_at)->not->toBeNull()
        ->and($spb->documents)->toHaveCount(21)->and($spb->histories)->toHaveCount(5);
    foreach ($paths as $path) {
        Storage::disk('local')->assertExists($path);
    }
});

test('success can be recorded without a separate departure step', function () {
    $spb = $this->workflow->finish(spbIssued(), ['sailing_result' => 'success', 'actual_departure_at' => '2026-10-06T11:00']);
    expect($spb->status)->toBe('completed')->and($spb->actual_departure_at->format('Y-m-d H:i'))->toBe('2026-10-06 03:00')->and($spb->billable)->toBeTrue();
});

test('failed sailing requires reason and stores optional attachment', function () {
    $spb = spbIssued();
    expect(fn () => $this->workflow->finish($spb, ['sailing_result' => 'failed']))->toThrow(ValidationException::class);
    $spb = $this->workflow->finish($spb, ['sailing_result' => 'failed', 'billable' => false, 'failure_reason' => 'Cuaca buruk'], ['sailing_failure_attachment' => spbUpload()]);
    expect($spb->status)->toBe('completed')->and($spb->billable)->toBeFalse()->and($spb->failure_reason)->toBe('Cuaca buruk')
        ->and($spb->archived_at)->not->toBeNull()->and($spb->vessel->operational_status)->toBe('berthed')
        ->and($spb->latestDocument('sailing_failure_attachment'))->not->toBeNull();
});

test('a legacy sailing SPB outcome preserves vessel status', function () {
    $spb = spbIssued();
    $departure = CarbonImmutable::parse('2026-10-06 03:00:00', 'UTC');
    $spb->vessel->forceFill(['operational_status' => 'sailing', 'sailed_at' => $departure])->save();
    $spb->forceFill(['status' => 'sailing', 'actual_departure_at' => $departure])->save();
    $spb = $this->workflow->finish($spb, ['sailing_result' => 'failed', 'billable' => false, 'failure_reason' => 'Kembali ke pelabuhan']);
    expect($spb->vessel->fresh()->operational_status)->toBe('sailing')->and($spb->actual_departure_at)->not->toBeNull();
});

test('invalid transitions cannot bypass the workflow', function (string $action) {
    $spb = SpbService::factory()->create(['assigned_staff_id' => auth()->id()]);
    expect(fn () => match ($action) {
        'apply' => $this->workflow->apply($spb, ['submitted_at' => '2026-10-06T09:00']),
        'issue' => $this->workflow->recordResult($spb, ['result' => 'approved', 'spb_number' => 'X', 'spb_issued_at' => '2026-10-06T10:00'], ['issued_spb' => spbUpload()]),
        'depart' => $this->workflow->depart($spb, ['actual_departure_at' => '2026-10-06T11:00', 'expected_arrival_at' => '2026-10-07T15:00']),
        'finish' => $this->workflow->finish($spb, ['sailing_result' => 'success', 'actual_departure_at' => '2026-10-06T11:00']),
    })->toThrow(ValidationException::class);
    expect($spb->fresh()->status)->toBe('draft')->and($spb->documents()->count())->toBe(0);
})->with(['apply', 'issue', 'depart', 'finish']);

test('vessel status is independent while its type remains protected during an active SPB', function () {
    $spb = spbIssued();
    $spb->vessel->saveWithDocuments(['operational_status' => 'sailing'], []);
    expect(fn () => $spb->vessel->saveWithDocuments(['vessel_type' => 'cargo'], []))->toThrow(ValidationException::class);
    expect($spb->vessel->fresh()->operational_status)->toBe('sailing');
});

test('non staff cannot execute workflow mutations', function () {
    $spb = SpbService::factory()->create();
    $this->actingAs(User::factory()->create(['role' => 'external']));
    expect(fn () => $this->workflow->saveDraft(null, spbVoyage($spb->vessel), []))->toThrow(AuthorizationException::class);
    expect(fn () => $this->workflow->ready($spb))->toThrow(AuthorizationException::class);
});

test('file and database writes are compensated when history persistence fails', function () {
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger']);
    SpbStatusHistory::creating(function (): void {
        throw new RuntimeException('Simulated history failure');
    });
    try {
        expect(fn () => $this->workflow->saveDraft(null, spbVoyage($vessel), ['cargo_manifest' => spbUpload(), 'pnbp_labuh_transfer' => spbUpload('labuh.pdf')]))->toThrow(RuntimeException::class);
        expect(SpbService::count())->toBe(0)->and(SpbDocument::count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    } finally {
        SpbStatusHistory::flushEventListeners();
    }
});

test('admin uses the shared SPB workflow and cannot skip readiness or status transitions', function () {
    $staff = auth()->user();
    $draft = spbCompleteDraft();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $workflow = app(SpbWorkflow::class);
    expect(fn () => $workflow->finish($draft, ['sailing_result' => 'success', 'actual_departure_at' => '2026-10-06T11:00']))->toThrow(ValidationException::class);
    $incomplete = SpbService::factory()->create();
    expect(fn () => $workflow->ready($incomplete))->toThrow(ValidationException::class);
    $ready = $workflow->ready($draft);
    $applied = $workflow->apply($ready, ['submitted_at' => '2026-10-06T09:00', 'inaportnet_reference' => 'ADMIN-01']);
    $issued = $workflow->recordResult($applied, ['result' => 'approved', 'spb_number' => 'ADMIN-SPB', 'spb_issued_at' => '2026-10-06T10:00'], ['issued_spb' => spbUpload('issued.pdf')]);
    $completed = $workflow->finish($issued, ['sailing_result' => 'success', 'actual_departure_at' => '2026-10-06T11:00']);
    expect($completed)->status->toBe('completed')->archived_at->not->toBeNull();
    expect($completed->histories()->latest('id')->first()->changed_by)->toBe($admin->id);
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'passenger']);
    $created = $workflow->saveDraft(null, spbVoyage($vessel, ['assigned_staff_id' => $staff->id]), ['pnbp_labuh_transfer' => spbUpload('labuh.pdf')]);
    expect($created)->created_by->toBe($admin->id)->assigned_staff_id->toBe($staff->id)->status->toBe('draft');
});
