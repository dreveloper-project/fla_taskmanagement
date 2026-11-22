<?php

use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Services\SpbWorkflow;
use App\Services\TaskAutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function arrivingSpb(User $staff, array $overrides = []): SpbService
{
    $vessel = Vessel::factory()->create([
        'operational_status' => 'sailing', 'sailed_at' => '2026-10-12 02:00:00',
    ]);

    return SpbService::factory()->create(array_replace([
        'vessel_id' => $vessel->id, 'assigned_staff_id' => $staff->id,
        'status' => 'sailing', 'actual_departure_at' => '2026-10-12 02:00:00',
    ], $overrides));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 04:00:00', 'UTC'));
});

test('PIC records the legacy SPB result without changing vessel status', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $this->actingAs($staff);
    $spb = arrivingSpb($staff);
    $monitor = Livewire::test('dashboard.sailing-monitoring')->assertSee($spb->vessel->vessel_name);

    Livewire::test('pages::services.spb-detail', ['spb' => $spb])
        ->assertDontSee('Konfirmasi Tiba / Selesai Berlayar')
        ->set('form.sailing_result', 'not_sailing')->set('form.billable', '0')
        ->call('finish')->assertHasNoErrors()->assertDispatched('vessel-status-updated');

    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'status' => 'completed', 'sailing_result' => 'not_sailing', 'billable' => false, 'actual_arrival_at' => null]);
    $this->assertDatabaseHas('vessels', ['id' => $spb->vessel_id, 'operational_status' => 'sailing']);
    $this->assertDatabaseHas('spb_status_histories', ['spb_service_id' => $spb->id, 'event' => 'Not Sailing', 'from_status' => 'sailing', 'to_status' => 'completed', 'changed_by' => $staff->id]);
    $monitor->call('$refresh')->assertSee($spb->vessel->vessel_name)->assertDontSee($spb->service_number);
});

test('an archived SPB rejects the removed arrival action without changing its dates', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $spb = arrivingSpb($staff, ['status' => 'completed', 'sailing_result' => 'success', 'completed_at' => '2026-10-12 02:05:00', 'archived_at' => '2026-10-12 02:05:00']);

    Livewire::test('pages::services.spb-detail', ['spb' => $spb])
        ->assertDontSee('Konfirmasi Tiba / Selesai Berlayar')
        ->set('arrival.actual_arrival_at', '2026-10-12T12:00')->call('confirmArrival')->assertHasErrors('state');

    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'actual_arrival_at' => null, 'arrival_confirmed_by' => null, 'completed_at' => '2026-10-12 02:05:00', 'archived_at' => '2026-10-12 02:05:00']);
});

test('invalid arrival input changes neither SPB nor vessel', function (string $time, string $message) {
    $staff = User::factory()->create(['role' => 'staff']);
    $this->actingAs($staff);
    $spb = arrivingSpb($staff);

    Livewire::test('pages::services.spb-detail', ['spb' => $spb])
        ->set('arrival.actual_arrival_at', $time)->call('confirmArrival')
        ->assertHasErrors('state');

    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'status' => 'sailing', 'actual_arrival_at' => null]);
    $this->assertDatabaseHas('vessels', ['id' => $spb->vessel_id, 'operational_status' => 'sailing']);
    expect($spb->histories()->count())->toBe(0);
})->with([
    'required' => ['', 'Waktu tiba wajib diisi.'],
    'invalid date' => ['invalid', 'Waktu tiba harus berupa tanggal dan jam yang valid.'],
    'future' => ['2026-10-12T12:01', 'Waktu tiba tidak boleh berada di masa depan.'],
    'before departure' => ['2026-10-12T09:59', 'Waktu tiba tidak boleh mendahului waktu keberangkatan.'],
]);

test('a different PIC cannot confirm arrival even through a direct action', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $spb = arrivingSpb($staff);
    $this->actingAs(User::factory()->create(['role' => 'staff']));

    Livewire::test('pages::services.spb-detail', ['spb' => $spb])
        ->assertDontSee('Konfirmasi Tiba / Selesai Berlayar')
        ->assertDontSee('Konfirmasi kedatangan kapal')
        ->set('arrival.actual_arrival_at', '2026-10-12T11:00')->call('confirmArrival')->assertForbidden();

    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'actual_arrival_at' => null]);
    $this->assertDatabaseHas('vessels', ['id' => $spb->vessel_id, 'operational_status' => 'sailing']);
});

test('PIC reassignment after opening the page revokes the arrival action', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $this->actingAs($staff);
    $spb = arrivingSpb($staff);
    $page = Livewire::test('pages::services.spb-detail', ['spb' => $spb]);
    $spb->update(['assigned_staff_id' => User::factory()->create(['role' => 'staff'])->id]);

    $page->set('arrival.actual_arrival_at', '2026-10-12T11:00')->call('confirmArrival')->assertForbidden();

    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'actual_arrival_at' => null]);
});

test('a recorded legacy SPB outcome cannot be changed by an arrival action', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $this->actingAs($staff);
    $spb = arrivingSpb($staff);
    $workflow = app(SpbWorkflow::class);
    $workflow->finish($spb, ['sailing_result' => 'not_sailing', 'billable' => false]);

    expect(fn () => $workflow->confirmArrival($spb, ['actual_arrival_at' => '2026-10-12T11:30']))->toThrow(ValidationException::class);
    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'status' => 'completed', 'sailing_result' => 'not_sailing', 'billable' => false, 'actual_arrival_at' => null]);
    expect($spb->histories()->count())->toBe(1);
});

test('arrival rejects incompatible states and historical departures', function (string $condition) {
    $staff = User::factory()->create(['role' => 'staff']);
    $this->actingAs($staff);
    $spb = arrivingSpb($staff);
    match ($condition) {
        'draft', 'ready_to_sail', 'failed_to_sail' => $spb->update(['status' => $condition]),
        'no departure' => $spb->update(['actual_departure_at' => null]),
        'berthed' => $spb->vessel->update(['operational_status' => 'berthed']),
        'new voyage' => $spb->vessel->forceFill(['sailed_at' => '2026-10-12 03:00:00'])->save(),
    };

    Livewire::test('pages::services.spb-detail', ['spb' => $spb])->assertDontSee('Konfirmasi Tiba / Selesai Berlayar')
        ->set('arrival.actual_arrival_at', '2026-10-12T11:30')->call('confirmArrival')->assertHasErrors('state');

    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'actual_arrival_at' => null]);
    expect($spb->histories()->count())->toBe(0);
})->with(['draft', 'ready_to_sail', 'failed_to_sail', 'no departure', 'berthed', 'new voyage']);

test('a failure during synchronization rolls back the outcome and its history', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $this->actingAs($staff);
    $spb = arrivingSpb($staff);
    $this->mock(TaskAutomationService::class)->shouldReceive('syncForService')->once()->andThrow(new RuntimeException('Sync failed'));

    expect(fn () => app(SpbWorkflow::class)->finish($spb, ['sailing_result' => 'not_sailing', 'billable' => false]))
        ->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('spb_services', ['id' => $spb->id, 'status' => 'sailing', 'actual_arrival_at' => null]);
    $this->assertDatabaseHas('vessels', ['id' => $spb->vessel_id, 'operational_status' => 'sailing']);
    expect($spb->histories()->count())->toBe(0);
});
