<?php

use App\Models\Client;
use App\Models\SafetyCertificateService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\DocumentFilename;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function vesselPdf(string $name = 'certificate.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");
}

test('staff can register a vessel with multiple optional documents', function () {
    Storage::fake('local');

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')->set('form.vessel_name', 'KM. Documents')->set('form.vessel_type', 'cargo')
        ->assertSee('Surat Moring')->assertSee('RPK')
        ->set('documents.grosse_akta.file', vesselPdf('grosse.pdf'))
        ->set('documents.surat_moring.file', vesselPdf('moring.pdf'))
        ->set('documents.rpk.file', vesselPdf('rpk.pdf'))
        ->set('documents.surat_laut.file', vesselPdf('surat-laut.pdf'))
        ->set('documents.surat_laut.issued_date', '2026-01-01')
        ->set('documents.surat_laut.expired_date', '2027-01-01')
        ->call('save')->assertHasNoErrors();

    $vessel = Vessel::with('documents')->sole();
    expect($vessel->documents)->toHaveCount(4);
    foreach ($vessel->documents as $document) {
        Storage::disk('local')->assertExists($document->file_path);
        expect($document->vessel->is($vessel))->toBeTrue();
        expect($document->file_path)->not->toContain($document->original_filename);
    }
    $this->assertDatabaseHas('vessel_documents', ['vessel_id' => $vessel->id, 'document_type' => 'grosse_akta', 'expired_date' => null, 'issued_date' => null]);
    $this->assertDatabaseHas('vessel_documents', ['vessel_id' => $vessel->id, 'document_type' => 'surat_moring', 'original_filename' => 'moring.pdf']);
    $this->assertDatabaseHas('vessel_documents', ['vessel_id' => $vessel->id, 'document_type' => 'rpk', 'original_filename' => 'rpk.pdf']);
    $this->assertDatabaseHas('vessel_documents', ['vessel_id' => $vessel->id, 'document_type' => 'surat_laut', 'original_filename' => 'surat-laut.pdf', 'expired_date' => '2027-01-01 00:00:00']);
});

test('staff can add documents after registration and replace existing files', function () {
    Storage::fake('local');
    $vessel = Vessel::factory()->create();

    $component = Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('manageDocuments', $vessel->id)
        ->set('documents.safety_certificate.file', vesselPdf('first.pdf'))
        ->call('save')->assertHasNoErrors()
        ->assertRedirect(route('dashboard.vessel'));
    $this->get(route('dashboard.vessel'))->assertSee('Dokumen vessel berhasil ditambahkan.');
    $document = $vessel->documents()->sole();
    $oldPath = $document->file_path;

    $component->call('manageDocuments', $vessel->id)
        ->set('documents.safety_certificate.file', UploadedFile::fake()->image('replacement.png'))
        ->set('documents.safety_certificate.issued_date', '2026-01-01')
        ->set('documents.safety_certificate.expired_date', '2027-01-01')
        ->call('save')->assertHasNoErrors();

    $document->refresh();
    expect($document->original_filename)->toBe('replacement.png');
    Storage::disk('local')->assertExists($oldPath);
    expect($document->versions()->sole()->file_path)->toBe($oldPath);
    Storage::disk('local')->assertExists($document->file_path);
    $this->assertDatabaseCount('vessel_documents', 1);
    $this->assertDatabaseHas('vessels', ['id' => $vessel->id, 'vessel_name' => $vessel->vessel_name]);
});

test('document dates can be edited or cleared without replacing the file', function () {
    Storage::fake('local');
    $document = VesselDocument::factory()->create(['issued_date' => '2025-01-01', 'expired_date' => '2026-01-01']);
    Storage::disk('local')->put($document->file_path, 'keep this file');

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('manageDocuments', $document->vessel_id)
        ->set('documents.surat_laut.issued_date', '')
        ->set('documents.surat_laut.expired_date', '')
        ->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('vessel_documents', ['id' => $document->id, 'file_path' => $document->file_path, 'issued_date' => null, 'expired_date' => null]);
    Storage::disk('local')->assertExists($document->file_path);
});

test('document expiration uses calendar dates and includes today in warnings', function (?string $expiry, string $status) {
    $this->travelTo(now()->setDate(2026, 10, 6)->setTime(23, 59));
    $document = VesselDocument::factory()->make(['expired_date' => $expiry]);

    expect($document->status())->toBe($status);
})->with([
    'no expiry' => [null, 'unknown'],
    'yesterday' => ['2026-10-05', 'expired'],
    'today' => ['2026-10-06', 'expiring_soon'],
    'tomorrow' => ['2026-10-07', 'expiring_soon'],
    'thirty days' => ['2026-11-05', 'expiring_soon'],
    'thirty one days' => ['2026-11-06', 'valid'],
]);

test('expiration warning interval can be configured', function () {
    $this->travelTo(now()->setDate(2026, 10, 6)->startOfDay());
    config(['vessels.expiration_warning_days' => 10]);
    $document = VesselDocument::factory()->make(['expired_date' => '2026-10-17']);

    expect($document->status())->toBe('valid');
});

test('alerts prioritize expired documents and only eligible certificates offer services', function () {
    $this->travelTo(now()->setDate(2026, 10, 6)->startOfDay());
    $vessel = Vessel::factory()->create();
    $suratLaut = VesselDocument::factory()->for($vessel)->create(['document_type' => 'surat_laut', 'expired_date' => '2026-10-18']);
    $pasBesar = VesselDocument::factory()->for($vessel)->create(['document_type' => 'pas_besar', 'expired_date' => '2026-09-30']);
    $radio = VesselDocument::factory()->for($vessel)->create(['document_type' => 'radio_certificate', 'expired_date' => '2026-09-30']);

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->assertSee('Dokumen kedaluwarsa: 2')->assertSee('Dokumen segera kedaluwarsa: 1')
        ->call('detail', $vessel->id)
        ->assertSeeInOrder(['Peringatan dokumen', 'Pas Besar', 'Radio Certificate', 'Surat Laut'])
        ->assertSee('Create Service')->assertSee($suratLaut->serviceUrl())->assertSee($pasBesar->serviceUrl())
        ->assertDontSee('Service module belum tersedia.');
    expect($radio->serviceUrl())->toBeNull();
});

test('unknown expiry never labels all vessel documents valid', function () {
    $vessel = Vessel::factory()->create();
    VesselDocument::factory()->for($vessel)->create(['expired_date' => null]);

    expect($vessel->documentCondition())->toBe(['label' => 'Masa berlaku belum diketahui: 1', 'tone' => 'neutral']);
});

test('service eligibility follows document type and status', function (string $type, ?int $days, bool $eligible) {
    $document = VesselDocument::factory()->make([
        'document_type' => $type,
        'expired_date' => $days !== null ? today()->addDays($days) : null,
    ]);

    expect($document->needsService())->toBe($eligible);
})->with([
    ['safety_certificate', 5, true],
    ['surat_laut', -1, true],
    ['pas_besar', 0, true],
    ['pas_kecil', 30, true],
    ['sijil_awak_kapal', 1, true],
    ['radio_certificate', -1, false],
    ['safety_certificate', 31, false],
    ['surat_laut', null, false],
]);

test('future service hook passes vessel client and document context only for an available route', function () {
    $vessel = Vessel::factory()->for(Client::factory())->create();
    $document = VesselDocument::factory()->for($vessel)->create(['document_type' => 'safety_certificate', 'expired_date' => today()->subDay()]);

    expect($document->serviceUrl())->toContain('/dashboard/services/safety-certificates/create');
    config(['vessels.service_routes.safety_certificate' => 'future.services.create']);
    expect($document->serviceUrl())->toBeNull();

    Route::get('/test-service-create', fn () => '')->name('future.services.create');
    Route::getRoutes()->refreshNameLookups();
    $query = [];
    parse_str(parse_url($document->serviceUrl(), PHP_URL_QUERY), $query);

    expect($query)->toBe([
        'vessel_id' => (string) $vessel->id,
        'client_id' => (string) $vessel->client_id,
        'document_type' => 'safety_certificate',
        'document_id' => (string) $document->id,
        'service_type' => 'safety_certificate',
        'request_type' => 'renewal',
        'previous_document_id' => (string) $document->id,
    ]);
});

test('unsupported or oversized documents are rejected', function (string $kind, string $rule) {
    Storage::fake('local');
    $file = match ($kind) {
        'script' => UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;'),
        'extension' => UploadedFile::fake()->createWithContent('certificate.php', "%PDF-1.4\n%%EOF"),
        'size' => UploadedFile::fake()->create('large.pdf', 2049, 'application/pdf'),
    };

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')->set('form.vessel_name', 'KM. Validation')->set('form.vessel_type', 'cargo')
        ->set('documents.surat_laut.file', $file)->call('save')
        ->assertHasErrors(['documents.surat_laut.file' => $rule]);

    $this->assertDatabaseCount('vessels', 0);
    $this->assertDatabaseCount('vessel_documents', 0);
})->with([['script', 'mimes'], ['extension', 'extensions'], ['size', 'max']]);

test('invalid document dates are rejected', function (string $issued, string $expiry, string $field, string $rule) {
    Storage::fake('local');

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')->set('form.vessel_name', 'KM. Dates')->set('form.vessel_type', 'cargo')
        ->set('documents.surat_laut.file', vesselPdf())
        ->set('documents.surat_laut.issued_date', $issued)
        ->set('documents.surat_laut.expired_date', $expiry)->call('save')
        ->assertHasErrors(['documents.surat_laut.'.$field => $rule]);

    $this->assertDatabaseCount('vessels', 0);
})->with([
    ['2026-02-30', '', 'issued_date', 'date_format'],
    ['', 'invalid', 'expired_date', 'date_format'],
    ['2026-10-10', '2026-10-01', 'expired_date', 'after_or_equal'],
]);

test('dates without a document file require the missing upload', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')->set('form.vessel_name', 'KM. Missing file')->set('form.vessel_type', 'cargo')
        ->set('documents.surat_laut.expired_date', '2027-01-01')->call('save')
        ->assertHasErrors(['documents.surat_laut.file' => 'required'])
        ->assertSee('File Surat Laut wajib diisi.');

    $this->assertDatabaseCount('vessels', 0);
});

test('unexpected document type and file path cannot be submitted', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')->set('form.vessel_name', 'KM. Invalid')->set('form.vessel_type', 'cargo')
        ->set('documents.injected', ['file_path' => '../private.txt'])
        ->set('documents.surat_laut.file_path', '../private.txt')
        ->call('save')->assertHasErrors(['documents', 'documents.surat_laut']);

    $this->assertDatabaseCount('vessels', 0);
});

test('a fatal document failure rolls back all rows and newly written files', function () {
    Storage::fake('local');
    Exceptions::fake();
    Event::listen('eloquent.creating: '.VesselDocument::class, function (VesselDocument $document): void {
        if ($document->document_type === 'surat_ukur') {
            throw new RuntimeException('Simulated document persistence failure');
        }
    });

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')->set('form.vessel_name', 'KM. Rollback')->set('form.vessel_type', 'cargo')
        ->set('documents.grosse_akta.file', vesselPdf('first.pdf'))
        ->set('documents.surat_ukur.file', vesselPdf('second.pdf'))
        ->call('save')->assertHasErrors('save')
        ->assertSee('Penyimpanan gagal. Data belum disimpan.');

    $this->assertDatabaseCount('vessels', 0);
    $this->assertDatabaseCount('vessel_documents', 0);
    expect(Storage::disk('local')->allFiles('vessels'))->toBe([]);
});

test('failed document replacement keeps the original file and metadata', function () {
    Storage::fake('local');
    Exceptions::fake();
    $document = VesselDocument::factory()->create();
    Storage::disk('local')->put($document->file_path, 'original');
    Event::listen('eloquent.updating: '.VesselDocument::class, function (): void {
        throw new RuntimeException('Simulated update failure');
    });

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('manageDocuments', $document->vessel_id)
        ->set('documents.surat_laut.file', vesselPdf('replacement.pdf'))
        ->call('save')->assertHasErrors('save');

    $this->assertDatabaseHas('vessel_documents', ['id' => $document->id, 'file_path' => $document->file_path, 'original_filename' => $document->original_filename]);
    Storage::disk('local')->assertExists($document->file_path);
    expect(Storage::disk('local')->allFiles('vessels'))->toBe([$document->file_path]);
});

test('staff can download private vessel files', function () {
    Storage::fake('local');
    $document = VesselDocument::factory()->create();
    Storage::disk('local')->put($document->file_path, 'private vessel content');

    $this->actingAs(User::factory()->create())
        ->get(route('vessel-documents.download', [$document->vessel_id, $document->id]))
        ->assertDownload(DocumentFilename::forVessel($document, $document->vessel, $document->document_type))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertStreamedContent('private vessel content');
});

test('guests cannot download vessel files', function () {
    $document = VesselDocument::factory()->create();

    $this->get(route('vessel-documents.download', [$document->vessel_id, $document->id]))->assertRedirectToRoute('login');
});

test('non staff cannot download vessel files', function () {
    $document = VesselDocument::factory()->create();

    $this->actingAs(User::factory()->create(['role' => 'external']))
        ->get(route('vessel-documents.download', [$document->vessel_id, $document->id]))->assertForbidden();
});

test('a document from another vessel cannot be downloaded through a mismatched vessel', function () {
    $document = VesselDocument::factory()->create();
    $otherVessel = Vessel::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('vessel-documents.download', [$otherVessel->id, $document->id]))->assertNotFound();
});

test('missing document files return not found', function () {
    Storage::fake('local');
    $document = VesselDocument::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('vessel-documents.download', [$document->vessel_id, $document->id]))->assertNotFound();
});

test('malformed document input returns validation errors without saving', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('create')->set('form.vessel_name', 'KM. Malformed')->set('form.vessel_type', 'cargo')
        ->set('documents.surat_laut', 'invalid')->call('save')
        ->assertHasErrors(['documents.surat_laut' => 'array']);

    $this->assertDatabaseCount('vessels', 0);
});

test('storage failure removes earlier uploads and rolls back the vessel', function () {
    Storage::fake('local');
    $vessel = Vessel::factory()->make();
    $failedUpload = Mockery::mock(UploadedFile::class);
    $failedUpload->shouldReceive('store')->once()->andReturn(false);

    expect(fn () => $vessel->saveWithDocuments([], [
        'grosse_akta' => ['file' => vesselPdf('first.pdf')],
        'surat_laut' => ['file' => $failedUpload],
    ]))->toThrow(RuntimeException::class, 'Dokumen vessel gagal disimpan.');

    $this->assertDatabaseCount('vessels', 0);
    $this->assertDatabaseCount('vessel_documents', 0);
    expect(Storage::disk('local')->allFiles('vessels'))->toBe([]);
});

test('standard document names replace unsafe upload names in detail and replacement forms', function () {
    $document = VesselDocument::factory()->for(Vessel::factory()->state(['vessel_name' => 'KM Flores']))->create([
        'id' => 123, 'document_type' => 'surat_laut', 'issued_date' => '2026-10-08',
        'original_filename' => '<script>alert(1)</script>.pdf',
    ]);

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('detail', $document->vessel_id)
        ->assertSee('Surat-Laut_KM-Flores_2026-10-08_123.pdf')->assertDontSeeHtml($document->original_filename)
        ->call('manageDocuments', $document->vessel_id)
        ->assertSee('Surat-Laut_KM-Flores_2026-10-08_123.pdf')->assertDontSeeHtml($document->original_filename);
});

test('legacy safety certificates merge into the current document without losing history', function (string $legacyType) {
    $vessel = Vessel::factory()->create();
    $current = VesselDocument::factory()->for($vessel)->create(['document_type' => 'safety_certificate', 'file_path' => 'current.pdf']);
    $legacy = VesselDocument::factory()->for($vessel)->create(['document_type' => $legacyType, 'file_path' => 'legacy.pdf']);
    $legacy->versions()->create(['file_path' => 'older.pdf', 'original_filename' => 'older.pdf', 'status' => 'superseded', 'archived_at' => now()]);
    $service = SafetyCertificateService::factory()->for($vessel)->create(['current_certificate_document_id' => $legacy->id]);

    $migration = require database_path('migrations/2026_11_03_051908_consolidate_vessel_safety_certificates.php');
    $migration->up();

    expect($vessel->documents()->where('document_type', 'safety_certificate')->sole()->id)->toBe($current->id)
        ->and($current->versions()->pluck('file_path')->all())->toContain('legacy.pdf', 'older.pdf')
        ->and($service->fresh()->current_certificate_document_id)->toBe($current->id);
    $this->assertDatabaseMissing('vessel_documents', ['id' => $legacy->id]);
})->with(['skkp', 'skp']);

test('a legacy safety certificate remains current when no unified certificate exists', function (string $legacyType) {
    $legacy = VesselDocument::factory()->create(['document_type' => $legacyType, 'file_path' => 'legacy.pdf']);

    $migration = require database_path('migrations/2026_11_03_051908_consolidate_vessel_safety_certificates.php');
    $migration->up();

    expect($legacy->fresh()->document_type)->toBe('safety_certificate')
        ->and($legacy->fresh()->file_path)->toBe('legacy.pdf');
})->with(['skkp', 'skp']);
