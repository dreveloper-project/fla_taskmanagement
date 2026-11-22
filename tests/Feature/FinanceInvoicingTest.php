<?php

use App\Models\BunkerService;
use App\Models\Client;
use App\Models\FinanceEntry;
use App\Models\FinanceInvoice;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Services\ClientBillingSchedule;
use App\Services\FinanceInvoicing;
use App\Services\ServiceFeeManager;
use App\Services\SpbPaymentService;
use App\Services\SpbWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function invoiceProof(): UploadedFile
{
    return UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf');
}

test('select all pays eligible filtered services across pages in one invoice', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    $services = SpbService::factory()->count(21)->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $bunker = BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '200']);
    SpbService::factory()->for($other)->create(['status' => 'completed', 'agent_fee' => '500']);
    FinanceEntry::factory()->create(['spb_service_id' => $services[0]->id, 'type' => 'income', 'amount' => '25']);

    $page = Livewire::test('pages::dashboard.finance')->assertDontSee('Pilih semua layanan yang dapat dibayar')
        ->set('filterClientId', (string) $client->id)->call('applyBillFilters')
        ->assertSee('Pilih semua layanan yang dapat dibayar')->call('toggleAllServiceBills')->assertHasNoErrors();
    expect($page->get('selectedBillKeys'))->toHaveCount(22)->toContain('bunker:'.$bunker->id);
    $page->call('paySelectedServiceBills')->assertHasNoErrors()->assertSet('invoicePaymentAmount', '2275.00');
    $invoice = FinanceInvoice::firstOrFail();
    expect($invoice->items()->count())->toBe(22)->and($invoice->client_id)->toBe($client->id);
    $page->set('invoicePaymentDate', '2026-11-01')->set('invoicePaymentProof', invoiceProof())
        ->call('saveInvoicePayment')->assertHasNoErrors();
    expect($invoice->fresh()->settled_at)->not->toBeNull();
    $page->call('setServiceBillTab', 'paid');
    expect($page->instance()->serviceBills->total())->toBe(22);
});

test('bulk payment rejects services outside the selected client and already invoiced services', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    $service = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $otherService = SpbService::factory()->for($other)->create(['status' => 'completed', 'agent_fee' => '100']);
    $page = Livewire::test('pages::dashboard.finance')->set('filterClientId', (string) $client->id)->call('applyBillFilters');
    $page->set('selectedBillKeys', ['spb:'.$otherService->id])->call('paySelectedServiceBills')->assertHasErrors('selectedBillKeys');
    expect(FinanceInvoice::count())->toBe(0);

    app(FinanceInvoicing::class)->createManual($client->id, ['spb:'.$service->id], '2026-11-15');
    $page->set('selectedBillKeys', ['spb:'.$service->id])->call('paySelectedServiceBills')->assertHasErrors('selectedBillKeys');
    expect(FinanceInvoice::count())->toBe(1);
    $page->set('filterClientId', (string) $other->id)->assertSet('selectedBillKeys', []);
});

test('service bills filter completed services by client vessels date and settlement', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    $vessels = Vessel::factory()->count(2)->for($client)->create();
    $outside = Vessel::factory()->for($other)->create();
    $first = SpbService::factory()->for($client)->for($vessels[0])->create(['status' => 'completed', 'agent_fee' => '100', 'completed_at' => '2026-11-02 04:00:00']);
    $second = BunkerService::factory()->for($client)->for($vessels[1])->create(['status' => 'completed', 'agent_fee' => '200', 'completed_at' => '2026-11-03 04:00:00']);
    SpbService::factory()->for($other)->for($outside)->create(['status' => 'completed', 'agent_fee' => '300', 'completed_at' => '2026-11-02 04:00:00']);
    SpbService::factory()->for($client)->for($vessels[0])->create(['status' => 'draft', 'agent_fee' => '400']);

    $page = Livewire::test('pages::dashboard.finance')
        ->set('filterClientId', (string) $client->id)
        ->set('filterVesselIds', [(string) $vessels[0]->id, (string) $vessels[1]->id])
        ->set('filterDateFrom', '2026-11-02')->set('filterDateTo', '2026-11-03')
        ->call('applyBillFilters')->assertHasNoErrors();
    expect($page->instance()->serviceBills->getCollection()->pluck('service.service_number')->all())->toEqualCanonicalizing([$first->service_number, $second->service_number]);

    $page->call('payService', 'bunker', $second->id)->assertHasNoErrors();
    $invoice = FinanceInvoice::firstOrFail();
    Storage::fake('local');
    app(FinanceInvoicing::class)->recordPayment($invoice->id, '200', '2026-11-03', invoiceProof(), (string) Str::uuid());
    $page->call('setServiceBillTab', 'paid');
    expect($page->instance()->serviceBills->getCollection()->pluck('service.service_number')->all())->toBe([$second->service_number]);
    $page->set('filterClientId', (string) $other->id)->assertSet('filterVesselIds', []);
    $page->set('filterDateFrom', '')->set('filterDateTo', '2026-11-03')->call('applyBillFilters')->assertHasNoErrors();
});

test('fully paid services appear individually in the paid tab', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $bunker = BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '200']);
    $direct = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '50']);
    $invoicing = app(FinanceInvoicing::class);
    $invoice = $invoicing->createManual($client->id, ['spb:'.$spb->id, 'bunker:'.$bunker->id], '2026-11-15');
    $invoicing->recordPayment($invoice->id, '100', '2026-11-01', invoiceProof(), (string) Str::uuid());
    app(SpbPaymentService::class)->record($direct->id, '50', '2026-11-01', invoiceProof(), (string) Str::uuid());

    $page = Livewire::test('pages::dashboard.finance')->call('setServiceBillTab', 'paid');
    expect($page->instance()->serviceBills->getCollection()->pluck('service.service_number')->all())->toBe([$direct->service_number]);

    $invoicing->recordPayment($invoice->id, '200', '2026-11-01', invoiceProof(), (string) Str::uuid());
    $page->call('setServiceBillTab', 'paid');
    expect($page->instance()->serviceBills->getCollection()->pluck('service.service_number')->all())
        ->toEqualCanonicalizing([$spb->service_number, $bunker->service_number, $direct->service_number]);
    $page->call('setServiceBillTab', 'unpaid');
    expect($page->instance()->serviceBills->total())->toBe(0);
});

test('one invoice combines different services after subtracting existing SPB payments', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '1000']);
    $bunker = BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '2000']);
    FinanceEntry::factory()->create(['spb_service_id' => $spb->id, 'type' => 'income', 'amount' => '250']);
    $billing = app(FinanceInvoicing::class);
    $invoice = $billing->createManual($client->id, ['spb:'.$spb->id, 'bunker:'.$bunker->id], '2026-11-10');
    expect((string) $billing->totals($invoice)['total'])->toBe('2750.00')->and($invoice->items()->count())->toBe(2)
        ->and($billing->available($client->id))->toHaveCount(0);
    expect(fn () => app(SpbPaymentService::class)->record($spb->id, '100', '2026-11-01', invoiceProof(), (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => $billing->createManual($client->id, ['spb:'.$spb->id], '2026-11-10'))->toThrow(ValidationException::class);
});

test('unpaid invoice amounts track price changes, then freeze after full payment', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '1000']);
    $billing = app(FinanceInvoicing::class);
    $invoice = $billing->createManual($client->id, ['spb:'.$spb->id], '2026-11-10');
    $billing->recordPayment($invoice->id, '300', '2026-11-01', invoiceProof(), (string) Str::uuid());
    app(SpbWorkflow::class)->updateAgentFee($spb, '1500');
    expect((string) $billing->totals($invoice->fresh())['balance'])->toBe('1200.00');
    $token = (string) Str::uuid();
    $first = $billing->recordPayment($invoice->id, '1200', '2026-11-01', invoiceProof(), $token);
    $again = $billing->recordPayment($invoice->id, '1200', '2026-11-01', invoiceProof(), $token);
    expect($first->id)->toBe($again->id)->and($invoice->fresh()->settled_at)->not->toBeNull();
    app(SpbWorkflow::class)->updateAgentFee($spb, '1800');
    expect((string) $billing->totals($invoice->fresh())['total'])->toBe('1500.00')
        ->and($invoice->payments()->count())->toBe(2)->and(Storage::disk('local')->files('finance'))->toHaveCount(2);
});

test('scheduled billing issues each due cycle once and ignores incomplete or later services', function () {
    $client = Client::factory()->create(['billing_frequency' => 'weekly', 'billing_day' => 1, 'next_billing_on' => '2026-11-02']);
    $included = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100', 'completed_at' => '2026-11-01 08:00:00']);
    SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100', 'completed_at' => '2026-11-03 08:00:00']);
    SpbService::factory()->for($client)->create(['status' => 'draft', 'agent_fee' => '100']);
    $billing = app(FinanceInvoicing::class);
    expect($billing->generateDueForClient($client->id, CarbonImmutable::parse('2026-11-02')))->toBeTrue()
        ->and($billing->generateDueForClient($client->id, CarbonImmutable::parse('2026-11-02')))->toBeFalse();
    $invoice = FinanceInvoice::firstOrFail();
    expect($invoice->items()->sole()->service_id)->toBe($included->id)->and($client->fresh()->next_billing_on->toDateString())->toBe('2026-11-09');
});

test('invoice payments reject overpayment and require evidence', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $billing = app(FinanceInvoicing::class);
    $invoice = $billing->createManual($client->id, ['spb:'.$spb->id], '2026-11-10');
    expect(fn () => $billing->recordPayment($invoice->id, '101', '2026-11-01', invoiceProof(), (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => $billing->recordPayment($invoice->id, '50', '2026-11-01', null, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(FinanceEntry::count())->toBe(0)->and(Storage::disk('local')->files('finance'))->toBeEmpty();
});

test('finance creates and pays a manually selected combined invoice', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '1000']);
    $bunker = BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '2000']);
    $page = Livewire::actingAs($admin)->test('pages::dashboard.finance')->set('invoiceClientId', (string) $client->id)
        ->assertSee($spb->service_number)->assertSee($bunker->service_number)
        ->set('invoiceDueOn', '2026-11-15')->set('serviceKeys', ['spb:'.$spb->id, 'bunker:'.$bunker->id])
        ->call('createInvoice')->assertHasNoErrors()->assertSee('3.000,00');
    $invoice = FinanceInvoice::firstOrFail();
    $page->set('invoicePaymentAmount', '3000')->set('invoicePaymentDate', '2026-11-01')
        ->set('invoicePaymentProof', invoiceProof())->call('saveInvoicePayment')->assertHasNoErrors()
        ->set('invoiceFilter', 'all')->assertSee('Lunas');
    expect($invoice->fresh()->settled_at)->not->toBeNull()->and($invoice->payments()->count())->toBe(1);
});

test('opening finance generates due scheduled invoices without generating duplicates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Makassar'));
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '1000', 'completed_at' => '2026-11-01 08:00:00']);
    app(ClientBillingSchedule::class)->save($client->id, 'weekly', 1);
    $page = Livewire::test('pages::dashboard.finance')->assertSee($client->name);
    $page->call('showInvoice', FinanceInvoice::firstOrFail()->id)->assertSee($spb->service_number);
    Livewire::test('pages::dashboard.finance');
    expect(FinanceInvoice::count())->toBe(1)->and($client->fresh()->next_billing_on->toDateString())->toBe('2026-11-09');
});

test('invoice list can be filtered by client and payment status', function () {
    $this->actingAs(User::factory()->admin()->create());
    $firstClient = Client::factory()->create();
    $secondClient = Client::factory()->create();
    $firstService = SpbService::factory()->for($firstClient)->create(['status' => 'completed', 'agent_fee' => '100']);
    $secondService = SpbService::factory()->for($secondClient)->create(['status' => 'completed', 'agent_fee' => '200']);
    $billing = app(FinanceInvoicing::class);
    $firstInvoice = $billing->createManual($firstClient->id, ['spb:'.$firstService->id], '2026-11-15');
    $secondInvoice = $billing->createManual($secondClient->id, ['spb:'.$secondService->id], '2026-11-15');
    Storage::fake('local');
    $billing->recordPayment($secondInvoice->id, '200', '2026-11-01', invoiceProof(), (string) Str::uuid());

    $page = Livewire::test('pages::dashboard.finance')->set('invoiceClientFilter', (string) $firstClient->id);
    expect($page->instance()->invoices->pluck('id')->all())->toBe([$firstInvoice->id]);
    $page->set('invoiceClientFilter', '')->set('invoiceFilter', 'paid');
    expect($page->instance()->invoices->pluck('id')->all())->toBe([$secondInvoice->id]);
});

test('manual invoice rejects services belonging to a different client', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $spb = SpbService::factory()->create(['status' => 'completed', 'agent_fee' => '100']);
    expect(fn () => app(FinanceInvoicing::class)->createManual($client->id, ['spb:'.$spb->id], '2026-11-15'))->toThrow(ValidationException::class);
    expect(FinanceInvoice::count())->toBe(0);
});

test('changes to non-SPB fees update an open invoice', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $bunker = BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $billing = app(FinanceInvoicing::class);
    $invoice = $billing->createManual($client->id, ['bunker:'.$bunker->id], '2026-11-15');
    app(ServiceFeeManager::class)->update('bunker', $bunker->id, '200');
    expect((string) $billing->totals($invoice->fresh())['total'])->toBe('200.00');
    Livewire::test('pages::dashboard.finance')->assertSee('200,00');
});

test('scheduled command catches up when it was not run on the billing date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Makassar'));
    $client = Client::factory()->create(['billing_frequency' => 'weekly', 'billing_day' => 1, 'next_billing_on' => '2026-11-02']);
    SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100', 'completed_at' => '2026-11-01 08:00:00']);
    SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '200', 'completed_at' => '2026-11-08 08:00:00']);
    $this->artisan('finance:generate-scheduled-invoices')->assertSuccessful();
    expect(FinanceInvoice::count())->toBe(2)->and($client->fresh()->next_billing_on->toDateString())->toBe('2026-11-23');
    $this->artisan('finance:generate-scheduled-invoices')->assertSuccessful();
    expect(FinanceInvoice::count())->toBe(2);
});

test('monthly billing on the 31st uses the end of shorter months and separates periods', function () {
    $client = Client::factory()->create(['billing_frequency' => 'monthly', 'billing_day' => 31, 'next_billing_on' => '2026-02-28']);
    SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100', 'completed_at' => '2026-02-27 08:00:00']);
    BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '200', 'completed_at' => '2026-03-30 08:00:00']);
    $billing = app(FinanceInvoicing::class);
    expect($billing->generateDue(CarbonImmutable::parse('2026-03-31')))->toBe(2)
        ->and(FinanceInvoice::count())->toBe(2)
        ->and($client->fresh()->next_billing_on->toDateString())->toBe('2026-04-30');
    expect((string) $billing->totals(FinanceInvoice::orderBy('id')->firstOrFail())['total'])->toBe('100.00');
    expect((string) $billing->totals(FinanceInvoice::orderByDesc('id')->firstOrFail())['total'])->toBe('200.00');
});

test('older completed services without a completion date use their archived date for scheduled billing', function () {
    $client = Client::factory()->create(['billing_frequency' => 'weekly', 'billing_day' => 1, 'next_billing_on' => '2026-11-02']);
    $spb = SpbService::factory()->for($client)->create([
        'status' => 'completed', 'agent_fee' => '100', 'completed_at' => null, 'archived_at' => '2026-11-01 08:00:00',
    ]);
    app(FinanceInvoicing::class)->generateDue(CarbonImmutable::parse('2026-11-02'));
    expect(FinanceInvoice::firstOrFail()->items()->sole()->service_id)->toBe($spb->id);
});

test('a price change after settlement preserves the invoice without showing a difference', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $billing = app(FinanceInvoicing::class);
    $invoice = $billing->createManual($client->id, ['spb:'.$spb->id], '2026-11-15');
    $billing->recordPayment($invoice->id, '100', '2026-11-01', invoiceProof(), (string) Str::uuid());
    app(SpbWorkflow::class)->updateAgentFee($spb, '130');
    Livewire::test('pages::dashboard.finance')->assertDontSee('Selisih harga')->set('invoiceFilter', 'all')
        ->call('showInvoice', $invoice->id)->assertSee('100,00')->assertSee('Lunas');
    expect((string) $billing->totals($invoice->fresh())['total'])->toBe('100.00')
        ->and($billing->available($client->id))->toBeEmpty();
});

test('filtering and selecting services across types creates one payable invoice with evidence', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $first = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100', 'completed_at' => '2026-11-01 00:00:00']);
    $second = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '250', 'completed_at' => '2026-11-15 00:00:00']);
    $bunker = BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '300', 'completed_at' => '2026-11-01 00:00:00']);
    $page = Livewire::test('pages::dashboard.finance')->set('filterClientId', (string) $client->id)
        ->set('filterDateTo', '2026-11-10')->call('applyBillFilters')
        ->assertSee($first->service_number)->assertSee($bunker->service_number)->assertDontSee($second->service_number)
        ->set('filterDateTo', '')->call('applyBillFilters')->call('toggleAllServiceBills');
    expect($page->get('selectedBillKeys'))->toHaveCount(3)
        ->toContain('spb:'.$first->id, 'spb:'.$second->id, 'bunker:'.$bunker->id);
    $page->call('paySelectedServiceBills')->assertHasNoErrors();
    $invoice = FinanceInvoice::firstOrFail();
    $page->assertSet('invoicePaymentAmount', '650.00')->set('invoicePaymentDate', '2026-11-01')
        ->set('invoicePaymentProof', invoiceProof())->call('saveInvoicePayment')->assertHasNoErrors();
    expect($invoice->items()->count())->toBe(3)->and($invoice->fresh()->settled_at)->not->toBeNull();
    Storage::disk('local')->assertExists($invoice->payments()->sole()->proof_path);
});

test('overpayment caused by a fee decrease can be refunded and settled', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $billing = app(FinanceInvoicing::class);
    $invoice = $billing->createManual($client->id, ['spb:'.$spb->id], '2026-11-15');
    $billing->recordPayment($invoice->id, '70', '2026-11-01', invoiceProof(), (string) Str::uuid());
    app(SpbWorkflow::class)->updateAgentFee($spb, '50');
    expect((string) $billing->totals($invoice->fresh())['balance'])->toBe('-20.00');
    expect(fn () => $billing->recordRefund($invoice->id, '21', '2026-11-01', invoiceProof(), (string) Str::uuid()))->toThrow(ValidationException::class);
    Livewire::test('pages::dashboard.finance')->call('showInvoice', $invoice->id)->assertSee('Catat pengembalian')
        ->set('invoicePaymentAmount', '5')->set('invoicePaymentDate', '2026-11-01')
        ->set('invoicePaymentProof', invoiceProof())->call('saveInvoiceRefund')->assertHasNoErrors();
    $token = (string) Str::uuid();
    $refund = $billing->recordRefund($invoice->id, '15', '2026-11-01', invoiceProof(), $token);
    expect($billing->recordRefund($invoice->id, '15', '2026-11-01', invoiceProof(), $token)->id)->toBe($refund->id)
        ->and($refund->type)->toBe('expense')->and($invoice->fresh()->settled_at)->not->toBeNull()
        ->and((string) $billing->totals($invoice->fresh())['balance'])->toBe('0.00');
});

test('an invoice can be confirmed settled when a fee change leaves no balance', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $billing = app(FinanceInvoicing::class);
    $invoice = $billing->createManual($client->id, ['spb:'.$spb->id], '2026-11-15');
    $billing->recordPayment($invoice->id, '70', '2026-11-01', invoiceProof(), (string) Str::uuid());
    expect(fn () => $billing->confirmBalanced($invoice->id))->toThrow(ValidationException::class);
    app(SpbWorkflow::class)->updateAgentFee($spb, '70');
    Livewire::test('pages::dashboard.finance')->call('showInvoice', $invoice->id)
        ->assertSee('Konfirmasi tagihan lunas')->call('confirmInvoiceBalanced')->assertHasNoErrors();
    expect($invoice->fresh()->settled_at)->not->toBeNull()->and($invoice->fresh()->settled_by)->toBe(auth()->id());
});

test('an unpaid invoice can be cancelled with a reason and its services can be invoiced again', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $billing = app(FinanceInvoicing::class);
    $invoice = $billing->createManual($client->id, ['spb:'.$spb->id], '2026-11-15');
    $page = Livewire::test('pages::dashboard.finance')->call('showInvoice', $invoice->id)
        ->call('cancelInvoice')->assertHasErrors(['cancellationReason']);
    $page->set('cancellationReason', 'Layanan salah pilih')->call('cancelInvoice')->assertHasNoErrors()->assertSee('Layanan salah pilih');
    expect($invoice->fresh()->cancelled_at)->not->toBeNull()->and($billing->available($client->id))->toHaveCount(1);
    $replacement = $billing->createManual($client->id, ['spb:'.$spb->id], '2026-11-20');
    app(SpbWorkflow::class)->updateAgentFee($spb, '150');
    expect((string) $billing->totals($invoice->fresh())['total'])->toBe('100.00')
        ->and((string) $billing->totals($replacement)['total'])->toBe('150.00');
});

test('an invoice with any recorded payment cannot be cancelled', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $billing = app(FinanceInvoicing::class);
    $invoice = $billing->createManual($client->id, ['spb:'.$spb->id], '2026-11-15');
    $billing->recordPayment($invoice->id, '20', '2026-11-01', invoiceProof(), (string) Str::uuid());
    expect(fn () => $billing->cancel($invoice->id, 'Salah'))->toThrow(ValidationException::class);
    expect($invoice->fresh()->cancelled_at)->toBeNull()->and($invoice->items()->sole()->active_service_key)->toBe('spb:'.$spb->id);
});

test('large monetary values are formatted without losing cents', function () {
    $page = Livewire::actingAs(User::factory()->admin()->create())->test('pages::dashboard.finance');
    expect($page->instance()->formatAmount('99999999999999.90'))->toBe('99.999.999.999.999,90')
        ->and($page->instance()->formatAmount('-1234567.05'))->toBe('-1.234.567,05');
});

test('partially paid combined invoice is not labelled unpaid on its service rows', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $bunker = BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '200']);
    $invoicing = app(FinanceInvoicing::class);
    $invoice = $invoicing->createManual($client->id, ['spb:'.$spb->id, 'bunker:'.$bunker->id], '2026-11-10');
    $invoicing->recordPayment($invoice->id, '50', '2026-11-01', invoiceProof(), (string) Str::uuid());
    Livewire::test('pages::dashboard.finance')->assertSee($spb->service_number)->assertSee($bunker->service_number)
        ->assertSee('Tagihan gabungan dibayar sebagian');
});

test('overpaid combined invoices are flagged until the excess is refunded', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $bunker = BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '200']);
    $invoicing = app(FinanceInvoicing::class);
    $invoice = $invoicing->createManual($client->id, ['spb:'.$spb->id, 'bunker:'.$bunker->id], '2026-11-10');
    $invoicing->recordPayment($invoice->id, '250', '2026-11-01', invoiceProof(), (string) Str::uuid());
    $bunker->forceFill(['agent_fee' => '100'])->save();
    Livewire::test('pages::dashboard.finance')->assertSee($spb->service_number)->assertSee('Tagihan gabungan kelebihan bayar')
        ->assertDontSee('Tagihan gabungan dibayar sebagian');
    $invoicing->recordRefund($invoice->id, '50', '2026-11-01', invoiceProof(), (string) Str::uuid());
    expect($invoice->fresh()->settled_at)->not->toBeNull();
    Livewire::test('pages::dashboard.finance')->call('setServiceBillTab', 'paid')->assertSee($spb->service_number)->assertSee('Lunas');
});
