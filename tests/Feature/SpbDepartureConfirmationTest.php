<?php

use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function departureReadySpb(User $pic): SpbService
{
    $spb = SpbService::factory()->create([
        'assigned_staff_id' => $pic->id, 'status' => 'ready_to_sail',
        'spb_number' => 'OFFICIAL-DEPARTURE-001', 'spb_issued_at' => '2026-10-06 02:00:00',
        'total_crew' => 10, 'total_passengers' => 0,
    ]);
    foreach (['liferaft', 'fire_fighting', 'life_jacket', 'emergency_equipment'] as $type) {
        $equipment = VesselDocument::factory()->for($spb->vessel)->readyEquipment($type)->create();
        Storage::disk('local')->put($equipment->file_path, '%PDF-1.4 equipment');
    }
    $document = SpbDocument::factory()->for($spb, 'spbService')->create(['document_type' => 'issued_spb']);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 issued');

    return $spb;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Asia/Makassar'));
});

test('PIC and admin record an SPB outcome directly without a departure step', function (string $role) {
    Storage::fake('local');
    $pic = User::factory()->create();
    $actor = $role === 'admin' ? User::factory()->admin()->create() : $pic;
    $spb = departureReadySpb($pic);

    Livewire::actingAs($actor)->test('pages::services.spb-detail', ['spb' => $spb])
        ->assertDontSee('wire:submit="confirmDeparture"', false)
        ->set('form.sailing_result', 'not_sailing')->set('form.billable', '0')
        ->call('finish')->assertHasNoErrors()->assertDispatched('vessel-status-updated');

    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'status' => 'completed', 'sailing_result' => 'not_sailing', 'billable' => false, 'actual_departure_at' => null, 'confirmed_by' => $actor->id]);
    $this->assertDatabaseHas('vessels', ['id' => $spb->vessel_id, 'operational_status' => 'berthed']);
    $this->assertDatabaseHas('spb_status_histories', ['spb_service_id' => $spb->id, 'event' => 'Not Sailing', 'from_status' => 'ready_to_sail', 'to_status' => 'completed', 'changed_by' => $actor->id]);
})->with(['staff', 'admin']);

test('vessel card links to the correct SPB result without a departure dialog', function () {
    Storage::fake('local');
    $pic = User::factory()->create();
    $spb = departureReadySpb($pic);
    $other = Vessel::factory()->create(['operational_status' => 'berthed']);

    Livewire::actingAs($pic)->test('pages::dashboard.vessel')
        ->assertDontSee('wire:click="openDeparture('.$spb->id.')"', false)
        ->assertSee(route('spb.show', $spb), false)
        ->call('openDeparture', $spb->id)->assertRedirect(route('spb.show', $spb));

    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'status' => 'ready_to_sail']);
    $this->assertDatabaseHas('vessels', ['id' => $spb->vessel_id, 'operational_status' => 'berthed']);
    $this->assertDatabaseHas('vessels', ['id' => $other->id, 'operational_status' => 'berthed']);
    expect($spb->histories()->count())->toBe(0);
});

test('removed departure actions on both screens leave the service unchanged', function (string $screen) {
    Storage::fake('local');
    $pic = User::factory()->create();
    $spb = departureReadySpb($pic);
    $this->actingAs($pic);
    if ($screen === 'spb') {
        Livewire::test('pages::services.spb-detail', ['spb' => $spb])
            ->call('confirmDeparture')->assertHasErrors('state')->assertNotDispatched('vessel-status-updated');
    } else {
        Livewire::test('pages::dashboard.vessel')->call('openDeparture', $spb->id)
            ->assertRedirect(route('spb.show', $spb))->assertNotDispatched('vessel-status-updated');
    }
    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'status' => 'ready_to_sail', 'actual_departure_at' => null]);
    $this->assertDatabaseHas('vessels', ['id' => $spb->vessel_id, 'operational_status' => 'berthed']);
    expect($spb->histories()->count())->toBe(0);
})->with(['spb', 'vessel']);

test('other staff cannot confirm departure from either entry point', function () {
    $spb = SpbService::factory()->create(['assigned_staff_id' => User::factory()->create()->id, 'status' => 'ready_to_sail']);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::services.spb-detail', ['spb' => $spb])
        ->assertDontSee('wire:submit="confirmDeparture"', false)->call('confirmDeparture')->assertForbidden();
    Livewire::test('pages::dashboard.vessel')
        ->assertDontSee('wire:click="openDeparture('.$spb->id.')"', false)
        ->call('openDeparture', $spb->id)->assertForbidden();

    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'status' => 'ready_to_sail', 'actual_departure_at' => null]);
    expect($spb->histories()->count())->toBe(0);
});

test('a completed SPB result cannot be recorded again from an already open page', function () {
    Storage::fake('local');
    $pic = User::factory()->create();
    $spb = departureReadySpb($pic);
    $this->actingAs($pic);
    $first = Livewire::test('pages::services.spb-detail', ['spb' => $spb]);
    $stale = Livewire::test('pages::services.spb-detail', ['spb' => $spb]);
    $first->set('form.sailing_result', 'not_sailing')->set('form.billable', '0')->call('finish')->assertHasNoErrors();
    $stale->set('form.sailing_result', 'not_sailing')->set('form.billable', '1')->call('finish')->assertHasErrors('state');
    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'status' => 'completed', 'billable' => false]);
    expect($spb->histories()->where('event', 'Not Sailing')->count())->toBe(1);
});

test('cancelling departure from a card keeps the vessel berthed', function () {
    $pic = User::factory()->create();
    $spb = SpbService::factory()->create(['assigned_staff_id' => $pic->id, 'status' => 'ready_to_sail']);

    Livewire::actingAs($pic)->test('pages::dashboard.vessel')->call('openDeparture', $spb->id)
        ->set('departure.actual_departure_at', '2026-10-06T11:00')
        ->call('cancelDeparture')->assertSet('departureSpbId', null)->assertSet('departure.actual_departure_at', '');

    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'status' => 'ready_to_sail']);
    $this->assertDatabaseHas('vessels', ['id' => $spb->vessel_id, 'operational_status' => 'berthed']);
    expect($spb->histories()->count())->toBe(0);
});

test('departure shortcuts are hidden when the voyage is not ready to leave', function (string $status, string $vesselStatus, ?string $departure) {
    $pic = User::factory()->create();
    $vessel = Vessel::factory()->create(['operational_status' => $vesselStatus]);
    $spb = SpbService::factory()->for($vessel)->create([
        'assigned_staff_id' => $pic->id, 'status' => $status, 'actual_departure_at' => $departure,
    ]);
    $this->actingAs($pic);

    $this->get(route('dashboard.vessel'))->assertDontSee('wire:click="openDeparture(', false);
    $this->get(route('spb.show', $spb))->assertDontSee('wire:submit="confirmDeparture"', false);
})->with([
    'draft' => ['draft', 'berthed', null],
    'sailing' => ['sailing', 'sailing', '2026-10-06 03:00:00'],
    'maintenance' => ['ready_to_sail', 'maintenance', null],
    'already departed' => ['ready_to_sail', 'berthed', '2026-10-06 03:00:00'],
]);
