<?php

use App\Models\SafetyCertificateDocument;
use App\Models\SafetyCertificateService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('safety draft and revision show the latest uploaded documents with download actions', function (string $status) {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = SafetyCertificateService::factory()->create(['assigned_staff_id' => $staff->id, 'status' => $status]);
    $old = SafetyCertificateDocument::factory()->for($service, 'safetyCertificateService')->create(['original_filename' => 'old-request.pdf']);
    $latest = SafetyCertificateDocument::factory()->for($service, 'safetyCertificateService')->create(['original_filename' => 'latest-request.pdf']);
    Storage::disk('local')->put($old->file_path, '%PDF-1.4 old');
    Storage::disk('local')->put($latest->file_path, '%PDF-1.4 latest');

    Livewire::actingAs($staff)->test('pages::services.safety-certificate-form', ['service' => $service])
        ->assertSee('data-document-state="saved"', false)->assertSee('Sudah diunggah')
        ->assertSee('latest-request.pdf')->assertDontSee('old-request.pdf')
        ->assertSee(route('safety-certificates.documents.download', [$service, $latest]), false)
        ->assertDontSee(route('safety-certificates.documents.download', [$service, $old]), false)
        ->assertSee('Unduh dokumen tersimpan')->assertSee('Ganti dokumen')->assertSee('Belum diunggah');
    $this->get(route('safety-certificates.documents.download', [$service, $latest]))->assertOk();
})->with(['draft', 'revision_required']);

test('new safety forms label empty documents without saved download actions', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::services.safety-certificate-form')
        ->assertSee('data-document-state="empty"', false)->assertSee('Belum diunggah')->assertSee('Pilih dokumen')
        ->assertDontSee('Sudah diunggah')->assertDontSee('Unduh dokumen tersimpan');
});

test('missing safety files show a reupload warning without a broken download link', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = SafetyCertificateService::factory()->create(['assigned_staff_id' => $staff->id]);
    $document = SafetyCertificateDocument::factory()->for($service, 'safetyCertificateService')->create();

    Livewire::actingAs($staff)->test('pages::services.safety-certificate-form', ['service' => $service])
        ->assertSee('data-document-state="missing"', false)->assertSee('File tidak tersedia')
        ->assertSee('Unggah ulang dokumen ini.')
        ->assertDontSee(route('safety-certificates.documents.download', [$service, $document]), false);
});

test('replacement selection preserves the saved safety file until the form is saved', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = SafetyCertificateService::factory()->create(['assigned_staff_id' => $staff->id]);
    $old = SafetyCertificateDocument::factory()->for($service, 'safetyCertificateService')->create(['original_filename' => 'original.pdf']);
    Storage::disk('local')->put($old->file_path, '%PDF-1.4 original');

    $page = Livewire::actingAs($staff)->test('pages::services.safety-certificate-form', ['service' => $service])
        ->set('documents.application_letter', UploadedFile::fake()->createWithContent('replacement.pdf', "%PDF-1.4\nreplacement"))
        ->assertSee('File baru dipilih')->assertSee('replacement.pdf')->assertSee('original.pdf')
        ->assertSee(route('safety-certificates.documents.download', [$service, $old]), false);
    expect($service->documents()->count())->toBe(1);
    $page->call('save')->assertHasNoErrors()->assertRedirect(route('safety-certificates.show', $service));
    $latest = $service->fresh()->latestDocument('application_letter');
    expect($latest->original_filename)->toBe('replacement.pdf');
    expect($service->documents()->count())->toBe(2);
    Storage::disk('local')->assertExists($old->file_path);
    Storage::disk('local')->assertExists($latest->file_path);
});

test('safety revision attachments are marked and filenames are escaped', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = SafetyCertificateService::factory()->create(['assigned_staff_id' => $staff->id, 'status' => 'revision_required']);
    $document = SafetyCertificateDocument::factory()->for($service, 'safetyCertificateService')->create([
        'document_type' => 'revision_attachment', 'original_filename' => '<script>alert(1)</script>.pdf',
    ]);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 revision');

    Livewire::actingAs($staff)->test('pages::services.safety-certificate-form', ['service' => $service])
        ->assertSee(route('safety-certificates.documents.download', [$service, $document]), false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;.pdf', false)->assertDontSee('<script>alert(1)</script>.pdf', false);
});

test('safety document metadata is not exposed after PIC reassignment', function () {
    $staff = User::factory()->create();
    $service = SafetyCertificateService::factory()->create(['assigned_staff_id' => $staff->id]);
    $page = Livewire::actingAs($staff)->test('pages::services.safety-certificate-form', ['service' => $service]);
    $service->update(['assigned_staff_id' => User::factory()->create()->id]);

    $page->call('$refresh')->assertForbidden();
});
