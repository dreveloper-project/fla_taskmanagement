<?php

use App\Models\SafetyCertificateService;
use App\Models\User;
use App\Models\Vessel;
use App\Services\SafetyCertificateWorkflow;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../SafetyCertificateFixtures.php';

beforeEach(function () {
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 7)->setTime(16, 0));
    Storage::fake('local');
});

test('safety pages enforce staff access', function (string $route) {
    $service = SafetyCertificateService::factory()->create();
    $url = route($route, ['service' => $service]);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'client']))->get($url)->assertForbidden();
    $this->actingAs($service->assignedStaff)->get($url)->assertOk();
})->with(['safety-certificates.index', 'safety-certificates.create', 'safety-certificates.show', 'safety-certificates.edit']);

test('staff create an incomplete renewal with passenger selection and missing certificate warning', function () {
    $staff = User::factory()->create();
    $passenger = Vessel::factory()->create(['vessel_name' => 'KM Passenger']);
    Vessel::factory()->create(['vessel_name' => 'Cargo Hidden', 'vessel_type' => 'cargo']);
    Livewire::actingAs($staff)->test('pages::services.safety-certificate-form')
        ->assertSee('KM Passenger')->assertDontSee('Cargo Hidden')
        ->set('form.vessel_id', $passenger->id)->set('form.request_type', 'renewal')
        ->assertSee('Sertifikat lama belum tersedia pada data Vessel.')
        ->call('save')->assertHasNoErrors()
        ->assertRedirect(route('safety-certificates.show', SafetyCertificateService::sole()));
    expect(SafetyCertificateService::sole()->status)->toBe('draft');
});

test('vessel action prefills renewal and rejects another vessel certificate', function () {
    $service = safetyDraft('renewal');
    $document = $service->vessel->documents()->where('document_type', 'safety_certificate')->sole();
    $this->get($document->serviceUrl())->assertOk()->assertSee('OLD-2025-001');
    Livewire::withQueryParams(['vessel_id' => $service->vessel_id, 'request_type' => 'renewal', 'previous_document_id' => $document->id])
        ->test('pages::services.safety-certificate-form')
        ->assertSet('form.vessel_id', $service->vessel_id)
        ->assertSet('form.request_type', 'renewal')->assertSet('previousDocumentId', $document->id);
    $other = Vessel::factory()->create();
    $this->get(route('safety-certificates.create', ['vessel_id' => $other->id, 'previous_document_id' => $document->id]))->assertNotFound();
});

test('saving uses the submitted passenger vessel when the live selection has not synchronized', function () {
    $staff = User::factory()->create();
    $passenger = Vessel::factory()->create();

    Livewire::actingAs($staff)->test('pages::services.safety-certificate-form')
        ->assertSet('form.vessel_id', '')
        ->call('save', (string) $passenger->id)
        ->assertHasNoErrors()
        ->assertRedirect(route('safety-certificates.show', SafetyCertificateService::sole()));

    $this->assertDatabaseHas('safety_certificate_services', [
        'vessel_id' => $passenger->id,
        'assigned_staff_id' => $staff->id,
        'status' => 'draft',
    ]);
});

test('submitted vessel selection is still required and restricted to passenger vessels', function (string $selection) {
    $staff = User::factory()->create();
    $passenger = Vessel::factory()->create();
    $cargo = Vessel::factory()->create(['vessel_type' => 'cargo']);

    Livewire::actingAs($staff)->test('pages::services.safety-certificate-form')
        ->set('form.vessel_id', $passenger->id)
        ->call('save', $selection === 'cargo' ? (string) $cargo->id : '')
        ->assertHasErrors(['vessel_id']);

    $this->assertDatabaseCount('safety_certificate_services', 0);
})->with(['empty', 'cargo']);

test('submitted vessel is retained when another field fails validation and the draft is retried', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->create();
    $passenger = Vessel::factory()->create();

    $page = Livewire::actingAs($admin)->test('pages::services.safety-certificate-form')
        ->set('form.priority', 'invalid')
        ->call('save', (string) $passenger->id)
        ->assertHasErrors(['priority'])
        ->assertHasNoErrors(['vessel_id'])
        ->assertSet('form.vessel_id', (string) $passenger->id);

    $this->assertDatabaseCount('safety_certificate_services', 0);

    $page->set('form.assigned_staff_id', $staff->id)
        ->set('form.priority', 'medium')
        ->call('save', (string) $passenger->id)
        ->assertHasNoErrors()
        ->assertRedirect(route('safety-certificates.show', SafetyCertificateService::sole()));

    $this->assertDatabaseHas('safety_certificate_services', [
        'vessel_id' => $passenger->id,
        'assigned_staff_id' => $staff->id,
    ]);
});

test('livewire actions persist readiness submission official certificate and handover', function () {
    $service = safetyDraft();
    $page = Livewire::test('pages::services.safety-certificate-detail', ['service' => $service])
        ->call('ready')->assertHasNoErrors()->assertSee('Mark as Applied')
        ->set('form.submitted_at', '2026-10-06T09:00')->call('apply')->assertHasNoErrors()
        ->set('form.result', 'approved');
    foreach (safetyIssueInput() as $field => $value) {
        $page->set('form.'.$field, $value);
    }
    $page->set('documents.issued_certificate', safetyPdf())->call('recordReview')->assertHasNoErrors();
    expect($service->fresh()->status)->toBe('ready_for_handover');
    $page->set('form.handed_over_to', 'Nakhoda')
        ->set('form.handed_over_at', '2026-10-07T10:00')->call('handover')->assertHasNoErrors();
    expect($service->fresh()->status)->toBe('completed');
    $this->get(route('safety-certificates.show', $service))->assertOk()->assertSee('CERT-2026-0034')->assertSee('Nakhoda');
    $this->get(route('safety-certificates.edit', $service))->assertForbidden();
});

test('monitoring filters persisted services and attention', function () {
    $service = safetyDraft('renewal');
    $other = SafetyCertificateService::factory()->create(['status' => 'completed']);
    Livewire::test('pages::services.safety-certificates')
        ->assertSee($service->service_number)->assertSee($other->service_number)
        ->set('status', 'attention')->assertSee($service->service_number)->assertDontSee($other->service_number)
        ->set('status', '')->set('search', $other->service_number)->assertDontSee($service->service_number)->assertSee($other->service_number)
        ->set('search', '')->set('requestType', 'renewal')->assertSee($service->service_number)->assertDontSee($other->service_number);
});

test('certificate files and old versions require staff and correct resource scope', function () {
    $service = safetyIssued('renewal');
    $document = $service->documents()->where('document_type', 'issued_certificate')->sole();
    $url = route('safety-certificates.documents.download', ['service' => $service, 'document' => $document]);
    $this->get($url)->assertOk()->assertDownload();
    $other = SafetyCertificateService::factory()->create();
    $this->get(route('safety-certificates.documents.download', ['service' => $other, 'document' => $document]))->assertNotFound();
    $version = $service->vessel->documents()->where('document_type', 'safety_certificate')->sole()->versions()->sole();
    $versionUrl = route('vessel-document-versions.download', ['vessel' => $service->vessel, 'version' => $version]);
    $this->get($versionUrl)->assertOk()->assertDownload();
    $this->get(route('vessel-document-versions.download', ['vessel' => $other->vessel, 'version' => $version]))->assertNotFound();
    Livewire::test('pages::dashboard.vessel')->call('detail', $service->vessel_id)->assertSee('CERT-2026-0034')->assertSee('OLD-2025-001');
    $this->actingAs(User::factory()->create(['role' => 'client']))->get($url)->assertForbidden();
    $this->get($versionUrl)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});

test('overview includes persisted safety activity and completion today', function () {
    $active = safetyDraft('renewal');
    $issued = safetyIssued();
    app(SafetyCertificateWorkflow::class)->handover($issued, ['handed_over_to' => 'Captain', 'handed_over_at' => '2026-10-07T10:00']);
    Livewire::test('pages::dashboard.overview')
        ->assertSet('activeServicesCount', 1)->assertSet('completedTodayCount', 1)
        ->assertSet('needAttentionCount', 2)->assertSee($active->service_number);
});
