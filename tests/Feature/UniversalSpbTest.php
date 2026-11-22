<?php

use App\Models\Client;
use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\SpbStatusHistory;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\DocumentFilename;
use App\Services\SpbReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** @return array<string, mixed> */
function universalSpbInput(Vessel $vessel, User $staff): array
{
    return [
        'vessel_id' => $vessel->id, 'assigned_staff_id' => $staff->id,
        'priority' => 'high', 'total_crew' => 10, 'total_passengers' => 0,
        'departure_port' => 'Makassar', 'destination' => 'Kupang',
        'estimated_departure_at' => '2026-10-07T10:00',
        'estimated_port_activity_completion_at' => '2026-10-07T09:00',
        'cargo_status' => 'not_applicable', 'port_cargo_activity_applicable' => false,
        'agent_fee' => '100000', 'pnbp_labuh' => '10000', 'pnbp_mastercable' => '1000',
    ];
}

function attachUniversalSpbDocument(SpbService $spb, string $type): void
{
    $document = SpbDocument::factory()->for($spb, 'spbService')->create(['document_type' => $type]);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 fixture');
    $spb->unsetRelation('documents');
}

/** @param array<string, mixed> $attributes */
function universalReadyFixture(string $type, array $attributes = []): SpbService
{
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => $type]);
    foreach (['liferaft', 'fire_fighting', 'life_jacket', 'emergency_equipment', ...($type === 'cargo' ? ['life_buoy'] : [])] as $equipmentType) {
        $document = VesselDocument::factory()->for($vessel)->readyEquipment($equipmentType)->create();
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 equipment');
    }
    foreach (config('spb.required_vessel_documents') as $requirement) {
        $document = VesselDocument::factory()->for($vessel)->create([
            'document_type' => $requirement['types'][0],
            'expired_date' => $requirement['requires_expiry'] ? '2027-10-01' : null,
        ]);
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 fixture');
    }
    $spb = SpbService::factory()->for($vessel)->create(array_replace([
        'total_passengers' => 0, 'cargo_status' => 'not_applicable',
        'estimated_departure_at' => '2026-10-07 02:00:00',
    ], $attributes));
    foreach (['payment_proof', 'pnbp_proof'] as $documentType) {
        attachUniversalSpbDocument($spb, $documentType);
    }

    return $spb;
}

test('one sidebar SPB link stays active throughout universal pages', function () {
    $staff = User::factory()->create();
    $spb = SpbService::factory()->create(['assigned_staff_id' => $staff->id]);
    $this->actingAs($staff);
    foreach (['spb.index' => [], 'spb.create' => [], 'spb.show' => [$spb], 'spb.edit' => [$spb]] as $name => $parameters) {
        $response = $this->get(route($name, $parameters))->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new DOMXPath($dom);
        $links = $xpath->query('//aside//a[@href="'.route('spb.index').'"]');
        expect($links->length)->toBe(1);
        expect($links->item(0)->getAttribute('aria-current'))->toBe('page');
        expect($xpath->query('//aside//a[contains(@href,"/spb/passenger")]')->length)->toBe(0);
    }
});

test('legacy passenger URLs redirect to the corresponding universal resource', function () {
    $spb = SpbService::factory()->create(['service_type' => 'spb_passenger']);
    $this->actingAs(User::factory()->create());
    $this->get('/dashboard/services/spb/passenger?status=pending')->assertRedirectToRoute('spb.index', ['status' => 'pending']);
    $this->get('/dashboard/services/spb/passenger/create')->assertRedirectToRoute('spb.create');
    $this->get('/dashboard/services/spb/passenger/'.$spb->id)->assertRedirectToRoute('spb.show', $spb);
    $this->get('/dashboard/services/spb/passenger/'.$spb->id.'/edit')->assertRedirectToRoute('spb.edit', $spb);
});

test('admin can select and create SPB for every vessel type in the same form', function (string $type) {
    Storage::fake('local');
    $staff = User::factory()->admin()->create();
    $pic = User::factory()->create();
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => $type]);

    Livewire::actingAs($staff)->test('pages::services.spb-form')
        ->assertSee($vessel->vessel_name)
        ->set('form', universalSpbInput($vessel, $pic))->set('priceMode', 'manual')
        ->set('documents.pnbp_labuh_transfer', UploadedFile::fake()->create('labuh.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors()->assertRedirect(route('spb.show', SpbService::firstOrFail()));

    $this->assertDatabaseHas('spb_services', [
        'vessel_id' => $vessel->id, 'client_id' => $vessel->client_id, 'service_type' => 'spb',
        'created_by' => $staff->id, 'assigned_staff_id' => $pic->id, 'total_passengers' => 0,
    ]);
})->with(['passenger', 'cargo', 'tanker', 'tugboat', 'fishing', 'other']);

test('SPB uses active sijil crew instead of submitted or historical vessel crew totals', function () {
    Storage::fake('local');
    $staff = User::factory()->admin()->create();
    $vessel = Vessel::factory()->for(Client::factory())->create();
    $vessel->forceFill(['total_crew' => 12])->save();
    $sijil = \App\Models\SijilService::factory()->for($vessel)->create(['status' => 'completed']);
    $crew = \App\Models\VesselCrew::factory()->for($vessel)->create(['sign_on_service_id' => $sijil->id]);
    $input = universalSpbInput($vessel, $staff);

    Livewire::actingAs($staff)->test('pages::services.spb-form')
        ->assertDontSee('Total Crew *')
        ->set('form', $input)->set('priceMode', 'manual')
        ->call('save')->assertHasNoErrors();

    $spb = SpbService::firstOrFail();
    expect($spb->total_crew)->toBe(1);

    \App\Models\VesselCrew::factory()->for($vessel)->create(['sign_on_service_id' => $sijil->id]);
    app(\App\Services\SpbWorkflow::class)->saveDraft($spb, $input, []);
    expect($spb->fresh()->total_crew)->toBe(2);

    $crew->update(['active_crew_id' => null, 'sign_off_at' => today()]);
    app(\App\Services\SpbWorkflow::class)->saveDraft($spb->fresh(), $input, []);
    expect($spb->fresh()->total_crew)->toBe(1);
});

test('editing an SPB loads its saved values and preserves the agreed price', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $client = Client::factory()->create(['service_prices' => ['spb' => '900000']]);
    $vessel = Vessel::factory()->for($client)->create(['vessel_type' => 'cargo']);
    $this->actingAs($staff);
    $input = array_replace(universalSpbInput($vessel, $staff), [
        'cargo_status' => 'carrying_cargo', 'cargo_description' => 'Beras', 'cargo_type' => 'Pangan',
        'cargo_quantity' => '12', 'cargo_unit' => 'Ton', 'pnbp_tambat' => '25000', 'agent_fee' => '300000',
    ]);
    $spb = app(\App\Services\SpbWorkflow::class)->saveDraft(null, $input, []);

    $page = Livewire::test('pages::services.spb-form', ['spb' => $spb])
        ->assertSet('priceMode', 'manual')
        ->assertSet('form.cargo_description', 'Beras')
        ->assertSet('form.agent_fee', '300000.00')
        ->assertSet('form.pnbp_tambat', '25000.00');
    $dom = new DOMDocument;
    @$dom->loadHTML($page->html());
    expect($dom->getElementById('cargo_description')->getAttribute('value'))->toBe('Beras')
        ->and($dom->getElementById('pnbp_tambat')->getAttribute('value'))->toBe('25000.00')
        ->and($dom->getElementById('agent_fee')->getAttribute('value'))->toBe('300000.00');
    $page->call('save')->assertHasNoErrors();

    expect($spb->fresh()->agent_fee)->toBe('300000.00');
});

test('monitoring summary and vessel filters include legacy categories without duplicate services', function () {
    $passenger = SpbService::factory()->create(['service_type' => 'spb_passenger', 'status' => 'applied']);
    $cargo = SpbService::factory()->for(Vessel::factory()->state(['vessel_type' => 'cargo']))->create(['service_type' => 'spb_other']);
    $page = Livewire::actingAs(User::factory()->create())->test('pages::services.spb')
        ->assertSee($passenger->service_number)->assertSee($cargo->service_number);
    expect($page->get('services')->pluck('id')->all())->toBe([$cargo->id, $passenger->id]);
    expect($page->get('summary'))->toBe([
        'SPB Aktif' => 2, 'Draft' => 1, 'Siap Diajukan' => 0, 'Dalam Review' => 1,
        'Perlu Revisi' => 0, 'Siap Berlayar' => 0, 'Selesai' => 0,
    ]);
    $page->set('vesselType', 'cargo')->assertSee($cargo->service_number)->assertDontSee($passenger->service_number);
    $page->set('vesselType', 'passenger')->assertSee($passenger->service_number)->assertDontSee($cargo->service_number);
    Livewire::test('pages::dashboard.overview')->assertSet('pendingSpb', 2)->assertSet('activeSpbCount', 2);
});

test('passenger and cargo fields react to vessel selection and operational conditions', function () {
    $passenger = Vessel::factory()->create(['vessel_type' => 'passenger']);
    $tugboat = Vessel::factory()->create(['vessel_type' => 'tugboat']);
    Livewire::actingAs(User::factory()->create())->test('pages::services.spb-form')
        ->set('form.vessel_id', $passenger->id)->assertSee('Total Passenger *')
        ->set('form.total_passengers', 15)
        ->set('form.vessel_id', $tugboat->id)->assertDontSee('Total Passenger *')->assertSet('form.total_passengers', 0)
        ->set('carriesPassengers', true)->assertSee('Total Passenger *')
        ->set('form.total_passengers', 5)->set('carriesPassengers', false)->assertSet('form.total_passengers', 0)
        ->set('form.cargo_status', 'carrying_cargo')->assertSee('Cargo Description *')
        ->set('form.cargo_status', 'no_cargo')->assertDontSee('Cargo Description *');
});

test('passenger manifest is required only for passenger vessels or positive passenger counts', function (string $type, int $passengers, bool $required) {
    Storage::fake('local');
    $spb = universalReadyFixture($type, ['total_passengers' => $passengers]);
    $result = app(SpbReadinessService::class)->check($spb);
    expect($result['missing'])->toBe($required ? ['Passenger Manifest'] : []);
    attachUniversalSpbDocument($spb, 'passenger_manifest');
    expect(app(SpbReadinessService::class)->check($spb)['complete'])->toBeTrue();
})->with([
    ['passenger', 0, true], ['passenger', 10, true], ['cargo', 0, false],
    ['tugboat', 2, true], ['fishing', 0, false], ['other', 0, false],
]);

test('cargo manifest is optional regardless of cargo status or vessel type', function (string $type, string $status) {
    Storage::fake('local');
    $spb = universalReadyFixture($type, ['cargo_status' => $status, 'cargo_description' => 'Beras']);
    $result = app(SpbReadinessService::class)->check($spb);
    expect($result['missing'])->toBe([]);
    attachUniversalSpbDocument($spb, 'cargo_manifest');
    expect(app(SpbReadinessService::class)->check($spb)['complete'])->toBeTrue();
})->with([
    ['cargo', 'not_applicable'], ['cargo', 'no_cargo'], ['cargo', 'carrying_cargo'],
    ['tanker', 'carrying_cargo'], ['tugboat', 'not_applicable'], ['fishing', 'carrying_cargo'],
]);

test('port cargo activity does not require RKBM or PBM', function () {
    Storage::fake('local');
    $spb = universalReadyFixture('cargo', ['cargo_status' => 'no_cargo', 'port_cargo_activity_applicable' => true]);
    expect(app(SpbReadinessService::class)->check($spb)['complete'])->toBeTrue();
});

test('declaring no passengers disables the manifest and permits readiness without it', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $spb = universalReadyFixture('passenger', ['assigned_staff_id' => $staff->id, 'total_passengers' => 0, 'pnbp_labuh' => 10000, 'pnbp_mastercable' => 1000]);

    Livewire::actingAs($staff)->test('pages::services.spb-form', ['spb' => $spb])
        ->set('priceMode', 'manual')->set('form.agent_fee', '100000')
        ->set('form.passengers_none', true)
        ->assertSet('form.total_passengers', 0)
        ->assertSee('Tidak diperlukan karena penumpang nihil.')
        ->call('save')->assertHasNoErrors();

    expect($spb->fresh()->passengers_none)->toBeTrue();
    Livewire::test('pages::services.spb-detail', ['spb' => $spb->fresh()])->call('ready')->assertHasNoErrors();
    expect($spb->fresh()->status)->toBe('ready_to_apply');
});

test('a no passengers declaration cannot be combined with passengers or a new manifest', function (bool $hasPassengers) {
    Storage::fake('local');
    $staff = User::factory()->create();
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'passenger']);
    $this->actingAs($staff);
    $input = array_replace(universalSpbInput($vessel, $staff), ['passengers_none' => true, 'total_passengers' => $hasPassengers ? 1 : 0]);
    $documents = $hasPassengers ? [] : ['passenger_manifest' => UploadedFile::fake()->create('manifest.pdf', 10, 'application/pdf')];

    expect(fn () => app(\App\Services\SpbWorkflow::class)->saveDraft(null, $input, $documents))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
})->with([true, false]);

test('SPB form and readiness do not require warta kapal', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $spb = universalReadyFixture('cargo', ['assigned_staff_id' => $staff->id]);

    Livewire::actingAs($staff)->test('pages::services.spb-form')->assertDontSee('Warta Kapal');
    Livewire::test('pages::services.spb-detail', ['spb' => $spb])->call('ready')->assertHasNoErrors();

    expect($spb->fresh()->status)->toBe('ready_to_apply');
});

test('cargo description and passenger totals are validated before saving', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'passenger']);
    $input = array_replace(universalSpbInput($vessel, $staff), ['cargo_status' => 'carrying_cargo']);
    $page = Livewire::actingAs($staff)->test('pages::services.spb-form')->set('form', $input)->set('priceMode', 'manual')
        ->set('documents.pnbp_labuh_transfer', UploadedFile::fake()->create('labuh.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasErrors('cargo_description');
    $page->set('form.cargo_description', 'Beras')->set('form.total_passengers', '')
        ->call('save')->assertHasErrors('total_passengers');
    $this->assertDatabaseCount('spb_services', 0);
    $page->set('form.total_passengers', 5)->set('form.cargo_quantity', '12.500')->set('form.cargo_unit', 'ton')
        ->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('spb_services', ['cargo_description' => 'Beras', 'cargo_quantity' => 12.5, 'cargo_unit' => 'ton']);
});

test('PIC must be staff and unlinked vessels require a selected client', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $external = User::factory()->create(['role' => 'external']);
    $vessel = Vessel::factory()->create(['vessel_type' => 'cargo']);
    $client = Client::factory()->create();
    $page = Livewire::actingAs($staff)->test('pages::services.spb-form')
        ->set('form', universalSpbInput($vessel, $external))->set('priceMode', 'manual')
        ->set('documents.pnbp_labuh_transfer', UploadedFile::fake()->create('labuh.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasErrors('assigned_staff_id');
    $page->set('form.assigned_staff_id', $staff->id)->call('save')->assertHasErrors('client_id');
    $this->assertDatabaseCount('spb_services', 0);
    $page->set('form.client_id', $client->id)->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('spb_services', ['client_id' => $client->id, 'assigned_staff_id' => $staff->id]);
    expect($vessel->fresh()->client_id)->toBeNull();
});

test('every vessel type uses the same readiness revision issue and outcome workflow', function (string $type) {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Asia/Makassar'));
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $spb = universalReadyFixture($type, ['assigned_staff_id' => $staff->id]);
    attachUniversalSpbDocument($spb, 'passenger_manifest');
    $page = Livewire::test('pages::services.spb-detail', ['spb' => $spb]);
    $page->call('ready')->assertHasNoErrors();
    expect($spb->fresh()->status)->toBe('ready_to_apply');
    $page->set('form.result', 'revision_required')->set('form.revision_notes', 'Perbaiki dokumen')
        ->set('form.revision_items', 'Crew list')->call('recordResult')->assertHasNoErrors();
    expect($spb->fresh()->status)->toBe('revision_required');
    $page->call('ready')->assertHasNoErrors();
    $page->set('form.result', 'approved')->set('form.spb_number', 'OFFICIAL-001')->set('form.spb_issued_at', '2026-10-06T10:00')
        ->set('documents.issued_spb', UploadedFile::fake()->create('issued.pdf', 10, 'application/pdf'))
        ->call('recordResult')->assertHasNoErrors();
    expect($spb->fresh()->status)->toBe('ready_to_sail');
    Livewire::test('pages::dashboard.vessel')->call('detail', $spb->vessel_id)->assertSee('OFFICIAL-001')
        ->call('openDeparture', $spb->id)->assertRedirect(route('spb.show', $spb));
    expect($spb->fresh()->status)->toBe('ready_to_sail');
    expect($spb->vessel->fresh()->operational_status)->toBe('berthed');
    $paths = $spb->documents()->pluck('file_path')->all();
    $page->set('form.sailing_result', 'success')->call('finish')->assertHasNoErrors();
    expect($spb->fresh()->status)->toBe('completed');
    expect($spb->histories()->where('event', 'Sail Completed')->first()->changed_by)->toBe($staff->id);
    Storage::disk('local')->assertExists($paths);
})->with(['passenger', 'cargo', 'tanker', 'tugboat', 'fishing', 'other']);

test('vessel document validity blocks every vessel type until repaired', function (string $type) {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Asia/Makassar'));
    $staff = User::factory()->create();
    $spb = universalReadyFixture($type, ['assigned_staff_id' => $staff->id]);
    attachUniversalSpbDocument($spb, 'passenger_manifest');
    $document = $spb->vessel->documents()->where('document_type', 'safety_certificate')->firstOrFail();
    $document->update(['expired_date' => '2026-10-05']);
    $page = Livewire::actingAs($spb->assignedStaff)->test('pages::services.spb-detail', ['spb' => $spb])
        ->assertSee('Kedaluwarsa')->assertSee('Dokumen kapal belum lengkap.')->call('ready')->assertHasErrors('readiness');
    expect($spb->fresh()->status)->toBe('draft');
    $document->update(['expired_date' => '2026-10-10']);
    $page->call('ready')->assertHasNoErrors()->assertSee('Segera kedaluwarsa');
    expect($spb->fresh()->status)->toBe('ready_to_apply');
})->with(['passenger', 'cargo', 'tanker', 'tugboat', 'fishing', 'other']);

test('legacy categories retain their details documents and history after the additive migration', function () {
    Storage::fake('local');
    $migration = require database_path('migrations/2026_10_06_133903_add_operational_fields_to_spb_services_table.php');
    $migration->down();
    $passenger = SpbService::factory()->create(['service_type' => 'spb_passenger']);
    $other = SpbService::factory()->for(Vessel::factory()->state(['vessel_type' => 'cargo']))->create(['service_type' => 'spb_other']);
    $document = SpbDocument::factory()->for($other, 'spbService')->create();
    $history = SpbStatusHistory::factory()->for($other, 'spbService')->create(['notes' => 'Historical entry']);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 historical');
    $migration->up();

    $this->actingAs(User::factory()->create());
    $this->get(route('spb.index'))->assertSee($passenger->service_number)->assertSee($other->service_number);
    $this->get(route('spb.show', $other))->assertSee('Historical entry')->assertSee(DocumentFilename::forService($document, $other->vessel));
    $this->get(route('spb.documents.download', [$other, $document]))->assertDownload(DocumentFilename::forService($document, $other->vessel));
    $this->assertDatabaseCount('spb_services', 2);
    $this->assertDatabaseHas('spb_services', ['id' => $passenger->id, 'service_type' => 'spb_passenger']);
    $this->assertDatabaseHas('spb_services', ['id' => $other->id, 'service_type' => 'spb_other']);
    $this->assertModelExists($history);
    $this->assertModelExists($document);
});

test('an admin creating an SPB without selecting staff becomes PIC of the service and task', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);
    $input = universalSpbInput($vessel, $admin);
    $input['assigned_staff_id'] = '';

    Livewire::actingAs($admin)->test('pages::services.spb-form')
        ->set('form', $input)->set('priceMode', 'manual')
        ->set('documents.pnbp_labuh_transfer', UploadedFile::fake()->create('labuh.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();

    $spb = SpbService::sole();
    expect($spb->assigned_staff_id)->toBe($admin->id);
    expect($spb->tasks()->sole()->assigned_to)->toBe($admin->id);
});

test('SPB is ready without a request letter and the upload field is removed', function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Asia/Makassar'));
    $staff = User::factory()->create();
    $spb = universalReadyFixture('cargo', ['assigned_staff_id' => $staff->id]);

    Livewire::actingAs($staff)->test('pages::services.spb-form', ['spb' => $spb])
        ->assertDontSee('Surat Permohonan SPB');
    Livewire::test('pages::services.spb-detail', ['spb' => $spb])
        ->call('ready')->assertHasNoErrors();

    expect($spb->fresh()->status)->toBe('ready_to_apply');
});

test('previously stored SPB request letters remain available in the archive', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $spb = SpbService::factory()->create(['assigned_staff_id' => $staff->id]);
    $document = SpbDocument::factory()->for($spb, 'spbService')->create(['document_type' => 'spb_request_letter']);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 archived');

    $this->actingAs($staff)->get(route('spb.show', $spb))->assertSee('Surat Permohonan SPB');
    $this->get(route('spb.documents.download', [$spb, $document]))->assertOk();
});

test('a new SPB request letter cannot be uploaded', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'cargo']);

    Livewire::actingAs($staff)->test('pages::services.spb-form')
        ->set('form', universalSpbInput($vessel, $staff))->set('priceMode', 'manual')
        ->set('documents.spb_request_letter', UploadedFile::fake()->create('request.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasErrors(['documents']);

    expect(SpbService::count())->toBe(0);
});

test('every SPB file input has its own drop and paste area', function (string $status, ?string $result) {
    $staff = User::factory()->create();
    $spb = SpbService::factory()->create(['assigned_staff_id' => $staff->id, 'status' => $status]);
    $page = $status === 'draft'
        ? Livewire::actingAs($staff)->test('pages::services.spb-form', ['spb' => $spb])
        : Livewire::actingAs($staff)->test('pages::services.spb-detail', ['spb' => $spb]);
    if ($result !== null) {
        $page->set($status === 'ready_to_sail' ? 'form.sailing_result' : 'form.result', $result);
    }
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($document);
    $inputs = $xpath->query('//input[@type="file"]');
    expect($inputs->length)->toBeGreaterThan(0);
    foreach ($inputs as $input) {
        expect($xpath->query('ancestor::*[@data-file-dropzone]', $input)->length)->toBe(1);
    }
})->with([
    'draft' => ['draft', null],
    'revision' => ['applied', 'revision_required'],
    'rejected' => ['applied', 'rejected'],
    'approved' => ['applied', 'approved'],
    'sailing failure' => ['ready_to_sail', 'failed'],
]);
