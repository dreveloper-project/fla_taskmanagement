<?php

use App\Models\Crew;
use App\Models\SijilDocument;
use App\Models\SijilService;
use App\Models\SijilServiceCrew;
use App\Models\User;
use App\Models\Vessel;
use App\Services\DocumentFilename;
use App\Services\SijilWorkflow;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../SijilFixtures.php';

beforeEach(function () {
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 7)->setTime(16, 0));
    Storage::fake('local');
});

test('sijil pages require authenticated staff', function (string $name) {
    $service = SijilService::factory()->create();
    $url = route($name, ['service' => $service]);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'external']))->get($url)->assertForbidden();
    $this->actingAs($service->assignedStaff)->get($url)->assertOk();
})->with(['sijil.index', 'sijil.create', 'sijil.show', 'sijil.edit']);

test('staff can save a crew and immediately apply the change to the vessel', function () {
    $this->actingAs(User::factory()->create());
    $vessel = sijilVesselWithAppointment();
    $page = Livewire::test('pages::services.sijil-form')
        ->assertSee('Simpan Perubahan')->assertDontSee('Simpan Draft')->set('form.vessel_id', $vessel->id)->call('addCrew')
        ->assertSee('Semua dokumen untuk crew baru bersifat opsional.')
        ->set('crews.0.full_name', 'Andi Form')->set('crews.0.rank', 'Master')
        ->set('crewDocuments.0.seafarer_book', sijilPdf('book.pdf'))
        ->call('save')->assertHasNoErrors();
    $service = SijilService::sole();
    $page->assertRedirect(route('sijil.show', $service));
    expect($service->status)->toBe('completed')->and($service->crews()->sole()->full_name)->toBe('Andi Form')
        ->and($service->documents()->count())->toBe(1)->and($vessel->fresh()->currentCaptain()?->full_name)->toBe('Andi Form')
        ->and($vessel->fresh()->currentCrewCount())->toBe(1)->and($service->issued_at)->toBeNull();
});

test('each crew position offers searchable categorized suggestions and can be saved', function () {
    $this->actingAs(User::factory()->create());
    $vessel = sijilVesselWithAppointment();
    $page = Livewire::test('pages::services.sijil-form')
        ->set('form.vessel_id', $vessel->id)->call('addCrew')->call('addCrew')
        ->assertSee('aria-controls="crew-0-positions"', false)->assertSee('aria-controls="crew-1-positions"', false)
        ->assertSee('Nakhoda')->assertSee('Mualim')->assertSee('KKM')->assertSee('Masinis')
        ->assertSee('Juru Mudi')->assertSee('Kelasi')->assertSee('Juru Minyak')->assertSee('Koki')->assertSee('Pelayan')
        ->assertSee('Dek')->assertSee('Mesin')->assertSee('Pelayanan')
        ->set('crews.0.full_name', 'Andi')->set('crews.0.rank', 'Nakhoda')
        ->set('crews.1.full_name', 'Budi')->set('crews.1.rank', 'KKM')
        ->call('save')->assertHasNoErrors();

    $page->assertRedirect(route('sijil.show', SijilService::sole()));
    expect(SijilService::sole()->crews()->pluck('rank')->all())->toBe(['Nakhoda', 'KKM']);
});

test('sijil form validates vessel and requires a crew when saving a prefilled vessel', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::services.sijil-form')->call('save')->assertHasErrors(['vessel_id' => 'required']);
    $vessel = Vessel::factory()->create();
    Livewire::withQueryParams(['vessel_id' => $vessel->id])->test('pages::services.sijil-form')
        ->assertSet('form.vessel_id', $vessel->id)->call('save')->assertHasErrors('readiness');
    $this->assertDatabaseCount('sijil_services', 0);
});

test('failed immediate save rolls back the service crew and uploaded files', function () {
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create();

    Livewire::test('pages::services.sijil-form')
        ->set('form.vessel_id', $vessel->id)->call('addCrew')
        ->set('crews.0.full_name', 'Andi')->set('crews.0.rank', 'Nakhoda')
        ->set('crewDocuments.0.seafarer_book', sijilPdf())
        ->call('save')->assertHasErrors('readiness');

    $this->assertDatabaseCount('sijil_services', 0);
    $this->assertDatabaseCount('vessel_crews', 0);
    expect(Storage::disk('local')->allFiles('sijil'))->toBeEmpty();
});

test('failed sijil validation retains entered data and uploads for a successful retry', function () {
    $staff = User::factory()->create();
    $vessel = sijilVesselWithAppointment();
    $this->actingAs($staff);

    $page = Livewire::test('pages::services.sijil-form')
        ->set('form.vessel_id', $vessel->id)
        ->set('form.notes', 'Catatan pengurusan')->set('form.agent_fee', '25000')
        ->call('addCrew')->call('addCrew')
        ->set('crews.0.full_name', 'Andi')->set('crews.0.rank', 'Nakhoda')
        ->set('crews.0.seafarer_book_number', 'SB-101')->set('crews.0.notes', 'Catatan awak')
        ->set('crews.1.full_name', 'Budi')->set('crews.1.rank', '')
        ->set('crewDocuments.0.seafarer_book', sijilPdf('andi.pdf'))
        ->call('save')->assertHasErrors('readiness')->assertNoRedirect()
        ->assertSet('form.vessel_id', $vessel->id)
        ->assertSet('form.notes', 'Catatan pengurusan')->assertSet('form.agent_fee', '25000')
        ->assertSet('crews.0.full_name', 'Andi')->assertSet('crews.0.rank', 'Nakhoda')
        ->assertSet('crews.0.seafarer_book_number', 'SB-101')->assertSet('crews.0.notes', 'Catatan awak')
        ->assertSet('crews.1.full_name', 'Budi')->assertSet('crews.1.rank', '')
        ->assertSet('crewDocuments.0.seafarer_book', fn (mixed $file): bool => $file instanceof \Illuminate\Http\UploadedFile && $file->getClientOriginalName() === 'andi.pdf')
        ->assertSeeHtml('value="Catatan pengurusan"')->assertSeeHtml('value="25000"')
        ->assertSeeHtml('value="Andi"')->assertSeeHtml('value="Nakhoda"')->assertSeeHtml('value="Budi"');

    $this->assertDatabaseCount('sijil_services', 0);
    $this->assertDatabaseCount('vessel_crews', 0);

    $page->set('crews.1.rank', 'Masinis')->call('save')->assertHasNoErrors();
    $service = SijilService::sole();
    $page->assertRedirect(route('sijil.show', $service));
    expect($service->status)->toBe('completed')->and($vessel->fresh()->currentCrewCount())->toBe(2)
        ->and($service->documents()->where('document_type', 'seafarer_book')->count())->toBe(1);
});

test('sijil forms and detail do not display completion targets', function () {
    $service = SijilService::factory()->create(['due_at' => now()->subDay()]);
    $this->actingAs($service->assignedStaff);

    $this->get(route('sijil.create'))->assertDontSee('Target penyelesaian');
    $this->get(route('sijil.edit', $service))->assertDontSee('Target penyelesaian');
    $this->get(route('sijil.show', $service))->assertDontSee('Target (WITA)')->assertDontSee('Layanan melewati target penyelesaian.');
});

test('sijil forms no longer show service document uploads', function () {
    $service = SijilService::factory()->create();
    $this->actingAs($service->assignedStaff);

    $this->get(route('sijil.create'))->assertDontSee('Service Documents')->assertDontSee('Surat Permohonan');
    $this->get(route('sijil.edit', $service))->assertDontSee('Service Documents')->assertDontSee('Surat Permohonan');
    $this->get(route('sijil.show', $service))->assertDontSee('Service Documents')->assertSee('Persyaratan &amp; Arsip Dokumen', false);
});

test('sijil pages do not show priority controls or values', function () {
    $service = SijilService::factory()->create(['priority' => 'urgent']);
    $this->actingAs($service->assignedStaff);

    $this->get(route('sijil.create'))->assertDontSee('Priority');
    $this->get(route('sijil.edit', $service))->assertDontSee('Priority');
    $this->get(route('sijil.show', $service))->assertDontSee('Priority');
    $this->get(route('sijil.index'))->assertDontSee('Priority')->assertSee($service->service_number);
});

test('legacy priority filters do not exclude sijil services', function () {
    SijilService::factory()->create(['priority' => 'urgent']);
    SijilService::factory()->create(['priority' => 'low']);

    $query = app(\App\Services\ServiceListFilters::class)->apply(SijilService::query(), ['priority' => 'urgent']);
    expect($query->count())->toBe(2);
});

test('existing crew selector prefills identity rank and reuses available documents', function () {
    $completed = sijilCompleted();
    $crew = Crew::sole();
    Livewire::withQueryParams(['vessel_id' => $completed->vessel_id])->test('pages::services.sijil-form')
        ->set('form.process_type', 'sign_off')->set('selectedCrewId', (string) $crew->id)
        ->call('addCrew')->assertSet('crews.0.crew_id', $crew->id)->assertSet('crews.0.rank', 'Master')
        ->assertSet('crews.0.action_type', 'sign_off')->call('save')->assertHasNoErrors();
    $service = SijilService::latest('id')->first();
    expect($service->status)->toBe('completed')->and($completed->vessel->fresh()->currentCrewCount())->toBe(0);
    $document = $completed->documents()->where('document_type', 'seafarer_book')->sole();
    $this->get(route('sijil.show', $service))->assertOk()->assertSee(DocumentFilename::forService($document, $service->vessel));
});

test('livewire complete flow applies crew only after recording official result', function () {
    $service = sijilDraft();
    $page = Livewire::test('pages::services.sijil-detail', ['service' => $service])
        ->call('ready')->assertHasNoErrors()
        ->set('form.submitted_at', '2026-10-06T09:00')->call('submit')->assertHasNoErrors()
        ->set('form.result', 'approved')->set('form.issued_at', '2026-10-07')
        ->set('form.result_reference', 'SIJIL-UI-001')->set('documents.official_result', sijilPdf())
        ->call('recordReview')->assertHasNoErrors()->assertSee('Apply Crew Changes');
    $this->assertDatabaseCount('vessel_crews', 0);
    $page->assertSet('form.effective_at', '2026-10-07')->call('applyCrewChanges')->assertHasNoErrors()->assertSee('Perubahan diterapkan');
    expect($service->fresh()->status)->toBe('completed');
    $this->get(route('sijil.show', $service))->assertOk()->assertSee('SIJIL-UI-001');
    $this->get(route('sijil.edit', $service))->assertForbidden();
    $page->call('applyCrewChanges')->assertHasErrors('state');
    $this->assertDatabaseCount('vessel_crews', 1);
});

test('sijil documents require correct parent staff role and existing files', function () {
    $service = sijilDraft();
    $document = $service->documents()->where('document_type', 'seafarer_book')->sole();
    $url = route('sijil.documents.download', [$service, $document]);
    $this->get($url)->assertOk()->assertDownload(DocumentFilename::forService($document, $service->vessel));
    $other = SijilService::factory()->create();
    $this->get(route('sijil.documents.download', [$other, $document]))->assertNotFound();
    $this->actingAs(User::factory()->create(['role' => 'external']))->get($url)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create());
    Storage::disk('local')->delete($document->file_path);
    $this->get($url)->assertNotFound();
});

test('vessel shows active previous crew updates and recent sijil services', function () {
    $service = sijilCompleted();
    Livewire::test('pages::dashboard.vessel')->call('detail', $service->vessel_id)
        ->assertSee('Awak aktif')->assertSee('Andi')->assertSee('Master')->assertSee($service->service_number);
    $off = app(SijilWorkflow::class)->applyCrewChanges(sijilApproved(sijilExistingDraft($service, 'sign_off', 'sign_off')), ['effective_at' => '2026-10-07']);
    Livewire::test('pages::dashboard.vessel')->call('detail', $service->vessel_id)
        ->assertSee('Crew History')->assertSee('Sign Off: 07 Oct 2026')->assertSee($off->service_number);
});

test('vessel card shows the active captain and crew count', function () {
    $draft = sijilDraft();
    $draft->vessel->forceFill(['captain_name' => 'Nama profil lama', 'total_crew' => 42])->save();
    expect($draft->vessel->fresh()->currentCaptain())->toBeNull();
    expect($draft->vessel->fresh()->currentCrewCount())->toBe(0);
    Livewire::test('pages::dashboard.vessel')->assertDontSee('Nama profil lama')->assertSee('0 orang')->assertDontSee('42 orang');

    $approved = sijilApproved($draft);
    expect($approved->vessel->fresh()->currentCaptain())->toBeNull();
    $service = app(SijilWorkflow::class)->applyCrewChanges($approved, ['effective_at' => '2026-10-07']);
    expect($service->vessel->fresh()->currentCaptain()?->full_name)->toBe('Andi');
    expect($service->vessel->fresh()->currentCrewCount())->toBe(1);

    Livewire::test('pages::dashboard.vessel')
        ->assertSee('Nakhoda')->assertSee('Andi')->assertDontSee('Nama profil lama')->assertSee('1 orang')->assertSee('Kapasitas penumpang')
        ->call('detail', $service->vessel_id)->assertSee('Nakhoda (Sijil)')->assertSee('Total crew (Sijil)')->assertSee('Andi')->assertDontSee('Nama profil lama');

    app(SijilWorkflow::class)->applyCrewChanges(sijilApproved(sijilExistingDraft($service, 'sign_off', 'sign_off')), ['effective_at' => '2026-10-07']);

    Livewire::test('pages::dashboard.vessel')
        ->assertSee('0 orang')->assertDontSee('Andi')->assertDontSee('Nama profil lama');
    expect($service->vessel->fresh()->currentCaptain())->toBeNull();
    expect($service->vessel->fresh()->currentCrewCount())->toBe(0);
    expect($service->vessel->fresh()->load('crewAssignments')->currentCrewCount())->toBe(0);
});

test('vessel offers official sijil downloads including completed services older than the five most recent', function () {
    $service = sijilCompleted();
    $service->vessel->update(['vessel_name' => 'KM Nusantara']);
    $documents = $service->documents()->where('document_type', 'official_result')->get();
    $additional = SijilDocument::factory()->for($service, 'sijilService')->create(['document_type' => 'official_result']);
    Storage::disk('local')->put($additional->file_path, 'additional official result');
    $documents->push($additional);
    SijilService::factory()->count(5)->for($service->vessel)->create();

    $page = Livewire::test('pages::dashboard.vessel')->call('detail', $service->vessel_id);

    $page->assertSee('Sijil Awak Kapal Selesai')->assertSee($service->service_number);
    foreach ($documents as $document) {
        $filename = 'Sijil-Awak-Kapal_KM-Nusantara_2026-10-07_'.$document->id.'.pdf';
        $page->assertSee(route('sijil.documents.download', [$service, $document]), false)->assertSee($filename);
        $this->get(route('sijil.documents.download', [$service, $document]))->assertDownload($filename);
    }
});

test('vessel sijil downloads exclude supporting documents unfinished services and other vessels', function () {
    $service = sijilCompleted();
    $supporting = $service->documents()->where('document_type', 'application_letter')->sole();
    $unfinished = SijilService::factory()->for($service->vessel)->create(['status' => 'ready_to_update_crew']);
    $unfinishedResult = SijilDocument::factory()->for($unfinished, 'sijilService')->create(['document_type' => 'official_result']);
    $other = SijilService::factory()->create(['status' => 'completed']);
    $otherResult = SijilDocument::factory()->for($other, 'sijilService')->create(['document_type' => 'official_result']);

    Livewire::test('pages::dashboard.vessel')->call('detail', $service->vessel_id)
        ->assertDontSee(route('sijil.documents.download', [$service, $supporting]), false)
        ->assertDontSee(route('sijil.documents.download', [$unfinished, $unfinishedResult]), false)
        ->assertDontSee(route('sijil.documents.download', [$other, $otherResult]), false)
        ->assertDontSee($other->service_number);
});

test('vessel explains when a completed sijil has no official result', function () {
    $this->actingAs(User::factory()->create());
    $service = SijilService::factory()->create(['status' => 'completed']);

    Livewire::test('pages::dashboard.vessel')->call('detail', $service->vessel_id)
        ->assertSee('Sijil Awak Kapal Selesai')->assertSee($service->service_number)
        ->assertSee('Dokumen hasil sijil belum tersedia.');
});

test('vessel explains when there are no completed sijil services', function () {
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create();

    Livewire::test('pages::dashboard.vessel')->call('detail', $vessel->id)
        ->assertSee('Belum ada sijil awak kapal yang selesai.');
});

test('overview includes incomplete sijil documents revision and completion today', function () {
    $this->actingAs(User::factory()->create());
    $draft = SijilService::factory()->create();
    SijilService::factory()->create(['status' => 'revision_required']);
    SijilService::factory()->create(['status' => 'ready_to_update_crew', 'due_at' => now()->subMinute()]);
    SijilService::factory()->create(['status' => 'completed', 'completed_at' => '2026-10-06 16:00:00']);
    SijilService::factory()->create(['status' => 'completed', 'completed_at' => '2026-10-06 15:59:59']);
    Livewire::test('pages::dashboard.overview')
        ->assertSet('activeServicesCount', 3)->assertSet('needAttentionCount', 3)->assertSet('completedTodayCount', 1)->assertSee($draft->service_number);
});

test('sijil monitoring filters status vessel search and submitted date and paginates', function () {
    $this->actingAs(User::factory()->create());
    $chosen = SijilService::factory()->create(['service_number' => 'SJL-CHOSEN', 'status' => 'completed', 'submitted_at' => '2026-10-06 16:30:00']);
    SijilService::factory()->count(11)->create(['status' => 'draft']);
    Livewire::test('pages::services.sijil')->assertDontSee('SJL-CHOSEN')
        ->call('setPage', 2)->assertSee('SJL-CHOSEN')
        ->set('status', 'completed')->assertSee('SJL-CHOSEN')->assertSet('paginators.page', 1)
        ->set('status', '')->set('vessel', (string) $chosen->vessel_id)->assertSee('SJL-CHOSEN')
        ->set('vessel', '')->set('dateFrom', '2026-10-07')->set('dateTo', '2026-10-07')->assertSee('SJL-CHOSEN')
        ->set('dateTo', '2026-10-06')->assertDontSee('SJL-CHOSEN')
        ->set('dateFrom', '')->set('dateTo', '')->set('search', 'SJL-CHOSEN')->assertSee('SJL-CHOSEN');
});

test('crew data is escaped in service and vessel detail', function () {
    $service = sijilDraft();
    $service->crews()->sole()->update(['full_name' => '<script>alert(1)</script>']);
    $this->get(route('sijil.show', $service))->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
});

test('removing all crew from an existing draft fails without changing its rows or files', function () {
    $service = sijilDraft();
    $row = $service->crews()->sole();
    $path = $row->documents()->first()->file_path;
    Livewire::test('pages::services.sijil-form', ['service' => $service])
        ->call('removeCrew', 0)->call('save')->assertHasErrors('readiness');
    expect($service->crews()->count())->toBe(1)->and($row->fresh()->removed_at)->toBeNull();
    Storage::disk('local')->assertExists($path);
});

test('reused crew documents display the source vessel filename across vessels', function () {
    $completed = sijilCompleted();
    $completed->vessel->update(['vessel_name' => 'KM Asal']);
    $crew = Crew::sole();
    $document = $completed->documents()->where('document_type', 'seafarer_book')->sole();
    $document->update(['uploaded_at' => '2026-10-07 17:30:00']);
    $service = SijilService::factory()->for(Vessel::factory()->state(['vessel_name' => 'KM Tujuan']))->create();
    SijilServiceCrew::factory()->for($service, 'sijilService')->for($crew)->create();
    $expected = 'Sijil-Awak-Kapal-Buku-Pelaut_KM-Asal_2026-10-08_'.$document->id.'.pdf';

    $this->get(route('sijil.show', $service))->assertOk()->assertSee($expected);
    $this->get(route('sijil.documents.download', [$completed, $document]))->assertDownload($expected);
});
