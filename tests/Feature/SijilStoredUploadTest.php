<?php

use App\Models\Crew;
use App\Models\SijilDocument;
use App\Models\SijilService;
use App\Models\SijilServiceCrew;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('sijil draft and revision forms show crew uploads while retaining service archives', function (string $status) {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = SijilService::factory()->create(['assigned_staff_id' => $staff->id, 'status' => $status]);
    $crew = SijilServiceCrew::factory()->for($service, 'sijilService')->create();
    $old = SijilDocument::factory()->for($service, 'sijilService')->create(['original_filename' => 'old-application.pdf']);
    $latest = SijilDocument::factory()->for($service, 'sijilService')->create(['original_filename' => 'latest-application.pdf']);
    $oldCertificate = SijilDocument::factory()->for($service, 'sijilService')->create([
        'sijil_service_crew_id' => $crew->id, 'document_type' => 'competency_certificate', 'original_filename' => 'old-certificate.pdf',
    ]);
    $certificate = SijilDocument::factory()->for($service, 'sijilService')->create([
        'sijil_service_crew_id' => $crew->id, 'document_type' => 'competency_certificate', 'original_filename' => 'latest-certificate.pdf',
    ]);
    foreach ([$old, $latest, $oldCertificate, $certificate] as $document) {
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 stored');
    }

    Livewire::actingAs($staff)->test('pages::services.sijil-form', ['service' => $service])
        ->assertSee('Sudah diunggah')->assertSee('Unduh dokumen tersimpan')->assertSee('Ganti dokumen')
        ->assertSee('Belum diunggah')->assertSee('Pilih dokumen')
        ->assertDontSee('Service Documents')->assertDontSee('latest-application.pdf')->assertSee('latest-certificate.pdf')
        ->assertDontSee('old-application.pdf')->assertDontSee('old-certificate.pdf')
        ->assertSee(route('sijil.documents.download', [$service, $certificate]), false);

    $this->get(route('sijil.show', $service))->assertSee('latest-application.pdf');
    $this->get(route('sijil.documents.download', [$service, $certificate]))->assertDownload();
})->with(['draft', 'revision_required']);

test('new sijil forms and new crew rows clearly identify documents that are not uploaded', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::services.sijil-form')
        ->call('addCrew')->assertSee('Sertifikat Kompetensi')
        ->assertSee('data-document-state="empty"', false)->assertSee('Belum diunggah')->assertSee('Pilih dokumen')
        ->assertDontSee('Sudah diunggah')->assertDontSee('Unduh dokumen tersimpan');
});

test('missing crew files show a reupload warning and do not download an older version', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = SijilService::factory()->create(['assigned_staff_id' => $staff->id]);
    $crew = SijilServiceCrew::factory()->for($service, 'sijilService')->create();
    $old = SijilDocument::factory()->for($service, 'sijilService')->create([
        'sijil_service_crew_id' => $crew->id, 'document_type' => 'competency_certificate',
    ]);
    Storage::disk('local')->put($old->file_path, '%PDF-1.4 old');
    $missing = SijilDocument::factory()->for($service, 'sijilService')->create([
        'sijil_service_crew_id' => $crew->id, 'document_type' => 'competency_certificate',
    ]);

    Livewire::actingAs($staff)->test('pages::services.sijil-form', ['service' => $service])
        ->assertSee('data-document-state="missing"', false)->assertSee('File tidak tersedia')
        ->assertSee('Unggah ulang dokumen ini.')
        ->assertDontSee(route('sijil.documents.download', [$service, $missing]), false)
        ->assertDontSee(route('sijil.documents.download', [$service, $old]), false);
});

test('selecting an existing crew displays reusable documents and downloads from their original service', function () {
    Storage::fake('local');
    $crew = Crew::factory()->create();
    $source = SijilService::factory()->create(['status' => 'completed']);
    $certificate = SijilDocument::factory()->for($source, 'sijilService')->create([
        'crew_id' => $crew->id, 'document_type' => 'competency_certificate', 'original_filename' => 'reusable-certificate.pdf',
    ]);
    Storage::disk('local')->put($certificate->file_path, '%PDF-1.4 certificate');

    $page = Livewire::actingAs(User::factory()->create())->test('pages::services.sijil-form')
        ->set('selectedCrewId', (string) $crew->id)->call('addCrew')
        ->assertSee('Sudah diunggah')->assertSee('reusable-certificate.pdf')
        ->assertSee(route('sijil.documents.download', [$source, $certificate]), false);

    $this->get(route('sijil.documents.download', [$source, $certificate]))->assertDownload();

    $page->call('removeCrew', 0)->call('addCrew')
        ->assertSee('Belum diunggah')->assertDontSee('reusable-certificate.pdf')
        ->assertDontSee('Unduh dokumen tersimpan');
});

test('each crew row shows its own uploads before reusable files belonging to the same crew', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $firstCrew = Crew::factory()->create();
    $secondCrew = Crew::factory()->create();
    $source = SijilService::factory()->create(['status' => 'completed']);
    $reused = SijilDocument::factory()->for($source, 'sijilService')->create([
        'crew_id' => $firstCrew->id, 'document_type' => 'competency_certificate', 'original_filename' => 'master-certificate.pdf',
    ]);
    $service = SijilService::factory()->create(['assigned_staff_id' => $staff->id]);
    $firstRow = SijilServiceCrew::factory()->for($service, 'sijilService')->for($firstCrew)->create();
    $secondRow = SijilServiceCrew::factory()->for($service, 'sijilService')->for($secondCrew)->create();
    $first = SijilDocument::factory()->for($service, 'sijilService')->create([
        'sijil_service_crew_id' => $firstRow->id, 'document_type' => 'competency_certificate', 'original_filename' => 'first-crew.pdf',
    ]);
    $second = SijilDocument::factory()->for($service, 'sijilService')->create([
        'sijil_service_crew_id' => $secondRow->id, 'document_type' => 'competency_certificate', 'original_filename' => 'second-crew.pdf',
    ]);
    foreach ([$reused, $first, $second] as $document) {
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 certificate');
    }

    $page = Livewire::actingAs($staff)->test('pages::services.sijil-form', ['service' => $service])
        ->assertSee('first-crew.pdf')->assertSee('second-crew.pdf')->assertDontSee('master-certificate.pdf');

    expect($page->get('existingCrewDocuments')[0]['competency_certificate']['url'])->toBe(route('sijil.documents.download', [$service, $first]));
    expect($page->get('existingCrewDocuments')[1]['competency_certificate']['url'])->toBe(route('sijil.documents.download', [$service, $second]));
});

test('replacement crew selections keep the saved certificate downloadable until saving a new version', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = SijilService::factory()->create(['assigned_staff_id' => $staff->id]);
    $appointment = \App\Models\VesselDocument::factory()->for($service->vessel)->create(['document_type' => 'surat_penunjukan_keagenan']);
    Storage::disk('local')->put($appointment->file_path, '%PDF-1.4 appointment');
    $crew = SijilServiceCrew::factory()->for($service, 'sijilService')->create();
    $original = SijilDocument::factory()->for($service, 'sijilService')->create([
        'sijil_service_crew_id' => $crew->id, 'document_type' => 'competency_certificate', 'original_filename' => 'original-certificate.pdf',
    ]);
    Storage::disk('local')->put($original->file_path, '%PDF-1.4 original');

    $page = Livewire::actingAs($staff)->test('pages::services.sijil-form', ['service' => $service])
        ->set('crewDocuments.0.competency_certificate', UploadedFile::fake()->createWithContent('replacement-certificate.pdf', "%PDF-1.4\nreplacement"))
        ->assertSee('File baru dipilih')->assertSee('replacement-certificate.pdf')->assertSee('original-certificate.pdf')
        ->assertSee(route('sijil.documents.download', [$service, $original]), false);
    expect($service->documents()->count())->toBe(1);

    $page->call('save')->assertHasNoErrors()->assertRedirect(route('sijil.show', $service));

    $latest = $crew->documents()->latest('id')->first();
    expect($latest->original_filename)->toBe('replacement-certificate.pdf');
    expect($service->documents()->count())->toBe(2);
    Storage::disk('local')->assertExists($original->file_path);
    Storage::disk('local')->assertExists($latest->file_path);
});

test('sijil revision filenames are escaped and revision attachments are marked as uploaded', function () {
    Storage::fake('local');
    $staff = User::factory()->create();
    $service = SijilService::factory()->create(['assigned_staff_id' => $staff->id, 'status' => 'revision_required']);
    $document = SijilDocument::factory()->for($service, 'sijilService')->create([
        'document_type' => 'revision_attachment', 'original_filename' => '<script>alert(1)</script>.pdf',
    ]);
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 revision');

    Livewire::actingAs($staff)->test('pages::services.sijil-form', ['service' => $service])
        ->assertSee('Sudah diunggah')->assertSee(route('sijil.documents.download', [$service, $document]), false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;.pdf', false)->assertDontSee('<script>alert(1)</script>.pdf', false);
});

test('sijil stored document metadata is not exposed after PIC reassignment', function () {
    $staff = User::factory()->create();
    $service = SijilService::factory()->create(['assigned_staff_id' => $staff->id]);
    $page = Livewire::actingAs($staff)->test('pages::services.sijil-form', ['service' => $service]);
    $service->update(['assigned_staff_id' => User::factory()->create()->id]);

    $page->call('$refresh')->assertForbidden();
});
