<?php

use App\Models\User;
use App\Models\Vessel;
use App\Services\SpbWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('service pages no longer show client and the client page is unavailable', function () {
    $this->actingAs(User::factory()->admin()->create());
    foreach (['dashboard.vessel', 'spb.index', 'bunker.index', 'sijil.index', 'ship-nationality.index', 'safety-certificates.index', 'spb.create', 'bunker.create', 'sijil.create', 'ship-nationality.create', 'safety-certificates.create', 'reports.index'] as $route) {
        $this->get(route($route))->assertOk()->assertDontSee('Pilih client')->assertDontSee('Semua client');
    }
    $this->get('/dashboard/clients')->assertNotFound();
});

test('spb can be created for a vessel without a client', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $this->actingAs($user);
    $vessel = Vessel::factory()->create(['client_id' => null, 'owner' => 'Pemilik kapal']);
    $spb = app(SpbWorkflow::class)->saveDraft(null, [
        'vessel_id' => $vessel->id, 'assigned_staff_id' => $user->id,
        'priority' => 'medium', 'total_passengers' => 0, 'cargo_status' => 'not_applicable',
        'port_cargo_activity_applicable' => false, 'departure_port' => 'Makassar', 'destination' => 'Surabaya',
        'estimated_departure_at' => '2026-12-01T10:00',
    ], ['spb_file' => UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf')]);
    expect($spb->client_id)->toBeNull()->and($vessel->ownerName())->toBe('Pemilik kapal');
});
