<?php

use App\Models\ShipNationalityService;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselDocument;
use App\Services\ShipNationalityWorkflow;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../ShipNationalityFixtures.php';

beforeEach(function () {
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 7)->setTime(16, 0));
    Storage::fake('local');
});

test('nationality pages enforce staff access', function (string $route) {
    $service = ShipNationalityService::factory()->create();
    $url = route($route, ['service' => $service]);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'client']))->get($url)->assertForbidden();
    $this->actingAs($service->assignedStaff)->get($url)->assertOk();
})->with(['ship-nationality.index', 'ship-nationality.create', 'ship-nationality.show', 'ship-nationality.edit']);

test('staff save incomplete renewal for any vessel and see missing old document', function () {
    $staff = User::factory()->create();
    $vessel = Vessel::factory()->create(['vessel_name' => 'KM Cargo', 'vessel_type' => 'cargo']);
    Livewire::actingAs($staff)->test('pages::services.ship-nationality-form')
        ->assertSee('KM Cargo')
        ->set('form.vessel_id', $vessel->id)->set('form.document_type', 'pas_kecil')->set('form.request_type', 'renewal')
        ->assertSee('Dokumen lama belum tersedia.')->assertSee('Lengkapi di Vessel')
        ->call('save')->assertHasNoErrors()->assertRedirect(route('ship-nationality.show', ShipNationalityService::sole()));
    expect(ShipNationalityService::sole()->status)->toBe('draft');
});

test('form reports missing required inputs without creating service', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::services.ship-nationality-form')
        ->set('form.request_type', '')->call('save')
        ->assertHasErrors(['vessel_id', 'document_type', 'request_type']);
    $this->assertDatabaseCount('ship_nationality_services', 0);
});

test('vessel action prefills matching renewal and refuses cross vessel or type reference', function (string $type) {
    $service = nationalityDraft('renewal', $type);
    $document = $service->vessel->documents()->where('document_type', $type)->sole();
    $this->get($document->serviceUrl())->assertOk()->assertSee('OLD-NATIONALITY');
    Livewire::withQueryParams(['vessel_id' => $service->vessel_id, 'document_type' => $type, 'request_type' => 'renewal', 'previous_document_id' => $document->id])
        ->test('pages::services.ship-nationality-form')->assertSet('form.vessel_id', $service->vessel_id)
        ->assertSet('form.document_type', $type)->assertSet('form.request_type', 'renewal')->assertSet('previousDocumentId', $document->id);
    $other = Vessel::factory()->create();
    $this->get(route('ship-nationality.create', ['vessel_id' => $other->id, 'document_type' => $type, 'previous_document_id' => $document->id]))->assertNotFound();
    $this->get(route('ship-nationality.create', ['vessel_id' => $service->vessel_id, 'document_type' => $type === 'surat_laut' ? 'pas_kecil' : 'surat_laut', 'previous_document_id' => $document->id]))->assertNotFound();
})->with(['surat_laut', 'pas_besar', 'pas_kecil']);

test('livewire completes submission issuance and handover and locks editing', function () {
    $service = nationalityDraft();
    $page = Livewire::test('pages::services.ship-nationality-detail', ['service' => $service])
        ->call('ready')->assertHasNoErrors()->assertSee('Mark as Submitted')
        ->set('form.submitted_at', '2026-10-06T09:00')->call('apply')->assertHasNoErrors()
        ->set('form.result', 'document_issued');
    foreach (nationalityIssueInput() as $field => $value) {
        $page->set('form.'.$field, $value);
    }
    $page->set('documents.issued_document', nationalityPdf())->call('recordReview')->assertHasNoErrors();
    expect($service->fresh()->status)->toBe('ready_for_handover');
    $page->set('form.handed_over_to', 'Nakhoda')->set('form.handed_over_at', '2026-10-07T10:00')->call('handover')->assertHasNoErrors();
    expect($service->fresh()->status)->toBe('completed');
    $this->get(route('ship-nationality.show', $service))->assertOk()->assertSee('NEW-NATIONALITY')->assertSee('Nakhoda');
    $this->get(route('ship-nationality.edit', $service))->assertForbidden();
});

test('monitoring filters persisted document types requests vessels dates and search', function () {
    $service = nationalityDraft('renewal', 'pas_besar');
    $other = ShipNationalityService::factory()->create(['status' => 'completed', 'created_at' => '2026-09-01 00:00:00']);
    Livewire::test('pages::services.ship-nationality')->assertSee($service->service_number)->assertSee($other->service_number)
        ->set('status', 'attention')->assertSee($service->service_number)->assertDontSee($other->service_number)
        ->set('status', '')->set('search', $other->service_number)->assertDontSee($service->service_number)->assertSee($other->service_number)
        ->set('search', '')->set('documentType', 'pas_besar')->assertSee($service->service_number)->assertDontSee($other->service_number)
        ->set('documentType', '')->set('requestType', 'renewal')->assertSee($service->service_number)->assertDontSee($other->service_number)
        ->set('requestType', '')->set('vessel', (string) $service->vessel_id)->assertSee($service->service_number)->assertDontSee($other->service_number)
        ->set('vessel', '')->set('dateFrom', '2026-10-01')->assertSee($service->service_number)->assertDontSee($other->service_number);
});

test('downloads enforce staff parent scope and physical file existence', function () {
    $service = nationalityIssued('renewal');
    $document = $service->issuedDocument;
    $url = route('ship-nationality.documents.download', [$service, $document]);
    $this->get($url)->assertDownload();
    $other = ShipNationalityService::factory()->create();
    $this->get(route('ship-nationality.documents.download', [$other, $document]))->assertNotFound();
    $version = $service->vessel->documents()->where('document_type', 'surat_laut')->sole()->versions()->sole();
    $versionUrl = route('vessel-document-versions.download', ['vessel' => $service->vessel, 'version' => $version]);
    $this->get($versionUrl)->assertDownload();
    Livewire::test('pages::dashboard.vessel')->call('detail', $service->vessel_id)->assertSee('NEW-NATIONALITY')->assertSee('OLD-NATIONALITY');
    $this->actingAs(User::factory()->create(['role' => 'client']))->get($url)->assertForbidden();
    $this->get($versionUrl)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create());
    Storage::disk('local')->delete($document->file_path);
    $this->get($url)->assertNotFound();
});

test('overview uses latest vessel expiry and nationality service completion', function () {
    $service = nationalityApplied('renewal');
    Livewire::test('pages::dashboard.overview')->assertSet('activeServicesCount', 1)->assertSet('expiredDocumentsCount', 1)->assertSet('needAttentionCount', 2)->assertSee($service->service_number);
    $issued = app(ShipNationalityWorkflow::class)->issueDocument($service, nationalityIssueInput(), ['issued_document' => nationalityPdf()]);
    app(ShipNationalityWorkflow::class)->handover($issued, ['handed_over_to' => 'Captain', 'handed_over_at' => '2026-10-07T10:00']);

    Livewire::test('pages::dashboard.overview')->assertSet('activeServicesCount', 0)->assertSet('completedTodayCount', 1)->assertSet('expiredDocumentsCount', 0)->assertSet('needAttentionCount', 0);
});

test('attention only follows matching document type and active overdue services', function () {
    $service = nationalityDraft();
    VesselDocument::factory()->for($service->vessel)->create(['document_type' => 'pas_kecil', 'expired_date' => '2026-01-01']);
    expect(ShipNationalityService::needsAttention()->count())->toBe(0);
    $service->update(['due_at' => now()->subHour()]);
    expect(ShipNationalityService::needsAttention()->sole()->id)->toBe($service->id);
    $service->update(['status' => 'cancelled']);
    expect(ShipNationalityService::needsAttention()->count())->toBe(0);
});
