<?php

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('login shows the document expiry summary once with the overview warning counts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', config('app.timezone')));
    $user = User::factory()->create();
    $vessel = Vessel::factory()->create();
    foreach (['radio_certificate' => '2026-10-12', 'safety_certificate' => '2026-10-13', 'insurance' => '2026-11-02', 'load_line' => '2026-11-03', 'smc' => null] as $type => $date) {
        VesselDocument::factory()->for($vessel)->create(['document_type' => $type, 'expired_date' => $date]);
    }

    $this->post(route('login.store'), ['username' => $user->username, 'password' => 'password'])->assertRedirect(route('home'));

    $response = $this->get(route('home'));
    $response->assertSee('expiry-summary-title', false)->assertSee('Hari ini hingga 20 hari ke depan')
        ->assertSeeInOrder(['>2</p>', 'Akan kedaluwarsa', '>1</p>', 'Sudah kedaluwarsa'], false)
        ->assertDontSee('Login berhasil. Selamat datang kembali.');
    $this->get(route('home'))->assertDontSee('expiry-summary-title', false);
});

test('login summary displays zero when there are no expiring documents', function () {
    $user = User::factory()->create();
    $this->post(route('login.store'), ['username' => $user->username, 'password' => 'password']);

    $this->get(route('home'))->assertSee('expiry-summary-title', false)
        ->assertSeeInOrder(['>0</p>', 'Akan kedaluwarsa', '>0</p>', 'Sudah kedaluwarsa'], false)
        ->assertSee('Tidak ada dokumen yang kedaluwarsa atau akan kedaluwarsa dalam 20 hari.');
});

test('login summary remains available when login returns to an intended page', function () {
    $user = User::factory()->create();
    $this->get(route('dashboard.vessel'));
    $this->post(route('login.store'), ['username' => $user->username, 'password' => 'password'])->assertRedirect(route('dashboard.vessel'));
    $this->get(route('dashboard.vessel'))->assertDontSee('expiry-summary-title', false);

    $this->get(route('home'))->assertSee('expiry-summary-title', false);
    $this->get(route('home'))->assertDontSee('expiry-summary-title', false);
});

test('overview warns about vessel documents from twenty days before expiry through overdue', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', config('app.timezone')));
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create(['vessel_name' => 'KM Peringatan']);
    $expired = VesselDocument::factory()->for($vessel)->create(['document_type' => 'radio_certificate', 'expired_date' => '2026-10-12']);
    $today = VesselDocument::factory()->for($vessel)->create(['document_type' => 'safety_certificate', 'expired_date' => '2026-10-13']);
    $withinWindow = VesselDocument::factory()->for($vessel)->create(['document_type' => 'insurance', 'expired_date' => '2026-11-02']);
    VesselDocument::factory()->for($vessel)->create(['document_type' => 'load_line', 'expired_date' => '2026-11-03']);
    VesselDocument::factory()->for($vessel)->create(['document_type' => 'smc', 'expired_date' => null]);

    $page = Livewire::test('pages::dashboard.overview')
        ->assertSee('Notifikasi masa berlaku dokumen kapal')->assertSee('KM Peringatan')
        ->assertSee('Sudah kedaluwarsa')->assertSee('Kedaluwarsa hari ini')->assertSee('Kedaluwarsa dalam 20 hari')
        ->assertSee(route('vessels.edit', ['vessel' => $vessel->id]), false)
        ->assertDontSee('Ringkasan operasional', false)->assertDontSee('Completed Today');

    expect($page->get('certificateNotifications')->modelKeys())->toBe([$expired->id, $today->id, $withinWindow->id]);
    $this->get(route('vessels.edit', $vessel))->assertOk()->assertSee('Edit vessel')->assertSee('KM Peringatan');
});

test('overview displays an empty notification state', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::dashboard.overview')->assertSee('Tidak ada dokumen yang kedaluwarsa atau akan kedaluwarsa dalam 20 hari.');
});

test('overview navigation warns for upcoming and expired documents until overview is opened', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', config('app.timezone')));
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create();
    VesselDocument::factory()->for($vessel)->create(['document_type' => 'smc', 'expired_date' => '2026-11-03']);
    $navigation = Livewire::test('dashboard.overview-navigation')->assertDontSee('overview-warning-bell', false);
    $document = VesselDocument::factory()->for($vessel)->create(['document_type' => 'insurance', 'expired_date' => '2026-11-02']);

    $navigation->call('refreshWarnings')->assertSet('warningLevel', 'expiring')->assertSee('overview-warning-expiring', false)->assertSee('overview-warning-bell', false)
        ->assertSee('Ada dokumen kapal yang akan kedaluwarsa dalam 20 hari');
    $document->update(['expired_date' => '2026-10-12']);
    $navigation->call('refreshWarnings')->assertSee('overview-warning-expired', false)->assertSee('Ada dokumen kapal yang sudah kedaluwarsa');
    Livewire::test('dashboard.overview-navigation', ['active' => true])->assertDontSee('overview-warning-bell', false)->assertSee('aria-current="page"', false);
    $document->update(['expired_date' => null]);
    $navigation->call('refreshWarnings')->assertDontSee('overview-warning-bell', false);
});

test('overview warning is visible on another dashboard page', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', config('app.timezone')));
    $this->actingAs(User::factory()->create());
    VesselDocument::factory()->create(['expired_date' => '2026-10-13']);

    $this->get(route('dashboard.vessel'))->assertSee('overview-warning-expiring', false)->assertSee('overview-warning-bell', false);
});

test('overview navigation shows the total number of overdue and upcoming documents', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', config('app.timezone')));
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create();
    VesselDocument::factory()->for($vessel)->create(['document_type' => 'radio_certificate', 'expired_date' => '2026-10-12']);
    $upcoming = VesselDocument::factory()->for($vessel)->create(['document_type' => 'insurance', 'expired_date' => '2026-11-02']);
    VesselDocument::factory()->for($vessel)->create(['document_type' => 'smc', 'expired_date' => '2026-11-03']);
    VesselDocument::factory()->for($vessel)->create(['document_type' => 'load_line', 'expired_date' => null]);

    $navigation = Livewire::test('dashboard.overview-navigation')->assertSet('warningCount', 2)
        ->assertSee('data-testid="overview-warning-count"', false)
        ->assertSee('2 dokumen kapal kedaluwarsa atau akan kedaluwarsa dalam 20 hari');
    $upcoming->update(['expired_date' => '2026-11-03']);

    $navigation->call('refreshWarnings')->assertSet('warningCount', 1)
        ->assertSee('1 dokumen kapal kedaluwarsa atau akan kedaluwarsa dalam 20 hari');
});
