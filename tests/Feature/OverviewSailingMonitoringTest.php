<?php

use App\Models\Client;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use Livewire\Livewire;

test('overview displays the sailing monitor and its empty state', function () {
    $this->actingAs(User::factory()->create(['role' => 'staff']));

    $this->get(route('home'))->assertSee('Kapal sedang berlayar')
        ->assertSee('Belum ada kapal yang sedang berlayar.');
});

test('monitor lists only sailing vessels with their matching voyage and local departure time', function () {
    $staff = User::factory()->create(['role' => 'staff', 'name' => 'Andi Pelayaran']);
    $this->actingAs($staff);
    $client = Client::factory()->create(['name' => 'PT Armada Nusantara']);
    $vessel = Vessel::factory()->create([
        'client_id' => $client->id, 'vessel_name' => 'KM Pemantauan',
        'operational_status' => 'sailing', 'sailed_at' => '2026-10-12 02:30:00',
    ]);
    Vessel::factory()->create(['vessel_name' => 'Kapal Sandar Saja']);
    Vessel::factory()->create(['vessel_name' => 'Kapal Perawatan Saja', 'operational_status' => 'maintenance']);
    $service = SpbService::factory()->create([
        'vessel_id' => $vessel->id, 'assigned_staff_id' => $staff->id,
        'status' => 'sailing', 'actual_departure_at' => '2026-10-12 02:30:00',
        'expected_arrival_at' => '2026-10-13 04:00:00',
        'departure_port' => 'Pelabuhan Makassar', 'destination' => 'Pelabuhan Kupang',
    ]);

    Livewire::test('dashboard.sailing-monitoring')
        ->assertSee('KM Pemantauan')->assertSee('PT Armada Nusantara')
        ->assertSee('Pelabuhan Makassar')->assertSee('Pelabuhan Kupang')
        ->assertSee('12 Oct 2026 10:30')->assertSee('Andi Pelayaran')
        ->assertSee('Perkiraan tiba (ETA)')->assertSee('13 Oct 2026 12:00')
        ->assertSee(route('spb.show', $service), false)
        ->assertSee(route('dashboard.vessel', ['vessel' => $vessel->id]), false)
        ->assertDontSee('Kapal Sandar Saja')->assertDontSee('Kapal Perawatan Saja');
});

test('sailing vessels without voyage data display placeholders', function () {
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    Vessel::factory()->create(['operational_status' => 'sailing', 'vessel_name' => 'Kapal Tanpa SPB']);

    Livewire::test('dashboard.sailing-monitoring')
        ->assertSee('Kapal Tanpa SPB')->assertSee('Informasi rute belum tersedia.')
        ->assertSee('Belum tercatat')->assertSee('Belum ditentukan')->assertDontSee('Detail SPB');
});

test('completed SPB retains voyage details while older voyages are excluded', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $vessel = Vessel::factory()->create(['operational_status' => 'sailing', 'sailed_at' => '2026-10-12 02:30:00']);
    SpbService::factory()->create([
        'vessel_id' => $vessel->id, 'status' => 'completed', 'sailing_result' => 'success',
        'actual_departure_at' => '2026-10-12 02:30:00', 'destination' => 'Tujuan Saat Ini',
    ]);
    SpbService::factory()->create([
        'vessel_id' => $vessel->id, 'status' => 'completed', 'sailing_result' => 'success',
        'actual_departure_at' => '2026-10-10 02:30:00', 'destination' => 'Tujuan Historis',
    ]);
    $stale = Vessel::factory()->create(['operational_status' => 'sailing', 'sailed_at' => '2026-10-12 04:30:00']);
    SpbService::factory()->create([
        'vessel_id' => $stale->id, 'status' => 'completed', 'actual_departure_at' => '2026-10-10 02:30:00',
        'destination' => 'Tujuan Lama Kapal Lain',
    ]);

    Livewire::test('dashboard.sailing-monitoring')->assertSee('Tujuan Saat Ini')
        ->assertDontSee('Tujuan Historis')->assertDontSee('Tujuan Lama Kapal Lain');
});

test('monitor paginates vessels and counts the complete sailing fleet', function () {
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    Vessel::factory()->count(6)->create(['operational_status' => 'sailing']);
    Vessel::factory()->create(['operational_status' => 'sailing', 'vessel_name' => 'Kapal Terbaru']);

    $page = Livewire::test('dashboard.sailing-monitoring')->assertSee('Kapal Terbaru');
    expect($page->get('sailingVessels')->total())->toBe(7);
    expect($page->get('sailingVessels')->count())->toBe(6);
    $page->call('setPage', 2, 'sailingPage')->assertDontSee('Kapal Terbaru');
    expect($page->get('sailingVessels')->count())->toBe(1);
});

test('refresh adds departing vessels and removes vessels returning to berth', function () {
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    $vessel = Vessel::factory()->create(['vessel_name' => 'Kapal Pergantian Status']);
    $page = Livewire::test('dashboard.sailing-monitoring')->assertDontSee('Kapal Pergantian Status');

    $vessel->update(['operational_status' => 'sailing']);
    $page->call('$refresh')->assertSee('Kapal Pergantian Status');
    $vessel->update(['operational_status' => 'berthed']);
    $page->call('$refresh')->assertDontSee('Kapal Pergantian Status')->assertSee('Belum ada kapal yang sedang berlayar.');
});

test('status events refresh voyage details and remove vessels that have arrived', function () {
    $staff = User::factory()->create(['name' => 'PIC Lama']);
    $replacement = User::factory()->create(['name' => 'PIC Terbaru']);
    $vessel = Vessel::factory()->create(['operational_status' => 'sailing', 'vessel_name' => 'Kapal Data Terkini']);
    $spb = SpbService::factory()->for($vessel)->create([
        'status' => 'sailing', 'actual_departure_at' => '2026-10-12 02:00:00',
        'destination' => 'Tujuan Awal', 'assigned_staff_id' => $staff->id,
        'expected_arrival_at' => '2026-10-13 02:00:00',
    ]);
    $page = Livewire::actingAs($staff)->test('dashboard.sailing-monitoring')
        ->assertSee('Tujuan Awal')->assertSee('PIC Lama');
    $spb->update([
        'destination' => 'Tujuan Terbaru', 'assigned_staff_id' => $replacement->id,
        'expected_arrival_at' => '2026-10-14 03:00:00',
    ]);

    $page->dispatch('vessel-status-updated')
        ->assertSee('Tujuan Terbaru')->assertSee('PIC Terbaru')->assertSee('14 Oct 2026 11:00')
        ->assertDontSee('Tujuan Awal')->assertDontSee('PIC Lama');
    $vessel->update(['operational_status' => 'berthed']);
    $page->dispatch('vessel-status-updated')->assertDontSee('Kapal Data Terkini')
        ->assertSee('Belum ada kapal yang sedang berlayar.');
});

test('polling returns to a valid page when arrivals remove the last page', function () {
    $this->actingAs(User::factory()->create());
    $vessels = Vessel::factory()->count(7)->create(['operational_status' => 'sailing']);
    $page = Livewire::test('dashboard.sailing-monitoring')->call('setPage', 2, 'sailingPage');
    $vessels->first()->update(['operational_status' => 'berthed']);

    $page->call('refreshSailingVessels')->assertSet('paginators.sailingPage', 1)
        ->assertDontSee('Belum ada kapal yang sedang berlayar.');
    expect($page->get('sailingVessels')->total())->toBe(6);
    expect($page->get('sailingVessels')->count())->toBe(6);

    Vessel::query()->update(['operational_status' => 'berthed']);
    $page->call('refreshSailingVessels')->assertSet('paginators.sailingPage', 1)
        ->assertSee('Belum ada kapal yang sedang berlayar.');
    expect($page->get('sailingVessels')->total())->toBe(0);
});

test('monitor rejects users outside the staff system', function () {
    $this->actingAs(User::factory()->create(['role' => 'external']));

    Livewire::test('dashboard.sailing-monitoring')->assertForbidden();
});

test('monitor escapes vessel and voyage input', function () {
    $this->actingAs(User::factory()->create(['role' => 'staff']));
    $vessel = Vessel::factory()->create([
        'operational_status' => 'sailing', 'vessel_name' => '<script>vessel()</script>',
        'sailed_at' => '2026-10-12 02:30:00',
    ]);
    SpbService::factory()->create([
        'vessel_id' => $vessel->id, 'status' => 'sailing', 'actual_departure_at' => '2026-10-12 02:30:00',
        'destination' => '<script>destination()</script>',
    ]);

    Livewire::test('dashboard.sailing-monitoring')->assertSee('&lt;script&gt;vessel()', false)
        ->assertDontSee('<script>vessel()</script>', false)->assertDontSee('<script>destination()</script>', false);
});
