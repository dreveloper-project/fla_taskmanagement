<?php

use App\Models\BunkerService;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Services\BunkerWorkflow;
use App\Services\ReportFilters;
use App\Services\ReportService;
use App\Services\SafetyCertificateWorkflow;
use App\Services\ServiceArchive;
use App\Services\ServiceListFilters;
use App\Services\ShipNationalityWorkflow;
use App\Services\SijilWorkflow;
use App\Services\SpbWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
});

test('all service forms and lists omit priority', function () {
    foreach (['spb', 'bunker', 'sijil', 'safety-certificate', 'ship-nationality'] as $type) {
        Livewire::test('pages::services.'.$type.'-form')->assertDontSee('Priority');
    }
    foreach (['spb', 'bunker', 'sijil', 'safety-certificates', 'ship-nationality'] as $type) {
        Livewire::test('pages::services.'.$type)->assertDontSee('Priority')->assertDontSee('priority');
    }
    expect((new ReportService(ReportFilters::validate([])))->columns('services'))->not->toHaveKey('priority');
});

test('every service can be created without priority', function () {
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger']);
    $spb = app(SpbWorkflow::class)->saveDraft(null, [
        'vessel_id' => $vessel->id, 'total_passengers' => 0, 'cargo_status' => 'not_applicable',
        'port_cargo_activity_applicable' => false, 'departure_port' => 'Makassar', 'destination' => 'Surabaya',
        'estimated_departure_at' => '2026-12-01T10:00',
    ], ['spb_file' => UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf')]);
    $bunker = app(BunkerWorkflow::class)->saveDraft(null, ['vessel_id' => $vessel->id]);
    $safety = app(SafetyCertificateWorkflow::class)->saveDraft(null, ['vessel_id' => $vessel->id, 'request_type' => 'new_issue']);
    $nationality = app(ShipNationalityWorkflow::class)->saveDraft(null, ['vessel_id' => $vessel->id, 'document_type' => 'surat_laut', 'request_type' => 'new_issue']);
    $sijil = app(SijilWorkflow::class)->saveDraft(null, ['vessel_id' => $vessel->id, 'process_type' => 'sign_on']);
    foreach ([$spb, $bunker, $safety, $nationality, $sijil] as $service) {
        expect($service->exists)->toBeTrue();
    }
});

test('old priority values do not affect filters, edits or exported archives', function () {
    $first = BunkerService::factory()->create(['status' => 'completed', 'priority' => 'high']);
    $second = BunkerService::factory()->create(['status' => 'completed', 'priority' => 'low']);
    $query = app(ServiceListFilters::class)->apply(BunkerService::query(), ['priority' => 'high']);
    expect($query->count())->toBe(2);
    $editable = BunkerService::factory()->create(['priority' => 'high']);
    app(BunkerWorkflow::class)->saveDraft($editable, ['vessel_id' => $editable->vessel_id, 'priority' => 'low']);
    expect($editable->fresh()->priority)->toBe('high');
    $path = app(ServiceArchive::class)->createCompletedServices(BunkerService::query()->whereKey([$first->id, $second->id]));
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $json = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_ends_with($zip->getNameIndex($i), 'Data-Layanan.json')) {
            $json[] = json_decode($zip->getFromIndex($i), true, flags: JSON_THROW_ON_ERROR);
        }
    }
    expect($json)->toHaveCount(2);
    foreach ($json as $row) {
        expect($row['service'])->not->toHaveKey('priority');
    }
    $zip->close();
    unlink($path);
});

test('service detail pages and reports render without priority', function (string $class, string $route) {
    $service = $class::factory()->create();
    $this->get(route($route, $service))->assertOk()->assertDontSee('Priority');
    $this->get(route('reports.index'))->assertOk()->assertDontSee('Priority');
})->with([
    [SpbService::class, 'spb.show'],
    [BunkerService::class, 'bunker.show'],
    [SafetyCertificateService::class, 'safety-certificates.show'],
    [ShipNationalityService::class, 'ship-nationality.show'],
    [SijilService::class, 'sijil.show'],
]);
