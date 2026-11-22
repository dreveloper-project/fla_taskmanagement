<?php

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'Asia/Makassar'));
    Storage::fake('local');
});

test('staff can upload registry endorsement and record its next due date on vessel', function () {
    $vessel = Vessel::factory()->create(['port_of_registry' => 'Makassar']);

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('manageDocuments', $vessel->id)
        ->assertSee('Endorsement Port of Registry')
        ->assertSee('Tanggal pengukuhan berikutnya')
        ->set('documents.registry_endorsement.file', UploadedFile::fake()->create('endorsement.pdf', 10, 'application/pdf'))
        ->set('documents.registry_endorsement.issued_date', '2026-10-01')
        ->set('documents.registry_endorsement.expired_date', '2027-10-01')
        ->call('save')->assertHasNoErrors()->assertRedirect(route('dashboard.vessel'));

    $document = $vessel->documents()->sole();
    expect($document->document_type)->toBe('registry_endorsement')
        ->and($document->issued_date->toDateString())->toBe('2026-10-01')
        ->and($document->expired_date->toDateString())->toBe('2027-10-01')
        ->and($vessel->fresh()->port_of_registry)->toBe('Makassar');
    Storage::disk('local')->assertExists($document->file_path);
    $this->get(route('vessel-documents.download', [$vessel->id, $document->id]))->assertOk();
    Livewire::test('pages::dashboard.vessel')->call('detail', $vessel->id)
        ->assertSee('Pengukuhan berikutnya')->assertSee('Pengukuhan belum jatuh tempo');
});

test('replacing an endorsement preserves the previous file and endorsement dates in archive', function () {
    $document = VesselDocument::factory()->create([
        'document_type' => 'registry_endorsement', 'issued_date' => '2025-10-01', 'expired_date' => '2026-10-01',
    ]);
    $oldPath = $document->file_path;
    Storage::disk('local')->put($oldPath, 'previous endorsement');

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('manageDocuments', $document->vessel_id)
        ->set('documents.registry_endorsement.file', UploadedFile::fake()->image('new-endorsement.png'))
        ->set('documents.registry_endorsement.issued_date', '2026-10-01')
        ->set('documents.registry_endorsement.expired_date', '2027-10-01')
        ->call('save')->assertHasNoErrors();

    $version = $document->versions()->sole();
    expect($version->file_path)->toBe($oldPath)
        ->and($version->issued_date->toDateString())->toBe('2025-10-01')
        ->and($version->expired_date->toDateString())->toBe('2026-10-01')
        ->and($document->fresh()->file_path)->not->toBe($oldPath);
    Storage::disk('local')->assertExists([$oldPath, $document->fresh()->file_path]);
    $this->get(route('vessel-document-versions.download', [$document->vessel_id, $version->id]))->assertOk();
});

test('endorsement next due date cannot precede the endorsement date', function () {
    $vessel = Vessel::factory()->create();
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard.vessel')
        ->call('manageDocuments', $vessel->id)
        ->set('documents.registry_endorsement.file', UploadedFile::fake()->create('endorsement.pdf', 10, 'application/pdf'))
        ->set('documents.registry_endorsement.issued_date', '2026-10-10')
        ->set('documents.registry_endorsement.expired_date', '2026-10-01')
        ->call('save')->assertHasErrors('documents.registry_endorsement.expired_date')
        ->assertSee('Tanggal pengukuhan berikutnya tidak boleh sebelum tanggal pengukuhan.');

    expect($vessel->documents()->count())->toBe(0);
});

test('endorsement labels describe due dates without treating the underlying registration as expired', function (?string $dueDate, string $label) {
    $document = VesselDocument::factory()->make(['document_type' => 'registry_endorsement', 'expired_date' => $dueDate]);

    expect($document->statusLabel())->toBe($label)
        ->and($document->serviceUrl())->toBeNull();
})->with([
    [null, 'Jadwal pengukuhan berikutnya belum diisi'],
    ['2026-10-15', 'Pengukuhan lewat jatuh tempo'],
    ['2026-11-01', 'Pengukuhan segera jatuh tempo'],
    ['2027-10-01', 'Pengukuhan belum jatuh tempo'],
]);

test('endorsement downloads stay private and scoped to their vessel', function () {
    $document = VesselDocument::factory()->create(['document_type' => 'registry_endorsement']);
    Storage::disk('local')->put($document->file_path, 'endorsement');
    $url = route('vessel-documents.download', [$document->vessel_id, $document->id]);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'external']))->get($url)->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('vessel-documents.download', [Vessel::factory()->create()->id, $document->id]))->assertNotFound();
});
