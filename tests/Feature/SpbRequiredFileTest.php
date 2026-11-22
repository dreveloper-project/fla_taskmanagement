<?php

use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Services\SpbReadinessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
});

function requiredFileSpbInput(Vessel $vessel): array
{
    return ['vessel_id' => $vessel->id, 'assigned_staff_id' => auth()->id(), 'priority' => 'medium',
        'total_passengers' => 0, 'cargo_status' => 'not_applicable', 'port_cargo_activity_applicable' => false,
        'departure_port' => 'Makassar', 'destination' => 'Surabaya', 'estimated_departure_at' => '2026-12-01T10:00'];
}

test('a new SPB requires a valid SPB file before it can be saved', function () {
    $vessel = Vessel::factory()->create();
    $page = Livewire::test('pages::services.spb-form')->assertSee('File SPB (wajib)')
        ->assertSee('Simpan SPB')->assertDontSee('Simpan Draft')->set('form', requiredFileSpbInput($vessel));
    $page->call('save')->assertHasErrors(['documents.spb_file' => 'required']);
    expect(SpbService::count())->toBe(0);
    $page->set('documents.spb_file', UploadedFile::fake()->create('bad.txt', 1, 'text/plain'))
        ->call('save')->assertHasErrors(['documents.spb_file']);
    expect(SpbService::count())->toBe(0);
});

test('a valid SPB is completed and archived without asserting a sailing result', function () {
    $vessel = Vessel::factory()->create();
    Livewire::test('pages::services.spb-form')->set('form', requiredFileSpbInput($vessel))
        ->set('documents.spb_file', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();
    $spb = SpbService::query()->sole();
    $document = $spb->latestDocument('spb_file');
    expect($spb->status)->toBe('completed')->and($spb->completed_at)->not->toBeNull()
        ->and($spb->archived_at)->not->toBeNull()->and($spb->sailing_result)->toBe('pending')
        ->and($spb->latestDocument('issued_spb'))->toBeNull();
    Storage::disk('local')->assertExists($document->file_path);
    $this->get(route('spb.show', $spb))->assertOk()->assertSee('File SPB')
        ->assertDontSee('Ready to Resubmit')->assertDontSee('Reply From Inaportnet');
    $this->get(route('spb.documents.download', [$spb, $document]))->assertOk();
});

test('existing SPB drafts with an SPB file are completed when saved and keep previous versions', function () {
    $spb = SpbService::factory()->create(['status' => 'draft', 'cargo_status' => 'not_applicable', 'passengers_none' => false]);
    $old = SpbDocument::factory()->for($spb, 'spbService')->create(['document_type' => 'spb_file']);
    Storage::disk('local')->put($old->file_path, '%PDF-1.4 old');
    Livewire::test('pages::services.spb-form', ['spb' => $spb])->set('form.notes', 'Catatan')
        ->call('save')->assertHasNoErrors();
    expect($spb->fresh()->notes)->toBe('Catatan')->and($spb->fresh()->status)->toBe('completed');
    Livewire::test('pages::services.spb-form', ['spb' => $spb])->assertSee('File SPB (unggah jika belum tersedia)')
        ->set('documents.spb_file', UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();
    expect($spb->documents()->where('document_type', 'spb_file')->count())->toBe(2);
    Storage::disk('local')->assertExists($old->file_path);
});

test('an existing draft cannot be completed without its SPB file', function () {
    $spb = SpbService::factory()->create(['status' => 'draft', 'cargo_status' => 'not_applicable']);
    Livewire::test('pages::services.spb-form', ['spb' => $spb])
        ->call('save')->assertHasErrors(['documents.spb_file']);
    expect($spb->fresh()->status)->toBe('draft');
});

test('a passenger SPB can be completed without a passenger manifest', function () {
    $vessel = Vessel::factory()->create(['vessel_type' => 'passenger']);
    $input = requiredFileSpbInput($vessel);
    $input['total_passengers'] = 10;
    Livewire::test('pages::services.spb-form')
        ->assertSee('Passenger Manifest (opsional saat pembuatan)')
        ->set('form', $input)
        ->set('documents.spb_file', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();
    $spb = SpbService::query()->sole();
    expect($spb->status)->toBe('completed')->and($spb->total_passengers)->toBe(10)
        ->and($spb->latestDocument('passenger_manifest'))->toBeNull()
        ->and(app(SpbReadinessService::class)->requiredDocumentTypes($spb))->toContain('passenger_manifest');
});

test('LK3 can be uploaded when creating and editing an SPB and its versions can be downloaded', function () {
    $vessel = Vessel::factory()->create();
    Livewire::test('pages::services.spb-form')
        ->assertSee('LK3 Nakhoda (opsional)')
        ->set('form', requiredFileSpbInput($vessel))
        ->set('documents.spb_file', UploadedFile::fake()->create('spb.pdf', 10, 'application/pdf'))
        ->set('documents.lk3_nakhoda', UploadedFile::fake()->create('lk3.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();

    $spb = SpbService::query()->sole();
    $first = $spb->latestDocument('lk3_nakhoda');
    Storage::disk('local')->assertExists($first->file_path);
    $this->get(route('spb.show', $spb))->assertOk()->assertSee('LK3 Nakhoda');
    $this->get(route('spb.documents.download', [$spb, $first]))->assertOk();

    Livewire::test('pages::services.spb-form', ['spb' => $spb])
        ->assertSee('LK3 Nakhoda (opsional)')
        ->set('documents.lk3_nakhoda', UploadedFile::fake()->create('lk3-baru.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();

    $latest = $spb->fresh()->latestDocument('lk3_nakhoda');
    expect($spb->documents()->where('document_type', 'lk3_nakhoda')->count())->toBe(2)
        ->and($latest->original_filename)->toBe('lk3-baru.pdf');
    Storage::disk('local')->assertExists($first->file_path);
    Storage::disk('local')->assertExists($latest->file_path);
    $this->get(route('spb.documents.download', [$spb, $latest]))->assertOk();
});
