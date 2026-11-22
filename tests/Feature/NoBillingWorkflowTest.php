<?php

use App\Models\Client;
use App\Models\User;
use App\Models\Vessel;
use App\Services\SpbWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

test('finance remains available without billing routes', function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->get(route('finance.index'))->assertOk()->assertSee('Finance')->assertDontSee('Service Billings');
    $this->get('/dashboard/finance/reports')->assertNotFound();
    expect(Route::has('finance.proof'))->toBeFalse();
});

test('spb draft needs no financial amounts and failed sailing needs no billing decision', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $this->actingAs($user);
    $vessel = Vessel::factory()->for(Client::factory())->create(['vessel_type' => 'passenger']);
    $workflow = app(SpbWorkflow::class);
    $spb = $workflow->saveDraft(null, [
        'vessel_id' => $vessel->id, 'client_id' => $vessel->client_id, 'assigned_staff_id' => $user->id,
        'priority' => 'medium', 'total_passengers' => 0, 'cargo_status' => 'not_applicable',
        'port_cargo_activity_applicable' => false, 'departure_port' => 'Makassar', 'destination' => 'Surabaya',
        'estimated_departure_at' => '2026-12-01T10:00',
    ], ['spb_file' => UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf')]);
    expect($spb->pnbp_labuh)->toBeNull();
    $spb->forceFill(['status' => 'ready_to_sail'])->save();
    $workflow->finish($spb, ['sailing_result' => 'failed', 'failure_reason' => 'Cuaca buruk']);
    expect($spb->fresh()->status)->toBe('completed');
});
