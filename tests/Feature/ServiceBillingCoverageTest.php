<?php

use App\Models\BunkerService;
use App\Models\Client;
use App\Models\FinanceInvoice;
use App\Models\SafetyCertificateService;
use App\Models\ShipNationalityService;
use App\Models\SijilService;
use App\Models\SpbService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('completed services appear in billing and move to paid after payment', function (string $type, string $class) {
    Storage::fake('local');
    $client = Client::factory()->create();
    $service = $class::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100']);
    $draft = $class::factory()->for($client)->create(['status' => 'draft', 'agent_fee' => '100']);

    $page = Livewire::actingAs(User::factory()->admin()->create())->test('pages::dashboard.finance')
        ->set('filterClientId', (string) $client->id)->call('applyBillFilters')
        ->assertSee($service->service_number)->assertDontSee($draft->service_number)
        ->set('selectedBillKeys', [$type.':'.$service->id])->call('paySelectedServiceBills')->assertHasNoErrors();
    $invoice = FinanceInvoice::firstOrFail();
    $page->set('invoicePaymentDate', '2026-11-01')->set('invoicePaymentProof', UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf'))
        ->call('saveInvoicePayment')->assertHasNoErrors()->call('setServiceBillTab', 'paid')
        ->assertSee($service->service_number);
    expect($invoice->fresh()->settled_at)->not->toBeNull();
})->with([
    'SPB' => ['spb', SpbService::class],
    'Bunker' => ['bunker', BunkerService::class],
    'Sertifikat Keselamatan' => ['safety-certificates', SafetyCertificateService::class],
    'Sijil' => ['sijil', SijilService::class],
    'Kebangsaan Kapal' => ['ship-nationality', ShipNationalityService::class],
]);
