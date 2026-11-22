<?php

use App\Models\Client;
use App\Models\SpbDocument;
use App\Models\SpbService;
use App\Models\Task;
use App\Models\User;
use App\Models\Vessel;
use App\Services\FinanceInvoicing;
use App\Services\OperationalCalendar;
use App\Services\SpbPaymentService;
use App\Services\SpbWorkflow;
use App\Services\TaskAutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->client = Client::factory()->create();
    $vessel = Vessel::factory()->for($this->client)->create(['operational_status' => 'berthed']);
    $this->spb = SpbService::factory()->for($vessel)->for($this->client)->create([
        'status' => 'ready_to_sail', 'assigned_staff_id' => $this->admin->id,
        'service_number' => 'SPB-RESULT-TEST', 'spb_issued_at' => now()->subHour(),
        'agent_fee' => '100000', 'actual_departure_at' => null,
    ]);
    $this->issuedSpb = SpbDocument::factory()->for($this->spb, 'spbService')->create(['document_type' => 'issued_spb']);
    Storage::disk('local')->put($this->issuedSpb->file_path, '%PDF-1.4 issued');
});

test('an issued SPB records its outcome and billing decision directly', function (string $result, ?string $decision, bool $billable) {
    $page = Livewire::test('pages::services.spb-detail', ['spb' => $this->spb])
        ->assertSee('Sail Completed')->assertSee('Not Sailing')->assertSee('Fail to Sail')
        ->assertDontSee('Konfirmasi Berlayar')->set('form.sailing_result', $result);
    if ($decision !== null) {
        $page->assertSee('Masukkan SPB ke billing?')->set('form.billable', $decision);
    }
    if ($result === 'failed') {
        $page->set('form.failure_reason', 'Kondisi cuaca');
    }
    if ($result === 'success') {
        $page->assertSee('Tanggal dan waktu keberangkatan')->set('form.actual_departure_at', '2026-10-15T09:30');
    }
    $page->call('finish')->assertHasNoErrors();
    $spb = $this->spb->fresh();
    expect($spb->status)->toBe('completed')->and($spb->sailing_result)->toBe($result)
        ->and($spb->billable)->toBe($billable)
        ->and($spb->vessel->fresh()->operational_status)->toBe('berthed');
    expect($spb->actual_departure_at?->format('Y-m-d H:i'))->toBe($result === 'success' ? '2026-10-15 01:30' : null);
    $billing = Livewire::test('pages::dashboard.finance');
    if ($billable) {
        $billing->assertSee($spb->service_number);
        $invoicing = app(FinanceInvoicing::class);
        expect($invoicing->available($this->client->id)->pluck('key')->all())->toContain('spb:'.$spb->id);
        $invoice = $invoicing->createManual($this->client->id, ['spb:'.$spb->id], now()->toDateString());
        $invoicing->recordPayment($invoice->id, '100000', now()->toDateString(), UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), (string) Str::uuid());
        Livewire::test('pages::dashboard.finance')->call('setServiceBillTab', 'paid')->assertSee($spb->service_number);
    } else {
        $billing->assertDontSee($spb->service_number);
        expect(app(FinanceInvoicing::class)->available($this->client->id)->pluck('key')->all())->not->toContain('spb:'.$spb->id);
        expect(fn () => app(FinanceInvoicing::class)->createManual($this->client->id, ['spb:'.$spb->id], now()->toDateString()))->toThrow(ValidationException::class, 'SPB ini tidak dipilih untuk masuk billing.');
        expect(fn () => app(SpbPaymentService::class)->record($spb->id, '100000', now()->toDateString(), UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), (string) Str::uuid()))->toThrow(ValidationException::class);
    }
})->with([
    'completed' => ['success', null, true],
    'not sailing billed' => ['not_sailing', '1', true],
    'not sailing not billed' => ['not_sailing', '0', false],
    'failed billed' => ['failed', '1', true],
    'failed not billed' => ['failed', '0', false],
]);

test('not sailing and fail to sail require a billing choice', function (string $result) {
    Livewire::test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('form.sailing_result', $result)->set('form.failure_reason', 'Cuaca')
        ->call('finish')->assertHasErrors('billable');
    expect($this->spb->fresh()->status)->toBe('ready_to_sail');
})->with(['not_sailing', 'failed']);

test('sail completed records optional arrival documents and PNBP without increasing the client invoice', function () {
    $this->spb->forceFill(['pnbp_labuh' => '10000'])->save();
    Livewire::test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('form.sailing_result', 'success')
        ->assertSee('Nomor SPB kedatangan (opsional)')
        ->set('form.arrival_pnbp_labuh', '12000')->set('form.arrival_pnbp_mastercable', '3000')
        ->set('form.arrival_spb_number', 'ARR-123')
        ->set('documents.arrival_pnbp_labuh_transfer', UploadedFile::fake()->create('labuh.pdf', 10, 'application/pdf'))
        ->set('documents.arrival_pnbp_mastercable_transfer', UploadedFile::fake()->create('mastercable.pdf', 10, 'application/pdf'))
        ->set('documents.arrival_spb', UploadedFile::fake()->create('arrival.pdf', 10, 'application/pdf'))
        ->call('finish')->assertHasNoErrors()->assertSee('ARR-123');

    $spb = $this->spb->fresh();
    expect($spb->arrival_pnbp_labuh)->toBe('12000.00')
        ->and($spb->arrival_pnbp_mastercable)->toBe('3000.00')
        ->and($spb->arrival_spb_number)->toBe('ARR-123');
    foreach (['arrival_pnbp_labuh_transfer', 'arrival_pnbp_mastercable_transfer', 'arrival_spb'] as $type) {
        $document = $spb->documents()->where('document_type', $type)->sole();
        Storage::disk('local')->assertExists($document->file_path);
        $this->get(route('spb.documents.download', [$spb, $document]))->assertOk();
    }

    $invoicing = app(FinanceInvoicing::class);
    $invoice = $invoicing->createManual($this->client->id, ['spb:'.$spb->id], now()->toDateString());
    expect((string) $invoicing->totals($invoice)['total'])->toBe('100000.00');
    $reports = app(\App\Services\FinanceReports::class);
    expect($reports->profitSummary($reports->bills(\App\Services\FinanceReports::defaults()))['pnbp'])->toBe('25000.00');
});

test('departure confirmation is no longer available', function () {
    expect(fn () => app(SpbWorkflow::class)->depart($this->spb, []))->toThrow(ValidationException::class);
    expect($this->spb->fresh()->status)->toBe('ready_to_sail');
});

test('successful sailing defaults to eight in the morning WITA and requires a valid departure time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 14:30', 'Asia/Makassar'));

    $page = Livewire::test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('form.sailing_result', 'success')
        ->assertSet('form.actual_departure_at', '2026-11-04T08:00')
        ->set('form.actual_departure_at', '')->call('finish')->assertHasErrors('actual_departure_at')
        ->set('form.actual_departure_at', 'invalid')->call('finish')->assertHasErrors('actual_departure_at');
    expect($this->spb->fresh()->status)->toBe('ready_to_sail');

    $page->set('form.actual_departure_at', '2026-11-04T08:00')->call('finish')->assertHasNoErrors()
        ->assertSee('Keberangkatan: 04 Nov 2026 08:00 WITA');
    expect($this->spb->fresh()->actual_departure_at->format('Y-m-d H:i'))->toBe('2026-11-04 00:00');
});

test('only the assigned staff or an admin can record an outcome', function () {
    $other = User::factory()->create();
    Livewire::actingAs($other)->test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('form.sailing_result', 'success')->call('finish')->assertForbidden();
    expect($this->spb->fresh()->status)->toBe('ready_to_sail');
});

test('an active SPB no longer prevents updating vessel operational status', function () {
    $vessel = $this->spb->vessel;
    $vessel->saveWithDocuments(['operational_status' => 'maintenance'], []);
    expect($vessel->fresh()->operational_status)->toBe('maintenance');
});

test('an issued SPB cannot be completed successfully when its official file is missing', function () {
    Storage::disk('local')->delete($this->issuedSpb->file_path);
    Livewire::test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('form.sailing_result', 'success')->call('finish')->assertHasErrors('state');
    expect($this->spb->fresh()->status)->toBe('ready_to_sail');
});

test('the former arrival action cannot bypass the outcome and billing decision', function () {
    $departure = now()->subHour();
    $this->spb->vessel->forceFill(['operational_status' => 'sailing', 'sailed_at' => $departure])->save();
    $this->spb->forceFill(['status' => 'sailing', 'actual_departure_at' => $departure])->save();
    Livewire::test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('arrival.actual_arrival_at', now('Asia/Makassar')->format('Y-m-d\TH:i'))
        ->call('confirmArrival')->assertHasErrors('state');
    expect($this->spb->fresh()->status)->toBe('sailing')->and($this->spb->vessel->fresh()->operational_status)->toBe('sailing');
});

test('recording a legacy sailing outcome does not change vessel status', function (string $result) {
    $departure = now()->subHour();
    $this->spb->vessel->forceFill(['operational_status' => 'sailing', 'sailed_at' => $departure])->save();
    $this->spb->forceFill(['status' => 'sailing', 'actual_departure_at' => $departure])->save();
    $page = Livewire::test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('form.sailing_result', $result)->set('form.billable', '0');
    if ($result === 'failed') {
        $page->set('form.failure_reason', 'Cuaca');
    }
    $page->call('finish')->assertHasNoErrors();
    expect($this->spb->vessel->fresh()->operational_status)->toBe('sailing');
})->with(['not_sailing', 'failed']);

test('ready SPB task directs the staff to record the Inaportnet reply', function () {
    $this->spb->forceFill(['status' => 'ready_to_apply'])->save();
    app(TaskAutomationService::class)->syncForService($this->spb, $this->admin->id);
    expect($this->spb->tasks()->where('status', 'pending')->sole()->title)->toBe('Catat balasan Inaportnet');
});

test('finished SPB does not leave an actionable ETA reminder', function () {
    $departure = now()->subHour();
    $this->spb->vessel->forceFill(['operational_status' => 'sailing', 'sailed_at' => $departure])->save();
    $this->spb->forceFill(['status' => 'completed', 'sailing_result' => 'success', 'actual_departure_at' => $departure, 'expected_arrival_at' => now()->addHour()])->save();
    $events = app(OperationalCalendar::class)->events($this->admin,
        CarbonImmutable::now(config('spb.timezone'))->subDay(), CarbonImmutable::now(config('spb.timezone'))->addDay());
    expect($events->pluck('id')->all())->not->toContain('service:spb:'.$this->spb->id.':expected_arrival_at');
});

test('staff viewing a completed SPB can see its outcome and billing decision', function () {
    $this->spb->forceFill(['status' => 'completed', 'sailing_result' => 'not_sailing', 'billable' => false, 'archived_at' => now()])->save();
    Livewire::actingAs(User::factory()->create())->test('pages::services.spb-detail', ['spb' => $this->spb])
        ->assertSee('NOT SAILING')->assertSee('Tidak masuk billing');
});

test('migration updates only outdated active system task titles and records history', function () {
    $this->spb->forceFill(['status' => 'ready_to_apply'])->save();
    $old = Task::factory()->create(['taskable_type' => SpbService::class, 'taskable_id' => $this->spb->id, 'is_system_generated' => true, 'title' => 'Ajukan SPB', 'status' => 'in_review']);
    $manual = Task::factory()->create(['taskable_type' => SpbService::class, 'taskable_id' => $this->spb->id, 'is_system_generated' => false, 'title' => 'Ajukan SPB']);
    $finished = Task::factory()->create(['taskable_type' => SpbService::class, 'taskable_id' => $this->spb->id, 'is_system_generated' => true, 'title' => 'Ajukan SPB', 'status' => 'completed']);
    $migration = require database_path('migrations/2026_11_02_080654_update_outdated_spb_system_task_titles.php');
    $migration->up();
    $migration->up();
    expect($old->fresh()->title)->toBe('Catat balasan Inaportnet')
        ->and($manual->fresh()->title)->toBe('Ajukan SPB')
        ->and($finished->fresh()->title)->toBe('Ajukan SPB')
        ->and($old->histories()->where('event', 'updated')->count())->toBe(1);
});

test('a finished legacy SPB is not presented as the current voyage', function () {
    $departure = now()->subHour();
    $this->spb->vessel->forceFill(['operational_status' => 'sailing', 'sailed_at' => $departure])->save();
    $this->spb->forceFill(['status' => 'completed', 'sailing_result' => 'not_sailing', 'actual_departure_at' => $departure])->save();
    Livewire::test('dashboard.sailing-monitoring')->assertSee($this->spb->vessel->vessel_name)->assertDontSee($this->spb->service_number);
});

test('old vessel shortcuts redirect to the SPB outcome instead of opening the removed departure form', function () {
    Livewire::test('pages::dashboard.vessel')->call('openDeparture', $this->spb->id)
        ->assertRedirect(route('spb.show', $this->spb));
    expect($this->spb->fresh()->status)->toBe('ready_to_sail');
});

test('a missing issued SPB file can be restored before completing the service', function () {
    Storage::disk('local')->delete($this->issuedSpb->file_path);
    $page = Livewire::test('pages::services.spb-detail', ['spb' => $this->spb])
        ->assertSee('Unggah ulang SPB resmi')->assertSee('File tidak tersedia')
        ->assertDontSee(route('spb.documents.download', [$this->spb->id, $this->issuedSpb->id]), false)
        ->set('documents.issued_spb', UploadedFile::fake()->create('restored.pdf', 10, 'application/pdf'))
        ->call('restoreIssuedSpb')->assertHasNoErrors();
    $new = $this->spb->fresh()->latestDocument('issued_spb');
    expect($new->id)->not->toBe($this->issuedSpb->id);
    Storage::disk('local')->assertExists($new->file_path);
    expect($this->spb->histories()->where('event', 'SPB resmi diunggah ulang')->count())->toBe(1);
    $page->set('form.sailing_result', 'success')->call('finish')->assertHasNoErrors();
    expect($this->spb->fresh()->status)->toBe('completed');
});

test('an available issued SPB cannot be overwritten through the recovery action', function () {
    Livewire::test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('documents.issued_spb', UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'))
        ->call('restoreIssuedSpb')->assertHasErrors('state');
    expect($this->spb->documents()->where('document_type', 'issued_spb')->count())->toBe(1);
});

test('only assigned staff or an admin can restore a missing SPB file', function () {
    Storage::disk('local')->delete($this->issuedSpb->file_path);
    Livewire::actingAs(User::factory()->create())->test('pages::services.spb-detail', ['spb' => $this->spb])
        ->set('documents.issued_spb', UploadedFile::fake()->create('restored.pdf', 10, 'application/pdf'))
        ->call('restoreIssuedSpb')->assertForbidden();
    expect($this->spb->documents()->where('document_type', 'issued_spb')->count())->toBe(1);
});
