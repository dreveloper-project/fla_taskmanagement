<?php

use App\Models\BunkerService;
use App\Models\Client;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Services\BunkerWorkflow;
use App\Services\SafetyCertificateWorkflow;
use App\Services\ShipNationalityWorkflow;
use App\Services\SijilWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('client service prices can be set and cleared', function () {
    $user = User::factory()->admin()->create();
    $client = Client::factory()->create();
    Livewire::actingAs($user)->test('pages::dashboard.clients')->call('edit', $client->id)
        ->set('servicePrices.spb', '1500000.50')->set('servicePrices.bunker', '800000')
        ->call('save')->assertHasNoErrors();
    expect($client->fresh()->service_prices)->toMatchArray(['spb' => '1500000.50', 'bunker' => '800000']);
    Livewire::actingAs($user)->test('pages::dashboard.clients')->call('edit', $client->id)
        ->set('servicePrices.bunker', '')->call('save')->assertHasNoErrors();
    expect($client->fresh()->service_prices['bunker'])->toBe('0');
});

test('agency price button edits only the selected client and preserves existing prices when cancelled', function () {
    $user = User::factory()->admin()->create();
    $client = Client::factory()->create(['name' => 'Client Satu', 'service_prices' => ['spb' => '100000']]);
    $other = Client::factory()->create(['service_prices' => ['spb' => '200000']]);
    $page = Livewire::actingAs($user)->test('pages::dashboard.clients')
        ->assertSee('Set harga keagenan')->call('editAgencyPrices', $client->id)
        ->assertSet('showAgencyPricesForm', true)->assertSet('pricingClientName', 'Client Satu')
        ->assertSet('agencyPrices.spb', '100000')->set('agencyPrices.spb', '-1')
        ->call('saveAgencyPrices')->assertHasErrors('agencyPrices.spb');
    expect($client->fresh()->service_prices['spb'])->toBe('100000');
    $page->set('agencyPrices.spb', '125000')->set('agencyPrices.bunker', '450000')
        ->call('saveAgencyPrices')->assertHasNoErrors()->assertSet('showAgencyPricesForm', false);
    expect($client->fresh()->service_prices)->toMatchArray(['spb' => '125000', 'bunker' => '450000'])
        ->and($other->fresh()->service_prices['spb'])->toBe('200000');
    $page->call('editAgencyPrices', $client->id)->set('agencyPrices.spb', '999')->call('cancelAgencyPrices');
    expect($client->fresh()->service_prices['spb'])->toBe('125000');
});

test('service drafts snapshot either the client price or a manual price', function (string $type, string $workflow, array $additional) {
    Storage::fake('local');
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $client = Client::factory()->create(['service_prices' => [$type => '950000.00']]);
    $vessel = Vessel::factory()->for($client)->create(['vessel_type' => 'passenger']);
    $input = ['vessel_id' => $vessel->id, 'priority' => 'medium', 'assigned_staff_id' => $staff->id] + $additional;
    $priced = app($workflow)->saveDraft(null, $input + ['price_mode' => 'client', 'agent_fee' => '1']);
    $manual = app($workflow)->saveDraft(null, $input + ['price_mode' => 'manual', 'agent_fee' => '420000']);
    $client->update(['service_prices' => [$type => '2000000']]);
    expect((float) $priced->fresh()->agent_fee)->toBe(950000.0)
        ->and((float) $manual->fresh()->agent_fee)->toBe(420000.0);
})->with([
    'bunker' => ['bunker', BunkerWorkflow::class, []],
    'safety' => ['safety-certificates', SafetyCertificateWorkflow::class, ['request_type' => 'new_issue']],
    'sijil' => ['sijil', SijilWorkflow::class, ['process_type' => 'sign_on']],
    'nationality' => ['ship-nationality', ShipNationalityWorkflow::class, ['document_type' => 'surat_laut', 'request_type' => 'new_issue']],
]);

test('a missing client price is treated as zero', function () {
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->for(Client::factory())->create();
    $service = app(BunkerWorkflow::class)->saveDraft(null, ['vessel_id' => $vessel->id, 'priority' => 'medium', 'price_mode' => 'client']);
    expect($service->agent_fee)->toBe('0.00');
});

test('a missing client price is displayed as zero on the service form', function () {
    $client = Client::factory()->create();
    $vessel = Vessel::factory()->for($client)->create();

    Livewire::actingAs(User::factory()->create())->test('pages::services.bunker-form')
        ->set('form.vessel_id', $vessel->id)->set('priceMode', 'client')
        ->assertSee('Rp 0,00')->assertDontSee('Harga client belum ditetapkan');
});

test('an administrator can clear a service fee to zero', function () {
    $service = BunkerService::factory()->create(['status' => 'completed', 'agent_fee' => '500']);
    Livewire::actingAs(User::factory()->admin()->create())->test('dashboard.service-price-editor', ['type' => 'bunker', 'serviceId' => $service->id])
        ->set('agentFee', '')->call('save')->assertHasNoErrors()->assertSee('Rp 0,00');

    expect($service->fresh()->agent_fee)->toBe('0.00');
});

test('empty manual service prices are treated as zero', function (string $workflow, array $additional) {
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->for(Client::factory())->create();
    $service = app($workflow)->saveDraft(null, ['vessel_id' => $vessel->id, 'priority' => 'medium', 'price_mode' => 'manual', 'agent_fee' => ''] + $additional);

    expect($service->agent_fee)->toBe('0.00');
})->with([
    [BunkerWorkflow::class, []],
    [SafetyCertificateWorkflow::class, ['request_type' => 'new_issue']],
    [SijilWorkflow::class, ['process_type' => 'sign_on']],
    [ShipNationalityWorkflow::class, ['document_type' => 'surat_laut', 'request_type' => 'new_issue']],
]);

test('SPB form uses the chosen client price instead of a submitted manual amount', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $client = Client::factory()->create(['service_prices' => ['spb' => '765000.00']]);
    $vessel = Vessel::factory()->for($client)->create(['vessel_type' => 'cargo']);
    $input = [
        'vessel_id' => $vessel->id, 'assigned_staff_id' => $staff->id, 'priority' => 'medium', 'total_crew' => 4,
        'total_passengers' => 0, 'cargo_status' => 'not_applicable', 'port_cargo_activity_applicable' => false,
        'departure_port' => 'Makassar', 'destination' => 'Kupang', 'estimated_departure_at' => '2026-12-01T10:00',
        'estimated_port_activity_completion_at' => '2026-12-01T09:00', 'agent_fee' => '1', 'pnbp_labuh' => '100000', 'pnbp_mastercable' => '1000',
    ];
    Livewire::actingAs($staff)->test('pages::services.spb-form')->set('form', $input)->set('priceMode', 'client')
        ->set('documents.pnbp_labuh_transfer', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();
    expect((float) SpbService::firstOrFail()->agent_fee)->toBe(765000.0);
});

test('an admin can set the client price on an existing completed service', function () {
    $client = Client::factory()->create(['service_prices' => ['bunker' => '350000']]);
    $service = BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => null]);
    Livewire::actingAs(User::factory()->admin()->create())->test('dashboard.service-price-editor', ['type' => 'bunker', 'serviceId' => $service->id])
        ->set('priceMode', 'client')->call('save')->assertHasNoErrors()->assertSee('Harga berhasil disimpan.');
    expect((float) $service->fresh()->agent_fee)->toBe(350000.0);
    Livewire::actingAs(User::factory()->create())->test('dashboard.service-price-editor', ['type' => 'bunker', 'serviceId' => $service->id])->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('bunker.show', $service))->assertOk()->assertSee('Gunakan harga client');
});

test('an assigned staff member can apply the client price to an existing SPB', function () {
    $staff = User::factory()->create();
    $client = Client::factory()->create(['service_prices' => ['spb' => '650000']]);
    $service = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100000', 'assigned_staff_id' => $staff->id]);
    Livewire::actingAs($staff)->test('pages::services.spb-detail', ['spb' => $service])
        ->set('priceMode', 'client')->call('saveAgentFee')->assertHasNoErrors();
    expect((float) $service->fresh()->agent_fee)->toBe(650000.0);
});

test('service forms offer manual and client prices', function (string $type, string $page) {
    $client = Client::factory()->create(['service_prices' => [$type => '123456']]);
    $vessel = Vessel::factory()->for($client)->create(['vessel_type' => 'passenger']);
    Livewire::actingAs(User::factory()->create())->test('pages::services.'.$page)
        ->set('form.vessel_id', $vessel->id)->set('priceMode', 'client')
        ->assertSee('Tentukan nominal')->assertSee('Gunakan harga client')->assertSee('123.456,00');
})->with([
    'SPB' => ['spb', 'spb-form'],
    'Bunker' => ['bunker', 'bunker-form'],
    'Safety' => ['safety-certificates', 'safety-certificate-form'],
    'Sijil' => ['sijil', 'sijil-form'],
    'Nationality' => ['ship-nationality', 'ship-nationality-form'],
]);

test('service drafts assign the creating admin and their automatic task when no staff is selected', function (string $workflow, array $additional) {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'passenger']);

    $service = app($workflow)->saveDraft(null, ['vessel_id' => $vessel->id, 'priority' => 'medium', 'assigned_staff_id' => '', 'price_mode' => 'manual', 'agent_fee' => '1000'] + $additional);

    expect($service->assigned_staff_id)->toBe($admin->id);
    expect($service->tasks()->sole()->assigned_to)->toBe($admin->id);
})->with([
    'bunker' => [BunkerWorkflow::class, []],
    'safety' => [SafetyCertificateWorkflow::class, ['request_type' => 'new_issue']],
    'sijil' => [SijilWorkflow::class, ['process_type' => 'sign_on']],
    'nationality' => [ShipNationalityWorkflow::class, ['document_type' => 'surat_laut', 'request_type' => 'new_issue']],
]);
