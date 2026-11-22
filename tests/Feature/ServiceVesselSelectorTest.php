<?php

use App\Models\BunkerService;
use App\Models\Client;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use Livewire\Livewire;

test('service forms provide a searchable vessel selector and update the selected client', function (string $page) {
    $client = Client::factory()->create(['name' => 'Client Pencarian']);
    $vessel = Vessel::factory()->for($client)->create(['vessel_type' => 'passenger', 'vessel_name' => 'Kapal Pencarian']);
    Livewire::actingAs(User::factory()->create())->test('pages::services.'.$page)
        ->assertSee('Pilih Vessel atau Kapal')
        ->assertSee('Cari dan pilih vessel atau kapal')
        ->assertSee('Kapal Pencarian')
        ->assertSeeHtml('role="combobox"')
        ->set('form.vessel_id', $vessel->id)
        ->assertSet('form.vessel_id', $vessel->id)
        ->assertSee('Client Pencarian');
})->with(['spb-form', 'bunker-form', 'sijil-form', 'safety-certificate-form', 'ship-nationality-form']);

test('existing service forms retain their selected vessel', function (string $page, string $model, string $parameter) {
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger', 'vessel_name' => 'Kapal Terkunci']);
    $service = $model::factory()->for($vessel)->create();
    Livewire::actingAs(User::factory()->admin()->create())->test('pages::services.'.$page, [$parameter => $service])
        ->assertSet('form.vessel_id', $vessel->id)
        ->assertSee('Kapal Terkunci')
        ->assertSeeHtml('disabled');
})->with([
    ['spb-form', SpbService::class, 'spb'],
    ['bunker-form', BunkerService::class, 'service'],
    ['sijil-form', SijilService::class, 'service'],
    ['safety-certificate-form', SafetyCertificateService::class, 'service'],
    ['ship-nationality-form', ShipNationalityService::class, 'service'],
]);
