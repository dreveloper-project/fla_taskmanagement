<?php

use App\Models\BunkerService;
use App\Models\SafetyCertificateDocument;
use App\Models\SafetyCertificateService;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\TaskServiceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('incomplete services link directly to their edit form and vessel uploads', function (string $type, string $serviceClass) {
    $staff = User::factory()->create();
    $service = $serviceClass::factory()->create(['status' => 'draft', 'assigned_staff_id' => $staff->id]);
    $vesselCompletionUrl = route('vessels.edit', ['vessel' => $service->vessel, 'service_type' => $type, 'service_id' => $service->id]).'#vessel-documents';

    $response = $this->actingAs($staff)->get(route($type.'.show', $service))
        ->assertOk()
        ->assertSeeInOrder([$type === 'spb' ? 'Masih perlu dilengkapi:' : 'Persyaratan belum lengkap', 'Lengkapi dokumen layanan'])
        ->assertSee(route($type.'.edit', $service), false)
        ->assertSee($vesselCompletionUrl);
    if (in_array($type, ['spb', 'safety-certificates', 'ship-nationality'], true)) {
        $response->assertSee('Lengkapi dokumen kapal')
            ->assertSee($vesselCompletionUrl);
    } else {
        $response->assertDontSee('Lengkapi dokumen kapal');
    }
    $this->get(route($type.'.edit', $service))->assertOk()->assertSee($vesselCompletionUrl);
})->with(function () {
    foreach (TaskServiceRegistry::TYPES as $type => $serviceClass) {
        yield $type => [$type, $serviceClass];
    }
});

test('vessel completion opens the selected vessel edit page at its uploads', function () {
    $staff = User::factory()->create();
    $vessel = Vessel::factory()->create();

    $this->actingAs($staff)->get(route('vessels.edit', $vessel))
        ->assertOk()->assertSee('id="vessel-documents"', false)->assertSee('type="file"', false)
        ->assertSee($vessel->vessel_name);
});

test('vessel edit marks documents that meet the originating service requirements', function (string $type, string $serviceClass, string $documentType) {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = $serviceClass::factory()->create(['assigned_staff_id' => $staff->id]);
    $document = VesselDocument::factory()->for($service->vessel)->create(['document_type' => $documentType]);
    $unrelated = VesselDocument::factory()->for($service->vessel)->create(['document_type' => 'registry_endorsement']);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 complete');
    Storage::disk('local')->put($unrelated->file_path, '%PDF-1.4 unrelated');

    $this->actingAs($staff)->get(route('vessels.edit', ['vessel' => $service->vessel, 'service_type' => $type, 'service_id' => $service->id]))
        ->assertOk()
        ->assertSee('Dokumen untuk '.TaskServiceRegistry::LABELS[$type])
        ->assertSee($service->service_number)
        ->assertSee('data-service-document-complete="'.$documentType.'"', false)
        ->assertDontSee('data-service-document-complete="registry_endorsement"', false)
        ->assertSee(route($type.'.show', $service), false);

    $this->get(route('vessels.edit', $service->vessel))->assertOk()
        ->assertDontSee('data-service-document-complete', false)
        ->assertDontSee('data-service-document-incomplete', false);
})->with(function () {
    foreach (TaskServiceRegistry::TYPES as $type => $serviceClass) {
        yield $type => [$type, $serviceClass, $type === 'sijil' ? 'sijil_awak_kapal' : 'surat_ukur'];
    }
});

test('vessel edit marks stored documents that fail SPB readiness as incomplete', function (string $condition, string $status) {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Asia/Makassar'));
    Storage::fake('local');
    $spb = SpbService::factory()->create(['estimated_departure_at' => '2026-10-07 02:00:00', 'total_crew' => 10, 'total_passengers' => 0]);
    $certificate = VesselDocument::factory()->for($spb->vessel)->create([
        'document_type' => 'safety_certificate', 'expired_date' => '2027-10-01',
    ]);
    $equipment = VesselDocument::factory()->for($spb->vessel)->readyEquipment('liferaft')->create();
    Storage::disk('local')->put($equipment->file_path, '%PDF-1.4 equipment');
    Storage::disk('local')->put($certificate->file_path, '%PDF-1.4 certificate');
    match ($condition) {
        'expired' => $certificate->update(['expired_date' => '2026-10-05']),
        'before_departure' => $certificate->update(['expired_date' => '2026-10-06']),
        'unknown' => $certificate->update(['expired_date' => null]),
        'file_missing' => Storage::disk('local')->delete($certificate->file_path),
    };
    $equipment->update(['equipment' => ['condition' => 'not_ready', 'quantity' => 200, 'capacity' => 200]]);

    $this->actingAs(User::factory()->create())->get(route('vessels.edit', ['vessel' => $spb->vessel, 'service_type' => 'spb', 'service_id' => $spb->id]))
        ->assertOk()->assertSee('Tersimpan')
        ->assertDontSee('data-service-document-complete="safety_certificate"', false)
        ->assertDontSee('data-service-document-complete="liferaft"', false)
        ->assertSee('data-service-document-incomplete="safety_certificate"', false)
        ->assertSee('data-service-document-incomplete="liferaft"', false)
        ->assertSee('Peringatan · Belum lengkap untuk SPB')
        ->assertSee('SPB tetap dapat dilanjutkan')
        ->assertSee($status)
        ->assertSee('Kondisi alat belum dinyatakan siap');
})->with([
    'expired' => ['expired', 'Kedaluwarsa'],
    'before departure' => ['before_departure', 'Kedaluwarsa sebelum keberangkatan'],
    'unknown expiry' => ['unknown', 'Tanggal kedaluwarsa belum diisi'],
    'missing file' => ['file_missing', 'File tidak tersedia'],
]);

test('vessel edit marks missing required documents for the originating service', function (string $type, string $documentType) {
    $staff = User::factory()->create();
    $serviceClass = TaskServiceRegistry::TYPES[$type];
    $service = $serviceClass::factory()->create(['assigned_staff_id' => $staff->id]);

    $this->actingAs($staff)->get(route('vessels.edit', ['vessel' => $service->vessel, 'service_type' => $type, 'service_id' => $service->id]))
        ->assertOk()
        ->assertSee('data-service-document-incomplete="'.$documentType.'"', false)
        ->assertSee(($type === 'spb' ? 'Peringatan' : 'Wajib').' · Belum lengkap untuk '.TaskServiceRegistry::LABELS[$type])
        ->assertSee('Belum tersedia')
        ->assertDontSee('data-service-document-incomplete="registry_endorsement"', false);
})->with([
    'SPB' => ['spb', 'surat_ukur'],
    'safety certificate' => ['safety-certificates', 'surat_ukur'],
    'bunker' => ['bunker', 'fire_fighting'],
    'nationality' => ['ship-nationality', 'surat_ukur'],
]);

test('missing SPB nationality alternatives are marked with a shared requirement and optional documents stay unmarked', function () {
    $spb = SpbService::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('vessels.edit', ['vessel' => $spb->vessel, 'service_type' => 'spb', 'service_id' => $spb->id]))
        ->assertOk()
        ->assertSee('data-service-document-incomplete="surat_laut"', false)
        ->assertSee('data-service-document-incomplete="pas_besar"', false)
        ->assertSee('data-service-document-incomplete="pas_kecil"', false)
        ->assertSee('Cukup lengkapi salah satu: Surat Laut / Pas Besar / Pas Kecil.')
        ->assertDontSee('data-service-document-incomplete="classification_certificate"', false)
        ->assertDontSee('data-service-document-incomplete="life_buoy"', false);
});

test('vessel edit marks the available nationality alternative and optional classification for SPB', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'Asia/Makassar'));
    Storage::fake('local');
    $spb = SpbService::factory()->create(['estimated_departure_at' => '2026-10-07 02:00:00']);
    foreach (['pas_besar', 'classification_certificate'] as $type) {
        $document = VesselDocument::factory()->for($spb->vessel)->create(['document_type' => $type, 'expired_date' => '2027-10-01']);
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 fixture');
    }

    $this->actingAs(User::factory()->create())->get(route('vessels.edit', ['vessel' => $spb->vessel, 'service_type' => 'spb', 'service_id' => $spb->id]))
        ->assertOk()
        ->assertSee('data-service-document-complete="pas_besar"', false)
        ->assertSee('data-service-document-complete="classification_certificate"', false)
        ->assertDontSee('data-service-document-complete="surat_laut"', false)
        ->assertDontSee('data-service-document-incomplete="surat_laut"', false)
        ->assertDontSee('data-service-document-incomplete="pas_besar"', false)
        ->assertDontSee('data-service-document-incomplete="pas_kecil"', false)
        ->assertSee('Lengkap untuk SPB · Pemeriksaan');
});

test('vessel completion rejects a service from another vessel or a missing service', function (bool $missing) {
    $service = BunkerService::factory()->create();
    $vessel = Vessel::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('vessels.edit', [
        'vessel' => $vessel, 'service_type' => 'bunker', 'service_id' => $missing ? $service->id + 1 : $service->id,
    ]))->assertNotFound();
})->with(['different vessel' => false, 'missing service' => true]);

test('vessel completion validates the service context query', function (array $query, string $field) {
    $vessel = Vessel::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('vessels.edit', ['vessel' => $vessel, ...$query]))
        ->assertSessionHasErrors($field);
})->with([
    'unknown type' => [['service_type' => 'unknown', 'service_id' => 1], 'service_type'],
    'missing type' => [['service_id' => 1], 'service_type'],
    'missing id' => [['service_type' => 'spb'], 'service_id'],
    'invalid id' => [['service_type' => 'spb', 'service_id' => 'invalid'], 'service_id'],
]);

test('service document markings persist when the vessel edit form updates', function () {
    Storage::fake('local');
    $service = BunkerService::factory()->create();
    $document = VesselDocument::factory()->for($service->vessel)->create(['document_type' => 'surat_ukur']);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 fixture');

    Livewire::actingAs(User::factory()->create())->withQueryParams(['service_type' => 'bunker', 'service_id' => $service->id])
        ->test('pages::dashboard.vessel', ['vessel' => $service->vessel])
        ->set('form.owner', 'Pemilik baru')
        ->assertSee('data-service-document-complete="surat_ukur"', false)
        ->assertSee('Dokumen untuk Izin Bunker');
});

test('read only staff do not see a service completion action for another staff members service', function () {
    $staff = User::factory()->create();
    $service = BunkerService::factory()->create(['assigned_staff_id' => User::factory()->create()->id]);

    $this->actingAs($staff)->get(route('bunker.show', $service))
        ->assertOk()->assertDontSee('Lengkapi dokumen layanan');
});

test('services which cannot be edited do not offer the edit shortcut', function () {
    $staff = User::factory()->create();
    $service = BunkerService::factory()->create(['status' => 'ready_to_apply', 'assigned_staff_id' => $staff->id]);

    $this->actingAs($staff)->get(route('bunker.show', $service))
        ->assertOk()->assertDontSee('Lengkapi dokumen layanan');
});

test('safety certificate vessel completion distinguishes required optional and unrelated documents', function () {
    Storage::fake('local');
    $service = SafetyCertificateService::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('vessels.edit', [
        'vessel' => $service->vessel, 'service_type' => 'safety-certificates', 'service_id' => $service->id,
    ]))
        ->assertSee('Sertifikat Keselamatan Kapal Penumpang · Penerbitan Baru')
        ->assertSee('data-service-document-incomplete="general_arrangement"', false)
        ->assertSee('data-service-document-incomplete="life_buoy"', false)
        ->assertSee('data-service-document-optional="grosse_akta"', false)
        ->assertSee('data-service-document-optional="safe_manning"', false)
        ->assertSee('data-service-document-optional="safety_certificate"', false)
        ->assertSeeInOrder(['data-service-document-incomplete="general_arrangement"', 'data-service-document-optional="grosse_akta"'], false)
        ->assertDontSee('data-service-document-incomplete="safety_certificate"', false)
        ->assertDontSee('data-service-document-optional="sopep_equipment"', false)
        ->assertSee('Dokumen lainnya')
        ->assertDontSee('Client dan dokumen bersifat opsional.');
});

test('safety renewal highlights a missing old certificate but accepts an expired saved copy', function (bool $available) {
    Storage::fake('local');
    $this->freezeTime();
    $service = SafetyCertificateService::factory()->create(['request_type' => 'renewal']);
    if ($available) {
        $certificate = VesselDocument::factory()->for($service->vessel)->create([
            'document_type' => 'safety_certificate', 'expired_date' => now()->subYear()->toDateString(),
        ]);
        Storage::disk('local')->put($certificate->file_path, '%PDF-1.4 old certificate');
    }

    $response = $this->actingAs(User::factory()->create())->get(route('vessels.edit', [
        'vessel' => $service->vessel, 'service_type' => 'safety-certificates', 'service_id' => $service->id,
    ]))->assertSee('Sertifikat Keselamatan Kapal Penumpang · Perpanjangan');

    if ($available) {
        $response->assertSee('data-service-document-complete="safety_certificate"', false)
            ->assertDontSee('data-service-document-incomplete="safety_certificate"', false);
    } else {
        $response->assertSee('data-service-document-incomplete="safety_certificate"', false)
            ->assertSee('Sertifikat lama wajib dilengkapi untuk perpanjangan.');
    }
})->with(['missing' => false, 'expired copy available' => true]);

test('safety renewal recognizes the old certificate already attached to the service', function () {
    Storage::fake('local');
    $service = SafetyCertificateService::factory()->create(['request_type' => 'renewal']);
    $previous = SafetyCertificateDocument::factory()->for($service, 'safetyCertificateService')->create(['document_type' => 'previous_certificate']);
    Storage::disk('local')->put($previous->file_path, '%PDF-1.4 previous certificate');

    $this->actingAs(User::factory()->create())->get(route('vessels.edit', [
        'vessel' => $service->vessel, 'service_type' => 'safety-certificates', 'service_id' => $service->id,
    ]))->assertSee('data-service-document-complete="safety_certificate"', false)
        ->assertSee('Sertifikat lama sudah dilampirkan pada service.');
});

test('safety certificate nationality alternatives do not demand all three documents', function () {
    Storage::fake('local');
    $service = SafetyCertificateService::factory()->create();
    $document = VesselDocument::factory()->for($service->vessel)->create(['document_type' => 'pas_kecil']);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 nationality');

    $this->actingAs(User::factory()->create())->get(route('vessels.edit', [
        'vessel' => $service->vessel, 'service_type' => 'safety-certificates', 'service_id' => $service->id,
    ]))->assertSee('data-service-document-complete="pas_kecil"', false)
        ->assertSee('data-service-document-alternative="surat_laut"', false)
        ->assertSee('data-service-document-alternative="pas_besar"', false)
        ->assertDontSee('data-service-document-incomplete="surat_laut"', false)
        ->assertDontSee('data-service-document-incomplete="pas_besar"', false);
});

test('new safety service links preserve the request type without creating a service', function (string $requestType) {
    $staff = User::factory()->create();
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger']);
    $url = route('vessels.edit', ['vessel' => $vessel, 'service_type' => 'safety-certificates', 'request_type' => $requestType]);

    Livewire::actingAs($staff)->test('pages::services.safety-certificate-form')
        ->set('form.vessel_id', $vessel->id)->set('form.request_type', $requestType)
        ->assertSee($url.'#vessel-documents');
    $response = $this->get($url)
        ->assertSee('Sertifikat Keselamatan Kapal Penumpang · '.SafetyCertificateService::REQUEST_TYPES[$requestType])
        ->assertSee('data-service-document-optional="grosse_akta"', false)
        ->assertSee(route('safety-certificates.create', ['vessel_id' => $vessel->id, 'request_type' => $requestType]));
    if ($requestType === 'renewal') {
        $response->assertSee('data-service-document-incomplete="safety_certificate"', false);
    }
    $this->assertDatabaseCount('safety_certificate_services', 0);
})->with(['new_issue', 'renewal']);

test('unsaved safety context rejects invalid request types and cargo vessels', function () {
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create(['vessel_type' => 'cargo']);

    $this->get(route('vessels.edit', ['vessel' => $vessel, 'service_type' => 'safety-certificates', 'request_type' => 'invalid']))
        ->assertSessionHasErrors('request_type');
    $this->get(route('vessels.edit', ['vessel' => $vessel, 'service_type' => 'safety-certificates', 'request_type' => 'new_issue']))
        ->assertUnprocessable();
});

test('an unsaved renewal context persists through vessel form updates', function () {
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger']);

    Livewire::actingAs(User::factory()->create())->withQueryParams(['service_type' => 'safety-certificates', 'request_type' => 'renewal'])
        ->test('pages::dashboard.vessel', ['vessel' => $vessel])
        ->set('form.owner', 'Pemilik terbaru')
        ->assertSee('Sertifikat Keselamatan Kapal Penumpang · Perpanjangan')
        ->assertSee('data-service-document-incomplete="safety_certificate"', false)
        ->assertSee('data-service-document-optional="grosse_akta"', false);
});
