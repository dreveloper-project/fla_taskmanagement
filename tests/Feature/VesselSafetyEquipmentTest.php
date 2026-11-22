<?php

use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\BunkerReadinessService;
use App\Services\BunkerWorkflow;
use App\Services\SafetyCertificateReadinessService;
use App\Services\SpbReadinessService;
use App\Services\SpbWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once dirname(__DIR__).'/SafetyCertificateFixtures.php';
require_once dirname(__DIR__).'/BunkerFixtures.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 16:00:00', 'Asia/Makassar'));
    Storage::fake('local');
});

/** @param list<string> $types */
function attachReadyEquipment(Vessel $vessel, array $types): void
{
    foreach ($types as $type) {
        $document = VesselDocument::factory()->for($vessel)->readyEquipment($type)->create();
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 equipment record');
    }
    $vessel->unsetRelation('documents');
}

test('staff upload safety equipment evidence with quantities and conditions on vessel', function () {
    $vessel = Vessel::factory()->create();
    $component = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')->call('manageDocuments', $vessel->id);
    foreach (VesselDocument::EQUIPMENT_TYPES as $type) {
        $component->set('documents.'.$type.'.file', safetyPdf($type.'.pdf'))
            ->set('documents.'.$type.'.equipment', ['condition' => 'ready', 'quantity' => 60, 'capacity' => $type === 'liferaft' ? 60 : null, 'notes' => 'Kondisi baik']);
    }

    $component->call('save')->assertHasNoErrors()->assertRedirect(route('dashboard.vessel'));

    expect($vessel->documents()->count())->toBe(6);
    foreach ($vessel->documents()->get() as $document) {
        expect($document->equipment['condition'])->toBe('ready')
            ->and((int) $document->equipment['quantity'])->toBe(60);
        Storage::disk('local')->assertExists($document->file_path);
        $this->get(route('vessel-documents.download', [$vessel->id, $document->id]))->assertOk();
    }
});

test('equipment inputs reject unsafe values without saving records', function (string $field, mixed $value) {
    $vessel = Vessel::factory()->create();
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('manageDocuments', $vessel->id)
        ->set('documents.life_jacket.file', safetyPdf())
        ->set('documents.life_jacket.equipment.'.$field, $value)
        ->call('save')->assertHasErrors('documents.life_jacket.equipment.'.$field);

    expect($vessel->documents()->count())->toBe(0);
})->with([['quantity', -1], ['capacity', '1.5'], ['condition', 'anything'], ['notes', str_repeat('a', 2001)]]);

test('not applicable records require an explanation and documentary evidence', function () {
    $vessel = Vessel::factory()->create(['vessel_type' => 'cargo']);
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('manageDocuments', $vessel->id)
        ->set('documents.liferaft.equipment.condition', 'not_applicable')
        ->call('save')->assertHasErrors(['documents.liferaft.file', 'documents.liferaft.equipment.notes']);

    expect($vessel->documents()->count())->toBe(0);
});

test('equipment condition changes keep the original evidence and archive the previous inspection', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $vessel = Vessel::factory()->create();
    attachReadyEquipment($vessel, ['life_jacket']);
    $document = $vessel->documents()->sole();
    $path = $document->file_path;

    Livewire::test('pages::dashboard.vessel')->call('manageDocuments', $vessel->id)
        ->set('documents.life_jacket.equipment.condition', 'not_ready')
        ->call('save')->assertHasNoErrors();

    expect($document->fresh()->file_path)->toBe($path)
        ->and($document->fresh()->equipment['condition'])->toBe('not_ready')
        ->and($document->versions()->sole()->equipment['condition'])->toBe('ready');
    Storage::disk('local')->assertExists($path);
});

test('safety certificate readiness requires each of the five safety equipment records', function (string $type) {
    $service = safetyDraft();
    $readiness = app(SafetyCertificateReadinessService::class);
    expect($readiness->check($service)['complete'])->toBeTrue();
    $document = $service->vessel->documents->firstWhere('document_type', $type);
    Storage::disk('local')->delete($document->file_path);

    expect(fn () => $readiness->ensureReady($service))->toThrow(ValidationException::class);
})->with(['liferaft', 'fire_fighting', 'life_jacket', 'life_buoy', 'emergency_equipment']);

test('SPB passenger checks use voyage headcount and departure expiry', function (string $type, array $changes, string $message) {
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger']);
    attachReadyEquipment($vessel, ['liferaft', 'fire_fighting', 'life_jacket', 'emergency_equipment']);
    $document = $vessel->documents()->where('document_type', $type)->sole();
    $document->update($changes);

    $checks = app(SpbReadinessService::class)->equipmentCheck($vessel->fresh(), CarbonImmutable::parse('2026-10-10'), 60);
    $failed = collect($checks)->firstWhere('complete', false);

    expect($failed['status'])->toContain($message);
})->with([
    ['liferaft', ['equipment' => ['condition' => 'ready', 'quantity' => 2, 'capacity' => 59]], 'Kapasitas'],
    ['life_jacket', ['equipment' => ['condition' => 'ready', 'quantity' => 59]], 'Jumlah Life Jacket'],
    ['fire_fighting', ['equipment' => ['condition' => 'not_ready', 'quantity' => 3]], 'belum dinyatakan siap'],
    ['liferaft', ['expired_date' => '2026-10-09'], 'Kedaluwarsa'],
    ['liferaft', ['expired_date' => null], 'kedaluwarsa belum diisi'],
    ['life_jacket', ['expired_date' => '2026-10-09'], 'Kedaluwarsa'],
    ['emergency_equipment', ['issued_date' => '2026-10-11'], 'belum berlaku'],
]);

test('cargo SPB accepts documented non applicability but passenger SPB does not', function (string $vesselType, bool $complete) {
    $vessel = Vessel::factory()->create(['vessel_type' => $vesselType]);
    attachReadyEquipment($vessel, ['liferaft', 'fire_fighting', 'life_jacket', 'life_buoy', 'emergency_equipment']);
    foreach ($vessel->documents()->get() as $document) {
        $document->update(['expired_date' => null, 'equipment' => ['condition' => 'not_applicable', 'notes' => 'Tidak disyaratkan menurut record applicability kapal ini']]);
    }

    $checks = app(SpbReadinessService::class)->equipmentCheck($vessel->fresh());

    expect(collect($checks)->every('complete', true))->toBe($complete);
})->with([['cargo', true], ['passenger', false]]);

test('SPB departure rechecks equipment changed after approval', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger', 'operational_status' => 'berthed']);
    attachReadyEquipment($vessel, ['liferaft', 'fire_fighting', 'life_jacket', 'emergency_equipment']);
    $spb = SpbService::factory()->for($vessel)->create(['status' => 'ready_to_sail', 'assigned_staff_id' => $staff->id, 'total_crew' => 10, 'total_passengers' => 50, 'spb_issued_at' => now()->subHours(2)]);
    $issued = SpbDocument::factory()->for($spb, 'spbService')->create(['document_type' => 'issued_spb']);
    Storage::disk('local')->put($issued->file_path, '%PDF-1.4 SPB');
    $vessel->documents()->where('document_type', 'life_jacket')->update(['equipment' => json_encode(['condition' => 'not_ready', 'quantity' => 60])]);

    expect(fn () => app(SpbWorkflow::class)->depart($spb, ['actual_departure_at' => '2026-10-07T15:00', 'expected_arrival_at' => '2026-10-08T09:00']))->toThrow(ValidationException::class);
    expect($spb->fresh()->status)->toBe('ready_to_sail')->and($vessel->fresh()->operational_status)->toBe('berthed');
});

test('Bunker only requires PMK and SOPEP and rechecks their readiness at execution', function () {
    $service = bunkerScheduled();
    expect(app(BunkerReadinessService::class)->check($service)['complete'])->toBeTrue();
    $service->vessel->documents()->where('document_type', 'fire_fighting')->update(['equipment' => json_encode(['condition' => 'not_ready', 'quantity' => 3])]);

    expect(fn () => app(BunkerWorkflow::class)->complete($service, bunkerActual()))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('bunker_scheduled');
});

test('Bunker submission requires both PMK and SOPEP evidence', function (string $type) {
    $service = bunkerDraft();
    $document = $service->vessel->documents()->where('document_type', $type)->sole();
    Storage::disk('local')->delete($document->file_path);

    expect(fn () => app(BunkerWorkflow::class)->ready($service))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('draft');
})->with(['fire_fighting', 'sopep_equipment']);

test('Bunker cannot be scheduled after the PMK certificate expires', function () {
    $service = bunkerApproved();
    $service->vessel->documents()->where('document_type', 'fire_fighting')->update(['expired_date' => '2026-10-07']);

    expect(fn () => app(BunkerWorkflow::class)->schedule($service, array_replace(bunkerInput($service->vessel), ['scheduled_at' => '2026-10-08T12:00'])))->toThrow(ValidationException::class);
    expect($service->fresh()->status)->toBe('approved');
});

test('cargo non applicability cannot bypass evidence and explanation checks', function (bool $keepFile, ?string $notes) {
    $vessel = Vessel::factory()->create(['vessel_type' => 'cargo']);
    attachReadyEquipment($vessel, ['liferaft', 'fire_fighting', 'life_jacket', 'life_buoy', 'emergency_equipment']);
    $document = $vessel->documents()->where('document_type', 'liferaft')->sole();
    $document->update(['equipment' => ['condition' => 'not_applicable', 'notes' => $notes]]);
    if (! $keepFile) {
        Storage::disk('local')->delete($document->file_path);
    }

    $checks = app(SpbReadinessService::class)->equipmentCheck($vessel->fresh());

    expect(collect($checks)->every('complete', true))->toBeFalse();
})->with([[false, 'Tidak diwajibkan untuk kapal ini'], [true, null]]);

test('external users cannot manage safety equipment files', function () {
    $vessel = Vessel::factory()->create();
    attachReadyEquipment($vessel, ['liferaft']);
    $document = $vessel->documents()->sole();

    Livewire::actingAs(User::factory()->create(['role' => 'external']))->test('pages::dashboard.vessel')->assertForbidden();
    $this->get(route('vessel-documents.download', [$vessel->id, $document->id]))->assertForbidden();
});
