<?php

use App\Models\Client;
use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function storedUploadSpb(User $staff, string $status = 'draft'): SpbService
{
    $vessel = Vessel::factory()->for(Client::factory()->state(['service_prices' => ['spb' => '100000']]))->create(['vessel_type' => 'passenger']);

    return SpbService::factory()->for($vessel)->create([
        'client_id' => $vessel->client_id, 'assigned_staff_id' => $staff->id,
        'cargo_status' => 'not_applicable', 'port_cargo_activity_applicable' => false, 'status' => $status, 'pnbp_labuh' => 10000, 'pnbp_mastercable' => 1000,
    ]);
}

it('marks uploaded SPB documents and offers the latest saved version for download', function (string $status): void {
    Storage::fake('local');
    $staff = User::factory()->create();
    $spb = storedUploadSpb($staff, $status);
    $old = SpbDocument::factory()->state(['document_type' => 'crew_list'])->for($spb, 'spbService')->create(['original_filename' => 'old-request.pdf']);
    $latest = SpbDocument::factory()->state(['document_type' => 'crew_list'])->for($spb, 'spbService')->create(['original_filename' => 'latest-request.pdf']);
    Storage::disk('local')->put($old->file_path, '%PDF-1.4 old');
    Storage::disk('local')->put($latest->file_path, '%PDF-1.4 latest');

    Livewire::actingAs($staff)->test('pages::services.spb-form', ['spb' => $spb])
        ->assertSee('data-document-state="saved"', false)->assertSee('Sudah diunggah')
        ->assertSee('latest-request.pdf')->assertDontSee('old-request.pdf')
        ->assertSee(route('spb.documents.download', [$spb, $latest]), false)
        ->assertDontSee(route('spb.documents.download', [$spb, $old]), false)
        ->assertSee('Unduh dokumen tersimpan')->assertSee('Ganti dokumen')->assertSee('Belum diunggah');
    $this->get(route('spb.documents.download', [$spb, $latest]))->assertOk();
})->with(['draft', 'revision_required']);

it('labels new SPB upload fields as empty without a saved download action', function (): void {
    Livewire::actingAs(User::factory()->create())->test('pages::services.spb-form')
        ->assertSee('data-document-state="empty"', false)->assertSee('Belum diunggah')->assertSee('Pilih dokumen')
        ->assertDontSee('Sudah diunggah')->assertDontSee('Unduh dokumen tersimpan');
});

it('asks for another upload when the stored SPB file is unavailable', function (): void {
    Storage::fake('local');
    $staff = User::factory()->create();
    $spb = storedUploadSpb($staff);
    $document = SpbDocument::factory()->state(['document_type' => 'crew_list'])->for($spb, 'spbService')->create();
    Livewire::actingAs($staff)->test('pages::services.spb-form', ['spb' => $spb])
        ->assertSee('data-document-state="missing"', false)->assertSee('File tidak tersedia')->assertSee('Unggah ulang dokumen ini.')
        ->assertDontSee('Sudah diunggah')->assertDontSee(route('spb.documents.download', [$spb, $document]), false);
});

it('distinguishes a pending replacement and retains the previous file until saved', function (): void {
    Storage::fake('local');
    $staff = User::factory()->create();
    $spb = storedUploadSpb($staff);
    $old = SpbDocument::factory()->state(['document_type' => 'crew_list'])->for($spb, 'spbService')->create(['original_filename' => 'original.pdf']);
    Storage::disk('local')->put($old->file_path, '%PDF-1.4 original');
    $page = Livewire::actingAs($staff)->test('pages::services.spb-form', ['spb' => $spb])
        ->set('documents.crew_list', UploadedFile::fake()->createWithContent('replacement.pdf', "%PDF-1.4\nreplacement"))
        ->assertSee('File baru dipilih')->assertSee('replacement.pdf')->assertSee('original.pdf')
        ->assertSee(route('spb.documents.download', [$spb, $old]), false);
    expect($spb->documents()->count())->toBe(1);
    $page->call('save')->assertHasNoErrors()->assertRedirect(route('spb.show', $spb));
    $latest = $spb->fresh()->latestDocument('crew_list');
    expect($latest->original_filename)->toBe('replacement.pdf');
    expect($spb->documents()->count())->toBe(2);
    Storage::disk('local')->assertExists($old->file_path);
    Storage::disk('local')->assertExists($latest->file_path);
});

it('keeps the uploaded document when completing data without a replacement', function (): void {
    Storage::fake('local');
    $staff = User::factory()->create();
    $spb = storedUploadSpb($staff);
    $document = SpbDocument::factory()->state(['document_type' => 'crew_list'])->for($spb, 'spbService')->create();
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 saved');
    Livewire::actingAs($staff)->test('pages::services.spb-form', ['spb' => $spb])
        ->set('form.notes', 'Lengkapi informasi')->call('save')->assertHasNoErrors();
    expect($spb->fresh()->notes)->toBe('Lengkapi informasi');
    expect($spb->documents()->sole()->id)->toBe($document->id);
    Storage::disk('local')->assertExists($document->file_path);
});

it('does not expose documents from another SPB and escapes the uploaded filename', function (): void {
    Storage::fake('local');
    $staff = User::factory()->create();
    $spb = storedUploadSpb($staff);
    $document = SpbDocument::factory()->state(['document_type' => 'crew_list'])->for($spb, 'spbService')->create(['original_filename' => '<b>request.pdf</b>']);
    $other = SpbDocument::factory()->state(['document_type' => 'crew_list'])->create(['original_filename' => 'another-service.pdf']);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 saved');
    Storage::disk('local')->put($other->file_path, '%PDF-1.4 other');
    Livewire::actingAs($staff)->test('pages::services.spb-form', ['spb' => $spb])
        ->assertSee('<b>request.pdf</b>')->assertDontSee('<b>request.pdf</b>', false)
        ->assertDontSee('another-service.pdf')->assertDontSee(route('spb.documents.download', [$other->spbService, $other]), false);
});

it('revokes the stored upload form when the service is assigned to another staff member', function (): void {
    $staff = User::factory()->create();
    $spb = storedUploadSpb($staff);
    $page = Livewire::actingAs($staff)->test('pages::services.spb-form', ['spb' => $spb]);
    $spb->update(['assigned_staff_id' => User::factory()->create()->id]);
    $page->call('$refresh')->assertForbidden();
});
