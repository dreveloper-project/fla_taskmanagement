<?php

use App\Models\BunkerService;
use App\Models\Client;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\BunkerReadinessService;
use App\Services\SafetyCertificateReadinessService;
use App\Services\ShipNationalityReadinessService;
use App\Services\SijilReadinessService;
use App\Services\SpbReadinessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('each service requires the vessel agency appointment letter', function (string $model, string $readiness) {
    Storage::fake('local');
    $vessel = Vessel::factory()->create();
    $service = $model::factory()->for($vessel)->create();
    $checks = app($readiness)->check($service)['vessel_checks'];
    $appointment = collect($checks)->firstWhere('label', 'Surat Penunjukan Keagenan');
    expect($appointment['required'])->toBeTrue()->and($appointment['complete'])->toBeFalse();

    $document = VesselDocument::factory()->for($vessel)->create(['document_type' => 'surat_penunjukan_keagenan']);
    Storage::disk('local')->put($document->file_path, 'appointment');
    $checks = app($readiness)->check($service->fresh())['vessel_checks'];
    expect(collect($checks)->firstWhere('label', 'Surat Penunjukan Keagenan')['complete'])->toBeTrue();
    Storage::disk('local')->delete($document->file_path);
    $checks = app($readiness)->check($service->fresh())['vessel_checks'];
    expect(collect($checks)->firstWhere('label', 'Surat Penunjukan Keagenan')['complete'])->toBeFalse();
})->with([
    'SPB' => [SpbService::class, SpbReadinessService::class],
    'Bunker' => [BunkerService::class, BunkerReadinessService::class],
    'Sijil' => [SijilService::class, SijilReadinessService::class],
    'Safety' => [SafetyCertificateService::class, SafetyCertificateReadinessService::class],
    'Nationality' => [ShipNationalityService::class, ShipNationalityReadinessService::class],
]);

test('SPB defaults to client price and saves without port activity completion', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $client = Client::factory()->create(['service_prices' => ['spb' => '765000']]);
    $vessel = Vessel::factory()->for($client)->create(['vessel_type' => 'cargo']);
    Livewire::actingAs($staff)->test('pages::services.spb-form')
        ->assertSet('priceMode', 'client')
        ->assertDontSee('Estimated Completion of Port Activities')
        ->set('form.vessel_id', $vessel->id)
        ->set('form.total_crew', 4)
        ->set('form.departure_port', 'Makassar')
        ->set('form.destination', 'Kupang')
        ->set('form.estimated_departure_at', '2026-12-01T10:00')
        ->set('form.pnbp_labuh', '100000')
        ->set('form.pnbp_mastercable', '1000')
        ->set('documents.pnbp_labuh_transfer', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();
    $spb = SpbService::firstOrFail();
    expect((float) $spb->agent_fee)->toBe(765000.0)->and($spb->estimated_port_activity_completion_at)->toBeNull();
    Livewire::actingAs($staff)->test('pages::services.spb-form', ['spb' => $spb])
        ->assertSet('priceMode', 'manual')->assertDontSee('Estimated Completion of Port Activities');
});

test('agency appointment letter can be uploaded to a vessel', function () {
    Storage::fake('local');
    $vessel = Vessel::factory()->create();
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('edit', $vessel->id)
        ->assertSee('Surat Penunjukan Keagenan')
        ->set('documents.surat_penunjukan_keagenan.file', UploadedFile::fake()->create('penunjukan.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();
    $document = $vessel->documents()->where('document_type', 'surat_penunjukan_keagenan')->firstOrFail();
    Storage::disk('local')->assertExists($document->file_path);
});
