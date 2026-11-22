<?php

use App\Models\Client;
use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\DocumentFilename;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

test('guests cannot open SPB pages', function (string $route) {
    $this->get(route($route))->assertRedirectToRoute('login');
})->with(['spb.index', 'spb.create']);

test('staff can open SPB menu monitoring form and sidebar links', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('spb.index'))->assertOk()->assertSee('Monitoring seluruh pengurusan SPB kapal');
    $this->get(route('spb.index'))->assertOk()->assertSee('Buat Baru')->assertSee('Tidak ada SPB sesuai pencarian.');
    $this->get(route('spb.create'))->assertOk()->assertSee('SPB Documents');
    $this->get(route('home'))->assertOk()->assertSee(route('spb.index'), false);
});

test('non staff cannot read or mutate SPB pages', function () {
    $this->actingAs(User::factory()->create(['role' => 'external']));
    $spb = SpbService::factory()->create();
    foreach (['spb.index', 'spb.index', 'spb.create'] as $route) {
        $this->get(route($route))->assertForbidden();
    }
    $this->get(route('spb.show', $spb))->assertForbidden();
    $this->get(route('spb.edit', $spb))->assertForbidden();
    Livewire::test('pages::services.spb-form')->assertForbidden();
    Livewire::test('pages::services.spb-detail', ['spb' => $spb])->assertForbidden();
});

test('form creates draft derives client and saves optional upload', function () {
    $user = User::factory()->create();
    $vessel = Vessel::factory()->for(Client::factory()->state(['service_prices' => ['spb' => '100000']]))->create(['vessel_type' => 'passenger']);
    Livewire::actingAs($user)->test('pages::services.spb-form')
        ->set('form', [
            'assigned_staff_id' => $user->id, 'cargo_status' => 'not_applicable', 'port_cargo_activity_applicable' => false,
            'vessel_id' => $vessel->id, 'priority' => 'medium', 'total_crew' => '10', 'total_passengers' => '50',
            'departure_port' => 'Makassar', 'destination' => 'Surabaya',
            'estimated_departure_at' => '2026-10-07T10:00',
            'pnbp_labuh' => '100000', 'pnbp_mastercable' => '1000', 'notes' => '',
        ])
        ->assertSee($vessel->client->name)->assertSee('Belum tersedia')
        ->set('documents.cargo_manifest', UploadedFile::fake()->create('manifest.pdf', 12, 'application/pdf'))
        ->set('documents.pnbp_labuh_transfer', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors()->assertRedirect(route('spb.show', SpbService::firstOrFail()));
    $spb = SpbService::firstOrFail();
    expect($spb->client_id)->toBe($vessel->client_id)->and($spb->status)->toBe('draft');
    Storage::disk('local')->assertExists($spb->documents->first()->file_path);
});

test('form presents validation errors for missing required voyage fields', function () {
    $vessel = Vessel::factory()->create();
    Livewire::actingAs(User::factory()->create())->test('pages::services.spb-form')
        ->set('form.vessel_id', $vessel->id)->set('form.agent_fee', '100000')
        ->set('priceMode', 'manual')->call('save')->assertHasErrors(['departure_port', 'destination', 'estimated_departure_at']);
    expect(SpbService::count())->toBe(0);
});

test('form lists passenger and cargo vessels', function () {
    $passenger = Vessel::factory()->create(['vessel_type' => 'passenger', 'vessel_name' => 'Passenger Test']);
    Vessel::factory()->create(['vessel_type' => 'cargo', 'vessel_name' => 'Cargo Included']);
    Livewire::actingAs(User::factory()->create())->test('pages::services.spb-form')
        ->assertSee($passenger->vessel_name)->assertSee('Cargo Included');
});

test('draft can be edited while terminal services cannot be edited', function () {
    $spb = SpbService::factory()->for(Vessel::factory()->for(Client::factory()->state(['service_prices' => ['spb' => '100000']])))->create();
    Livewire::actingAs(User::factory()->admin()->create())->test('pages::services.spb-form', ['spb' => $spb])
        ->set('form.destination', 'Kupang')->call('save')->assertHasNoErrors();
    expect($spb->fresh()->destination)->toBe('Kupang');
    $spb->update(['status' => 'completed', 'completed_at' => now(), 'archived_at' => now()]);
    $this->get(route('spb.edit', $spb))->assertForbidden();
    $this->get(route('spb.show', $spb))->assertOk()->assertSee('Completed')->assertSee('Archive');
});

test('detail readiness action reports missing documents', function () {
    $spb = SpbService::factory()->create(['assigned_staff_id' => User::factory()->create()->id]);
    Livewire::actingAs($spb->assignedStaff)->test('pages::services.spb-detail', ['spb' => $spb])
        ->assertSee('BELUM LENGKAP')->call('ready')->assertHasErrors('readiness');
    expect($spb->fresh()->status)->toBe('draft');
});

test('monitoring filters by status vessel priority search and local dates', function () {
    $spb = SpbService::factory()->create(['service_number' => 'SPB-MATCH', 'priority' => 'urgent', 'destination' => 'Kupang', 'estimated_departure_at' => '2026-10-06 17:00:00']);
    $other = SpbService::factory()->create(['service_number' => 'SPB-OTHER', 'status' => 'completed', 'archived_at' => now(), 'priority' => 'low', 'destination' => 'Surabaya']);
    $page = Livewire::actingAs(User::factory()->create())->test('pages::services.spb');
    $page->set('search', 'Kupang')->assertSee($spb->service_number)->assertDontSee($other->service_number);
    $page->set('search', '')->set('status', 'draft')->assertSee($spb->service_number)->assertDontSee($other->service_number);
    $page->set('status', '')->set('vessel', (string) $spb->vessel_id)->assertSee($spb->service_number)->assertDontSee($other->service_number);
    $page->set('vessel', '')->set('priority', 'urgent')->assertSee($spb->service_number)->assertDontSee($other->service_number);
    $page->set('dateFrom', '2026-10-07')->set('dateTo', '2026-10-07')->assertSee($spb->service_number);
    $page->set('dateTo', '2026-10-06')->assertDontSee($spb->service_number);
});

test('completed history stays visible and pending excludes terminal and issued records', function () {
    $completed = SpbService::factory()->create(['status' => 'completed', 'archived_at' => now()]);
    $ready = SpbService::factory()->create(['status' => 'ready_to_sail']);
    $draft = SpbService::factory()->create();
    Livewire::actingAs(User::factory()->create())->test('pages::services.spb')
        ->set('status', 'completed')->assertSee($completed->service_number)->assertDontSee($draft->service_number)
        ->set('status', 'pending')->assertSee($draft->service_number)->assertDontSee($completed->service_number)->assertDontSee($ready->service_number);
    Livewire::test('pages::dashboard.overview')->assertSet('pendingSpb', 1)->assertSet('activeSpbCount', 2);
});

test('monitoring is paginated and resets page after filtering', function () {
    SpbService::factory()->count(11)->create();
    Livewire::actingAs(User::factory()->create())->test('pages::services.spb')
        ->call('setPage', 2)->assertSet('paginators.page', 2)
        ->set('search', 'none')->assertSet('paginators.page', 1)->assertSee('Tidak ada SPB sesuai pencarian.');
});

test('staff download private SPB files and cannot cross service boundaries', function () {
    $spb = SpbService::factory()->create(['status' => 'completed']);
    $document = SpbDocument::factory()->for($spb, 'spbService')->create();
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 private');
    $url = route('spb.documents.download', [$spb, $document]);
    $this->get($url)->assertRedirectToRoute('login');
    $this->actingAs(User::factory()->create(['role' => 'external']))->get($url)->assertForbidden();
    $this->actingAs(User::factory()->create())->get($url)->assertOk()->assertDownload(DocumentFilename::forService($document, $spb->vessel));
    $other = SpbService::factory()->create();
    $this->get(route('spb.documents.download', [$other, $document]))->assertNotFound();
    Storage::disk('local')->delete($document->file_path);
    $this->get($url)->assertNotFound();
});

test('ready SPB appears in vessel and links to the result form', function () {
    $spb = SpbService::factory()->create(['assigned_staff_id' => User::factory()->create()->id, 'status' => 'ready_to_sail', 'spb_number' => 'ISSUED-1', 'spb_issued_at' => now()]);
    $document = SpbDocument::factory()->for($spb, 'spbService')->create(['document_type' => 'issued_spb']);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 issued');
    $this->actingAs($spb->assignedStaff);
    $this->get(route('dashboard.vessel', ['vessel' => $spb->vessel_id]))
        ->assertOk()->assertSee('Ready To Sail')->assertSee('Catat hasil SPB')->assertDontSee('Konfirmasi Berlayar');
    Livewire::test('pages::services.spb-detail', ['spb' => $spb])
        ->assertSee('Sail Completed')->assertSee('Not Sailing')->assertSee('Fail to Sail')
        ->set('form.sailing_result', 'success')->call('finish')->assertHasNoErrors();
    expect($spb->fresh()->status)->toBe('completed')->and($spb->vessel->fresh()->operational_status)->toBe('berthed');
});

test('livewire result recording requires official upload and updates status', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Asia/Makassar'));
    $spb = SpbService::factory()->create(['assigned_staff_id' => User::factory()->create()->id, 'status' => 'applied', 'submitted_at' => '2026-10-06 01:00:00']);
    foreach (['liferaft', 'fire_fighting', 'life_jacket', 'emergency_equipment'] as $type) {
        $equipment = VesselDocument::factory()->for($spb->vessel)->readyEquipment($type)->create();
        Storage::disk('local')->put($equipment->file_path, '%PDF-1.4 equipment');
    }
    Livewire::actingAs($spb->assignedStaff)->test('pages::services.spb-detail', ['spb' => $spb])
        ->set('form.result', 'approved')->set('form.spb_number', 'OFF-123')->set('form.spb_issued_at', '2026-10-06T10:00')
        ->call('recordResult')->assertHasErrors('documents.issued_spb')
        ->set('documents.issued_spb', UploadedFile::fake()->create('issued.pdf', 10, 'application/pdf'))
        ->call('recordResult')->assertHasNoErrors()->assertSee('Ready To Sail');
    expect($spb->fresh()->status)->toBe('ready_to_sail')->and($spb->vessel->operational_status)->toBe('berthed');
});
