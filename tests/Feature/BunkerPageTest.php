<?php

use App\Models\BunkerService;
use App\Models\User;
use App\Models\Vessel;
use App\Services\BunkerWorkflow;
use App\Services\DocumentFilename;
use App\Services\TaskAutomationService;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../BunkerFixtures.php';

beforeEach(function () {
    $this->travelTo(now('Asia/Makassar')->setDate(2026, 10, 7)->setTime(16, 0));
    Storage::fake('local');
});

test('bunker routes require staff authentication', function (string $name) {
    $service = BunkerService::factory()->create();
    $url = route($name, ['service' => $service]);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'external']))->get($url)->assertForbidden();
    $this->actingAs($service->assignedStaff)->get($url)->assertOk();
})->with(['bunker.index', 'bunker.create', 'bunker.show', 'bunker.edit']);

test('staff creates an incomplete draft from vessel prefill and sees missing requirements', function () {
    $this->actingAs(User::factory()->create());
    $vessel = Vessel::factory()->create(['vessel_type' => 'tanker']);
    Livewire::withQueryParams(['vessel_id' => $vessel->id])->test('pages::services.bunker-form')
        ->assertSet('form.vessel_id', $vessel->id)->call('save')->assertHasNoErrors()
        ->assertRedirect(route('bunker.show', BunkerService::sole()));
    $service = BunkerService::sole();
    Livewire::test('pages::services.bunker-detail', ['service' => $service])
        ->assertSee('Persyaratan belum lengkap')->assertSee('Pemasok belum diisi.')
        ->call('ready')->assertHasErrors('readiness');
    expect($service->fresh()->status)->toBe('draft');
});

test('form validates vessel and escapes stored supplier content', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::services.bunker-form')->call('save')->assertHasErrors(['vessel_id' => 'required']);
    $service = bunkerDraft();
    $service->update(['supplier' => '<script>alert(1)</script>']);
    $this->get(route('bunker.show', $service))->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
});

test('bunker edit shows saved documents and keeps the download until a replacement is saved', function () {
    $service = bunkerDraft();
    $original = $service->latestDocument('application_letter');
    $request = $service->latestDocument('bunker_request');
    $originalUrl = route('bunker.documents.download', [$service, $original]);

    $page = Livewire::test('pages::services.bunker-form', ['service' => $service])
        ->assertSee('Sudah diunggah')->assertSee('Belum diunggah')
        ->assertSee('application.pdf')->assertSee('request.pdf')
        ->assertSee($originalUrl, false)
        ->assertSee(route('bunker.documents.download', [$service, $request]), false)
        ->set('documents.application_letter', bunkerPdf('application-revised.pdf'))
        ->assertSee('File baru dipilih')->assertSee('application-revised.pdf')
        ->assertSee($originalUrl, false);
    expect($service->documents()->count())->toBe(2);

    $page->call('save')->assertHasNoErrors()->assertRedirect(route('bunker.show', $service));
    $service->refresh();
    $replacement = $service->latestDocument('application_letter');
    expect($replacement->original_filename)->toBe('application-revised.pdf');
    expect($service->documents)->toHaveCount(3);
    Storage::disk('local')->assertExists($original->file_path);
    $this->get(route('bunker.documents.download', [$service, $replacement]))->assertDownload();

    Livewire::test('pages::services.bunker-form', ['service' => $service])
        ->assertSee(route('bunker.documents.download', [$service, $replacement]), false)
        ->assertDontSee($originalUrl, false)->assertDontSee('application.pdf');
});

test('bunker edit marks a missing saved file and hides its download', function () {
    $service = bunkerDraft();
    $document = $service->latestDocument('application_letter');
    Storage::disk('local')->delete($document->file_path);

    Livewire::test('pages::services.bunker-form', ['service' => $service])
        ->assertSee('File tidak tersedia')->assertSee('Unggah ulang dokumen ini.')
        ->assertDontSee(route('bunker.documents.download', [$service, $document]), false)
        ->assertSee(route('bunker.documents.download', [$service, $service->latestDocument('bunker_request')]), false);
});

test('new bunker forms mark documents as not uploaded without download buttons', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::services.bunker-form')
        ->assertSee('Belum diunggah')->assertSee('Pilih dokumen')
        ->assertDontSee('Unduh dokumen tersimpan')->assertDontSee('Sudah diunggah');
});

test('livewire drives full bunker lifecycle with approval upload and completion', function () {
    $service = bunkerDraft();
    $page = Livewire::test('pages::services.bunker-detail', ['service' => $service])
        ->call('ready')->assertHasNoErrors()->assertSee('Mark as Submitted')
        ->set('form.submitted_at', '2026-10-06T09:00')->call('submit')->assertHasNoErrors()
        ->set('form.result', 'approved')->set('form.approved_at', '2026-10-07T09:00')
        ->set('form.approval_number', 'APP-UI-001')
        ->set('documents.approval_document', bunkerPdf())->call('recordReview')->assertHasNoErrors()
        ->assertSee('Confirm Bunker Schedule')->assertSet('form.supplier', 'Supplier Flores')
        ->call('schedule')->assertHasNoErrors()->assertSee('Mark Bunker Completed');
    foreach (bunkerActual() as $field => $value) {
        $page->set('form.'.$field, $value);
    }
    $page->set('documents.activity_proof', bunkerPdf('activity.pdf'))->call('complete')->assertHasNoErrors()
        ->assertSee('Layanan telah diarsipkan.')->assertSee('1400.250');
    expect($service->fresh()->status)->toBe('completed');
    $this->get(route('bunker.show', $service))->assertOk()->assertSee('APP-UI-001');
    $this->get(route('bunker.edit', $service))->assertForbidden();
});

test('monitoring filters status priority vessel and scheduled dates with pagination', function () {
    $this->actingAs(User::factory()->create());
    $selected = BunkerService::factory()->create(['service_number' => 'BKR-SELECTED', 'status' => 'approved', 'priority' => 'urgent', 'scheduled_at' => '2026-10-08 01:00:00']);
    BunkerService::factory()->count(11)->create(['status' => 'draft', 'priority' => 'low', 'scheduled_at' => '2026-10-09 01:00:00']);
    $page = Livewire::test('pages::services.bunker')->assertDontSee('BKR-SELECTED')
        ->call('setPage', 2)->assertSee('BKR-SELECTED')
        ->set('status', 'approved')->assertSee('BKR-SELECTED')->assertSet('paginators.page', 1)
        ->set('status', '')->set('priority', 'urgent')->assertSee('BKR-SELECTED')
        ->set('priority', '')->set('vessel', (string) $selected->vessel_id)->assertSee('BKR-SELECTED')
        ->set('vessel', '')->set('dateFrom', '2026-10-08')->set('dateTo', '2026-10-08')->assertSee('BKR-SELECTED')
        ->set('dateFrom', '2026-10-09')->assertDontSee('BKR-SELECTED');
    $page->set('dateFrom', '')->set('dateTo', '')->set('search', 'BKR-SELECTED')->assertSee('BKR-SELECTED');
});

test('bunker downloads enforce resource scope role authentication and file availability', function () {
    $service = bunkerApproved();
    $document = $service->latestDocument('approval_document');
    $url = route('bunker.documents.download', [$service, $document]);
    $this->get($url)->assertOk()->assertDownload(DocumentFilename::forService($document, $service->vessel))->assertHeader('X-Content-Type-Options', 'nosniff');
    $other = BunkerService::factory()->create();
    $this->get(route('bunker.documents.download', [$other, $document]))->assertNotFound();
    $this->actingAs(User::factory()->create(['role' => 'external']))->get($url)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create());
    Storage::disk('local')->delete($document->file_path);
    $this->get($url)->assertNotFound();
});

test('vessel and overview display real bunker services attention and local day completions', function () {
    $service = bunkerDraft();
    Storage::disk('local')->delete($service->latestDocument('bunker_request')->file_path);
    app(TaskAutomationService::class)->syncForService($service, auth()->id());
    BunkerService::factory()->create(['status' => 'rejected']);
    BunkerService::factory()->create(['status' => 'completed', 'completed_at' => '2026-10-06 16:00:00']);
    BunkerService::factory()->create(['status' => 'completed', 'completed_at' => '2026-10-06 15:59:59']);
    $task = $service->tasks()->active()->sole();
    expect($task->isOverdue())->toBeTrue();
    Livewire::test('pages::dashboard.overview')->assertSet('activeServicesCount', 1)
        ->assertSet('needAttentionCount', 3)->assertSet('completedTodayCount', 1)->assertSee($service->service_number)
        ->assertSee($task->title)->assertSee(route('tasks.show', $task), false);
    Livewire::test('pages::dashboard.vessel')->call('detail', $service->vessel_id)
        ->assertSee($service->service_number)->assertSee('Buat Izin Bunker');
    $this->get(route('bunker.index', ['status' => 'attention']))->assertOk()->assertSee($service->service_number);
});

test('terminal workflow actions remain forbidden through livewire', function () {
    $service = bunkerSubmitted();
    app(BunkerWorkflow::class)->cancel($service, ['cancellation_reason' => 'Tidak jadi']);
    Livewire::test('pages::services.bunker-detail', ['service' => $service->fresh()])
        ->call('ready')->assertHasErrors('state');
    expect($service->fresh()->status)->toBe('cancelled');
});
