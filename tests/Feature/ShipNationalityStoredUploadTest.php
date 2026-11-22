<?php

use App\Models\ShipNationalityDocument;
use App\Models\ShipNationalityService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('nationality edit forms show the latest saved document and its download action', function (string $type, string $status) {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = ShipNationalityService::factory()->create([
        'assigned_staff_id' => $staff->id, 'document_type' => $type, 'status' => $status,
    ]);
    $old = ShipNationalityDocument::factory()->for($service)->create(['original_filename' => 'old-request.pdf']);
    $latest = ShipNationalityDocument::factory()->for($service)->create(['original_filename' => 'latest-request.pdf']);
    Storage::disk('local')->put($old->file_path, '%PDF-1.4 old');
    Storage::disk('local')->put($latest->file_path, '%PDF-1.4 latest');

    Livewire::actingAs($staff)->test('pages::services.ship-nationality-form', ['service' => $service])
        ->assertSee('data-document-state="saved"', false)
        ->assertSee('Sudah diunggah')->assertSee('latest-request.pdf')->assertDontSee('old-request.pdf')
        ->assertSee(route('ship-nationality.documents.download', [$service, $latest]), false)
        ->assertDontSee(route('ship-nationality.documents.download', [$service, $old]), false)
        ->assertSee('Unduh dokumen tersimpan')->assertSee('Ganti dokumen');

    $this->get(route('ship-nationality.documents.download', [$service, $latest]))->assertDownload();
})->with(['surat_laut', 'pas_besar', 'pas_kecil'])->with(['draft', 'revision_required']);

test('new nationality forms mark documents as empty without saved download actions', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::services.ship-nationality-form')
        ->assertSee('data-document-state="empty"', false)->assertSee('Belum diunggah')
        ->assertDontSee('Sudah diunggah')->assertDontSee('Unduh dokumen tersimpan');
});

test('a missing latest nationality file requests reupload instead of linking to an older file', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = ShipNationalityService::factory()->create(['assigned_staff_id' => $staff->id]);
    $old = ShipNationalityDocument::factory()->for($service)->create();
    $latest = ShipNationalityDocument::factory()->for($service)->create();
    Storage::disk('local')->put($old->file_path, '%PDF-1.4 old');

    Livewire::actingAs($staff)->test('pages::services.ship-nationality-form', ['service' => $service])
        ->assertSee('data-document-state="missing"', false)->assertSee('File tidak tersedia')
        ->assertSee('Unggah ulang dokumen ini.')
        ->assertDontSee(route('ship-nationality.documents.download', [$service, $latest]), false)
        ->assertDontSee(route('ship-nationality.documents.download', [$service, $old]), false);
});

test('a nationality replacement is shown separately and becomes the saved file after reopening', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = ShipNationalityService::factory()->create(['assigned_staff_id' => $staff->id]);
    $old = ShipNationalityDocument::factory()->for($service)->create(['original_filename' => 'original.pdf']);
    Storage::disk('local')->put($old->file_path, '%PDF-1.4 original');

    $page = Livewire::actingAs($staff)->test('pages::services.ship-nationality-form', ['service' => $service])
        ->set('documents.application_letter', UploadedFile::fake()->createWithContent('replacement.pdf', "%PDF-1.4\nreplacement"))
        ->assertSee('File baru dipilih')->assertSee('replacement.pdf')->assertSee('original.pdf')
        ->assertSee(route('ship-nationality.documents.download', [$service, $old]), false);
    expect($service->documents()->count())->toBe(1);

    $page->call('save')->assertHasNoErrors()->assertRedirect(route('ship-nationality.show', $service));
    $latest = $service->fresh()->latestDocument('application_letter');
    expect($latest->original_filename)->toBe('replacement.pdf');
    expect($service->documents()->count())->toBe(2);
    Storage::disk('local')->assertExists($old->file_path);
    Storage::disk('local')->assertExists($latest->file_path);

    Livewire::test('pages::services.ship-nationality-form', ['service' => $service->fresh()])
        ->assertSee('Sudah diunggah')->assertSee('replacement.pdf')->assertDontSee('original.pdf')
        ->assertSee(route('ship-nationality.documents.download', [$service, $latest]), false);
});

test('nationality revision attachments display saved metadata with escaped filenames', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = ShipNationalityService::factory()->create(['assigned_staff_id' => $staff->id, 'status' => 'revision_required']);
    $document = ShipNationalityDocument::factory()->for($service)->create([
        'document_type' => 'revision_attachment', 'original_filename' => '<script>alert(1)</script>.pdf',
    ]);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 revision');

    Livewire::actingAs($staff)->test('pages::services.ship-nationality-form', ['service' => $service])
        ->assertSee(route('ship-nationality.documents.download', [$service, $document]), false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;.pdf', false)
        ->assertDontSee('<script>alert(1)</script>.pdf', false);
});

test('nationality saved document metadata is not exposed after PIC reassignment', function () {
    $staff = User::factory()->create();
    $service = ShipNationalityService::factory()->create(['assigned_staff_id' => $staff->id]);
    $page = Livewire::actingAs($staff)->test('pages::services.ship-nationality-form', ['service' => $service]);
    $service->update(['assigned_staff_id' => User::factory()->create()->id]);

    $page->call('$refresh')->assertForbidden();
});
