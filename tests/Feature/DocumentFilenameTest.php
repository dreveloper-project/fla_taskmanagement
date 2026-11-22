<?php

use App\Models\BunkerDocument;
use App\Models\BunkerService;
use App\Models\SafetyCertificateDocument;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityDocument;
use App\Models\ShipNationalityService;
use App\Models\SijilDocument;
use App\Models\SijilService;
use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Models\VesselDocumentVersion;
use App\Services\DocumentFilename;
use Illuminate\Support\Facades\Storage;

test('service pages and downloads use standard names for existing documents', function (string $serviceClass, string $documentClass, string $relation, string $route, string $documentType, string $prefix, array $attributes) {
    Storage::fake('local');
    $vessel = Vessel::factory()->create(['vessel_name' => 'KM. Flores']);
    $service = $serviceClass::factory()->for($vessel)->create($attributes);
    $document = $documentClass::factory()->for($service, $relation)->create([
        'id' => 123, 'document_type' => $documentType, 'original_filename' => 'scan001.pdf',
        'uploaded_at' => '2026-10-07 17:30:00', 'file_path' => 'legacy/random.pdf',
    ]);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 original content');
    $this->actingAs(User::factory()->create());
    $expected = $prefix.'_KM-Flores_2026-10-08_123.pdf';

    $this->get(route($route.'.documents.download', [$service, $document]))
        ->assertDownload($expected)->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertStreamedContent('%PDF-1.4 original content');
    $this->get(route($route.'.show', $service))->assertOk()->assertSee($expected);

    $this->assertDatabaseHas($document->getTable(), ['id' => 123, 'original_filename' => 'scan001.pdf', 'file_path' => 'legacy/random.pdf']);
    Storage::disk('local')->assertExists('legacy/random.pdf');
})->with([
    'SPB' => [SpbService::class, SpbDocument::class, 'spbService', 'spb', 'issued_spb', 'SPB', []],
    'Safety' => [SafetyCertificateService::class, SafetyCertificateDocument::class, 'safetyCertificateService', 'safety-certificates', 'issued_certificate', 'Sertifikat-Keselamatan', []],
    'Bunker' => [BunkerService::class, BunkerDocument::class, 'bunkerService', 'bunker', 'approval_document', 'Izin-Bunker', []],
    'Sijil' => [SijilService::class, SijilDocument::class, 'sijilService', 'sijil', 'official_result', 'Sijil-Awak-Kapal', []],
    'Surat Laut' => [ShipNationalityService::class, ShipNationalityDocument::class, 'shipNationalityService', 'ship-nationality', 'issued_document', 'Surat-Laut', ['document_type' => 'surat_laut']],
    'Pas Besar' => [ShipNationalityService::class, ShipNationalityDocument::class, 'shipNationalityService', 'ship-nationality', 'issued_document', 'Pas-Besar', ['document_type' => 'pas_besar']],
    'Pas Kecil' => [ShipNationalityService::class, ShipNationalityDocument::class, 'shipNationalityService', 'ship-nationality', 'issued_document', 'Pas-Kecil', ['document_type' => 'pas_kecil']],
    'Supporting letter' => [SpbService::class, SpbDocument::class, 'spbService', 'spb', 'spb_request_letter', 'SPB-Surat-Permohonan-SPB', []],
]);

test('master documents and archived versions have distinct standard download names', function () {
    Storage::fake('local');
    $vessel = Vessel::factory()->create(['vessel_name' => 'KM Flores']);
    $document = VesselDocument::factory()->for($vessel)->create([
        'id' => 123, 'document_type' => 'surat_laut', 'issued_date' => '2026-10-08',
    ]);
    $version = VesselDocumentVersion::factory()->for($document, 'vesselDocument')->create([
        'id' => 123, 'issued_date' => '2025-10-08',
    ]);
    Storage::disk('local')->put($document->file_path, 'current');
    Storage::disk('local')->put($version->file_path, 'archive');
    $this->actingAs(User::factory()->create());

    $this->get(route('vessel-documents.download', [$vessel, $document]))
        ->assertDownload('Surat-Laut_KM-Flores_2026-10-08_123.pdf')->assertStreamedContent('current');
    $this->get(route('vessel-document-versions.download', [$vessel, $version]))
        ->assertDownload('Surat-Laut_KM-Flores_2025-10-08_V123.pdf')->assertStreamedContent('archive');
    Storage::disk('local')->assertExists([$document->file_path, $version->file_path]);
});

test('filenames sanitize vessel names and preserve safe extensions', function (string $name, string $path, string $original, string $expected) {
    $vessel = Vessel::factory()->make(['vessel_name' => $name]);
    $document = SpbDocument::factory()->make([
        'id' => 123, 'document_type' => 'issued_spb', 'uploaded_at' => '2026-10-07 17:30:00',
        'file_path' => $path, 'original_filename' => $original,
    ]);

    expect(DocumentFilename::forService($document, $vessel))->toBe($expected);
})->with([
    'path and header characters' => ["../KM. Flores/\r\n\"Abadi\"", 'spb/a.PDF', 'scan.exe', 'SPB_KM-Flores-Abadi_2026-10-08_123.pdf'],
    'empty safe name' => ['...///', 'spb/a.png', 'scan.pdf', 'SPB_Kapal_2026-10-08_123.png'],
    'jpeg' => ['KM Flores', 'spb/a.jpeg', 'scan.pdf', 'SPB_KM-Flores_2026-10-08_123.jpeg'],
    'legacy extension fallback' => ['KM Flores', 'legacy/no-extension', 'SCAN.JPG', 'SPB_KM-Flores_2026-10-08_123.jpg'],
    'unsafe extensions' => ['KM Flores', 'legacy/file.php', 'file.exe', 'SPB_KM-Flores_2026-10-08_123.bin'],
    'long vessel name' => [str_repeat('A', 255), 'spb/a.pdf', 'scan.pdf', 'SPB_'.str_repeat('A', 100).'_2026-10-08_123.pdf'],
]);

test('undated master documents use their recorded creation date and never todays date', function () {
    $vessel = Vessel::factory()->make(['vessel_name' => 'KM Flores']);
    $document = VesselDocument::factory()->make([
        'id' => 123, 'issued_date' => null, 'created_at' => '2026-10-07 17:30:00',
    ]);

    expect(DocumentFilename::forVessel($document, $vessel, 'surat_ukur'))
        ->toBe('Surat-Ukur_KM-Flores_2026-10-08_123.pdf');
    $document->created_at = null;
    expect(DocumentFilename::forVessel($document, $vessel, 'surat_ukur'))
        ->toBe('Surat-Ukur_KM-Flores_Tanpa-Tanggal_123.pdf');
});
