<?php

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('registration offers and saves only cargo and passenger vessels', function (string $type, string $label) {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel', ['registrationPage' => true])
        ->assertSeeHtml('value="cargo">Kapal Barang</option>')
        ->assertSeeHtml('value="passenger">Kapal Penumpang</option>')
        ->assertDontSeeHtml('value="tanker"')->assertDontSeeHtml('value="tugboat"')
        ->assertDontSeeHtml('value="fishing"')->assertDontSeeHtml('value="other"')
        ->set('form.vessel_name', 'KM Pilihan')->set('form.vessel_type', $type)
        ->call('save')->assertHasNoErrors()->assertRedirectToRoute('dashboard.vessel');

    $this->assertDatabaseHas('vessels', ['vessel_name' => 'KM Pilihan', 'vessel_type' => $type]);
    $this->get(route('dashboard.vessel'))->assertSee($label);
})->with(['cargo' => ['cargo', 'Kapal Barang'], 'passenger' => ['passenger', 'Kapal Penumpang']]);

test('legacy vessels remain readable and must be classified before editing', function (string $type, string $label) {
    $vessel = Vessel::factory()->create(['vessel_type' => $type]);
    $staff = User::factory()->create();

    Livewire::actingAs($staff)->test('pages::dashboard.vessel')
        ->assertSee($label)->call('detail', $vessel->id)->assertSee($label);
    Livewire::test('pages::dashboard.vessel', ['vessel' => $vessel])
        ->assertSet('form.vessel_type', '')
        ->assertSee('Jenis sebelumnya: '.$label)
        ->call('save')->assertHasErrors(['form.vessel_type' => 'required']);

    $this->assertDatabaseHas('vessels', ['id' => $vessel->id, 'vessel_type' => $type]);
})->with([
    'tanker' => ['tanker', 'Tanker (jenis lama)'],
    'tugboat' => ['tugboat', 'Tugboat (jenis lama)'],
    'fishing' => ['fishing', 'Fishing / Penangkap ikan (jenis lama)'],
    'other' => ['other', 'Lainnya (jenis lama)'],
    'unknown' => ['unknown', 'Jenis kapal belum dikenali'],
]);

test('legacy vessel classification preserves documents and completed services', function () {
    Storage::fake('local');
    $vessel = Vessel::factory()->create(['vessel_type' => 'tanker']);
    $document = VesselDocument::factory()->for($vessel)->create();
    $service = SpbService::factory()->for($vessel)->create(['status' => 'completed']);
    Storage::disk('local')->put($document->file_path, 'original document');

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel', ['vessel' => $vessel])
        ->set('form.vessel_type', 'cargo')->call('save')->assertHasNoErrors()
        ->assertRedirectToRoute('dashboard.vessel');

    $this->assertDatabaseHas('vessels', ['id' => $vessel->id, 'vessel_type' => 'cargo']);
    $this->assertDatabaseHas('vessel_documents', ['id' => $document->id, 'vessel_id' => $vessel->id, 'file_path' => $document->file_path]);
    $this->assertDatabaseHas('spb_services', ['id' => $service->id, 'vessel_id' => $vessel->id, 'status' => 'completed']);
    Storage::disk('local')->assertExists($document->file_path);
});

test('documents can still be managed for a legacy vessel with active SPB', function () {
    $vessel = Vessel::factory()->create(['vessel_type' => 'tanker']);
    SpbService::factory()->for($vessel)->create();

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('manageDocuments', $vessel->id)->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('vessels', ['id' => $vessel->id, 'vessel_type' => 'tanker']);
});

test('service details render legacy vessel types without invalid label lookups', function (string $model, string $routeName) {
    $vessel = Vessel::factory()->create(['vessel_type' => 'tanker']);
    $service = $model::factory()->for($vessel)->create();

    $this->actingAs(User::factory()->create())->get(route($routeName, $service))
        ->assertSee('Tanker (jenis lama)');
})->with([
    'SPB' => [SpbService::class, 'spb.show'],
    'safety certificate' => [SafetyCertificateService::class, 'safety-certificates.show'],
    'bunker' => [BunkerService::class, 'bunker.show'],
    'sijil' => [SijilService::class, 'sijil.show'],
    'ship nationality' => [ShipNationalityService::class, 'ship-nationality.show'],
]);

test('SPB filters offer only the two supported vessel types while legacy services remain visible', function () {
    $service = SpbService::factory()->for(Vessel::factory()->state(['vessel_type' => 'tanker']))->create();

    Livewire::actingAs(User::factory()->create())->test('pages::services.spb')
        ->assertSee($service->service_number)->assertSee('Tanker (jenis lama)')
        ->assertSeeHtml('value="cargo">Kapal Barang</option>')
        ->assertSeeHtml('value="passenger">Kapal Penumpang</option>')
        ->assertDontSeeHtml('value="tanker"')->assertDontSeeHtml('value="tugboat"')
        ->assertDontSeeHtml('value="fishing"')->assertDontSeeHtml('value="other"');
    Livewire::test('pages::services.spb-form')->set('form.vessel_id', $service->vessel_id)
        ->assertSee('Vessel Type: Tanker (jenis lama)');
});

test('tanker migration changes only the category and preserves active services and documents', function () {
    Storage::fake('local');
    $tanker = Vessel::factory()->create(['vessel_type' => 'tanker']);
    $cargo = Vessel::factory()->create(['vessel_type' => 'cargo']);
    $passenger = Vessel::factory()->create(['vessel_type' => 'passenger']);
    $fishing = Vessel::factory()->create(['vessel_type' => 'fishing']);
    $service = SpbService::factory()->for($tanker)->create();
    $document = VesselDocument::factory()->for($tanker)->create();
    Storage::disk('local')->put($document->file_path, 'original document');
    $originalVessel = $tanker->fresh()->getAttributes();
    $originalService = $service->fresh()->getAttributes();
    $originalDocument = $document->fresh()->getAttributes();
    $migration = require database_path('migrations/2026_10_15_114305_classify_tanker_vessels_as_cargo.php');

    $migration->up();
    $migration->up();
    $migration->down();

    expect($tanker->fresh()->getAttributes())->toBe(array_replace($originalVessel, ['vessel_type' => 'cargo']));
    expect($service->fresh()->getAttributes())->toBe($originalService);
    expect($document->fresh()->getAttributes())->toBe($originalDocument);
    $this->assertDatabaseHas('vessels', ['id' => $cargo->id, 'vessel_type' => 'cargo']);
    $this->assertDatabaseHas('vessels', ['id' => $passenger->id, 'vessel_type' => 'passenger']);
    $this->assertDatabaseHas('vessels', ['id' => $fishing->id, 'vessel_type' => 'fishing']);
    Storage::disk('local')->assertExists($document->file_path);
    $this->actingAs(User::factory()->create())->get(route('spb.show', $service))->assertSee('Kapal Barang');
});
