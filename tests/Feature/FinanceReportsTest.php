<?php

use App\Models\BunkerService;
use App\Models\Client;
use App\Models\FinanceEntry;
use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use App\Services\FinanceInvoicing;
use App\Services\FinanceReports;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('finance reports and exports require an administrator', function () {
    $this->get(route('finance.reports.export'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('finance.reports.export'))->assertForbidden();
});

test('reports filter service completion in local time and exclude non billable SPB', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $vessel = Vessel::factory()->for($client)->create();
    $included = SpbService::factory()->for($client)->for($vessel)->create(['status' => 'completed', 'billable' => true, 'agent_fee' => '100', 'completed_at' => '2026-10-31 16:30:00']);
    SpbService::factory()->for($client)->create(['status' => 'completed', 'billable' => false, 'agent_fee' => '900', 'completed_at' => '2026-11-01 04:00:00']);
    BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '200', 'completed_at' => '2026-11-01 04:00:00']);
    $page = Livewire::test('pages::dashboard.finance-reports')
        ->set('filters.from', '2026-11-01')->set('filters.to', '2026-11-01')
        ->set('filters.client', (string) $client->id)->set('filters.vessel', (string) $vessel->id)
        ->call('applyFilters')->assertHasNoErrors()->assertSee($included->service_number);
    expect($page->instance()->data['count'])->toBe(1)->and($page->instance()->data['amount'])->toBe('100');
    $page->set('filters.to', '2026-10-31')->call('applyFilters')->assertHasErrors();
});

test('combined payments are counted once and never allocated to individual service balances', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100', 'completed_at' => '2026-11-02 04:00:00']);
    $bunker = BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '200', 'completed_at' => '2026-11-02 04:00:00']);
    $invoicing = app(FinanceInvoicing::class);
    $invoice = $invoicing->createManual($client->id, ['spb:'.$spb->id, 'bunker:'.$bunker->id], '2026-11-15');
    $invoicing->recordPayment($invoice->id, '75', '2026-11-03', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), (string) Str::uuid());
    $filters = FinanceReports::validateFilters(['from' => '2026-11-01', 'to' => '2026-11-30', 'client' => (string) $client->id]);
    $reports = app(FinanceReports::class);
    $bills = $reports->bills($filters);
    expect($bills)->toHaveCount(2);
    expect($bills->pluck('combined')->all())->toBe([true, true]);
    expect($bills->pluck('balance')->all())->toBe([null, null]);
    expect($bills->pluck('status')->all())->toBe(['Dibayar sebagian', 'Dibayar sebagian']);
    expect($reports->payments($filters)->pluck('amount')->all())->toBe(['75.00']);
    $balances = $reports->rows(array_replace($filters, ['view' => 'balances']), $bills);
    expect($balances)->toHaveCount(1)->and($balances[0]['total'])->toBe('300.00')->and($balances[0]['paid'])->toBe('75.00')->and($balances[0]['balance'])->toBe('225.00');
    $clientRows = $reports->rows(array_replace($filters, ['view' => 'clients']), $bills);
    expect($clientRows[0]['received'])->toBe('75.00')->and($clientRows[0]['balance'])->toBe('225.00');
    Livewire::test('pages::dashboard.finance-reports')->set('filters.from', '2026-11-01')->set('filters.to', '2026-11-30')->call('applyFilters')->call('changeView', 'balances')->assertSee('225,00');
    $invoicing->recordPayment($invoice->id, '225', '2026-11-04', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), (string) Str::uuid());
    expect($reports->rows(array_replace($filters, ['view' => 'paid'])))->toHaveCount(2);
});

test('payments use transaction date and include direct SPB payments', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $spb = SpbService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100', 'completed_at' => '2026-10-01']);
    FinanceEntry::factory()->create(['client_id' => $client->id, 'spb_service_id' => $spb->id, 'type' => 'income', 'amount' => '40', 'transaction_date' => '2026-11-01']);
    $page = Livewire::test('pages::dashboard.finance-reports')->set('filters.from', '2026-11-01')->set('filters.to', '2026-11-30')->call('applyFilters')->call('changeView', 'payments');
    expect($page->instance()->data['count'])->toBe(0)->and($page->instance()->data['received'])->toBe('40.00');
});

test('payment report shows refunds and net receipts after a fee correction', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $service = BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '100', 'completed_at' => '2026-11-02 04:00:00']);
    $invoicing = app(FinanceInvoicing::class);
    $invoice = $invoicing->createManual($client->id, ['bunker:'.$service->id], '2026-11-15');
    $proof = fn () => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf');
    $invoicing->recordPayment($invoice->id, '80', '2026-11-03', $proof(), (string) Str::uuid());
    $service->forceFill(['agent_fee' => '60'])->save();
    $invoicing->recordRefund($invoice->id, '20', '2026-11-04', $proof(), (string) Str::uuid());

    $page = Livewire::test('pages::dashboard.finance-reports')->set('filters.from', '2026-11-01')->set('filters.to', '2026-11-30')->call('applyFilters')->call('changeView', 'payments')->assertSee('Pengembalian');
    expect($page->instance()->data['received'])->toBe('60.00');
});

test('all report views can be exported with empty results', function (string $view) {
    if (! class_exists(FinanceReports::class)) {
        $this->markTestSkipped('Laporan keuangan lama sudah tidak digunakan.');
    }
    $this->actingAs(User::factory()->admin()->create());
    $this->get(route('finance.reports.export', ['from' => '2020-01-01', 'to' => '2020-01-31', 'view' => $view]))
        ->assertOk()->assertDownload('finance-'.$view.'-2020-01-01_2020-01-31.xlsx')->streamedContent();
})->with(class_exists(FinanceReports::class) ? array_keys(FinanceReports::VIEWS) : ['removed']);

test('excel exports the selected filtered report as a valid workbook', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create(['name' => 'Client Laporan']);
    BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '250', 'completed_at' => '2026-11-02 04:00:00']);
    $response = $this->get(route('finance.reports.export', ['from' => '2026-11-01', 'to' => '2026-11-30', 'client' => $client->id, 'view' => 'clients']))
        ->assertOk()->assertDownload('finance-clients-2026-11-01_2026-11-30.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'finance-xlsx-');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);
    expect($sheet)->toContain('Client Laporan')->toContain('250')->toContain('Jumlah service');
});

test('client profit summary uses all matching billable services across tabs and pages', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $vessel = Vessel::factory()->for($client)->create();
    SpbService::factory()->for($client)->for($vessel)->create(['status' => 'completed', 'billable' => true, 'agent_fee' => '1000.25', 'pnbp_labuh' => '100.10', 'pnbp_tambat' => '20.05', 'pnbp_mastercable' => '5.00', 'completed_at' => '2026-11-02 04:00:00']);
    BunkerService::factory()->for($client)->for($vessel)->count(16)->create(['status' => 'completed', 'agent_fee' => '50.00', 'completed_at' => '2026-11-02 04:00:00']);
    SpbService::factory()->for($client)->for($vessel)->create(['status' => 'completed', 'billable' => false, 'agent_fee' => '9000', 'pnbp_labuh' => '900', 'completed_at' => '2026-11-02 04:00:00']);
    SpbService::factory()->for($client)->create(['status' => 'completed', 'billable' => true, 'agent_fee' => '8000', 'pnbp_labuh' => '800', 'completed_at' => '2026-10-02 04:00:00']);
    $page = Livewire::test('pages::dashboard.finance-reports')->assertDontSee('Ringkasan profit client')
        ->set('filters.from', '2026-11-01')->set('filters.to', '2026-11-30')
        ->set('filters.client', (string) $client->id)->set('filters.vessel', (string) $vessel->id)
        ->call('applyFilters')->assertSee('Ringkasan profit client')->assertSee('1.800,25')->assertSee('125,15')->assertSee('1.675,10');

    expect($page->instance()->data['profit'])->toMatchArray(['gross' => '1800.25', 'pnbp' => '125.15', 'net' => '1675.10']);
    $page->call('changeView', 'payments')->assertSee('1.675,10');
    $page->set('filters.type', 'bunker')->call('applyFilters')->assertSee('800,00');
    expect($page->instance()->data['profit'])->toMatchArray(['gross' => '800', 'pnbp' => '0', 'net' => '800']);
});

test('client summary treats missing prices as zero and identifies missing labuh amounts', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    SpbService::factory()->for($client)->create(['status' => 'completed', 'billable' => true, 'agent_fee' => null, 'pnbp_labuh' => null, 'completed_at' => '2026-11-02 04:00:00']);

    Livewire::test('pages::dashboard.finance-reports')->set('filters.from', '2026-11-01')->set('filters.to', '2026-11-30')
        ->set('filters.client', (string) $client->id)->call('applyFilters')
        ->assertSee('Ringkasan belum lengkap')->assertSee('1 SPB belum memiliki nominal PNBP labuh');
});

test('missing service prices are zero and do not create outstanding bills', function (string $model) {
    $client = Client::factory()->create();
    $service = $model::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => null, 'completed_at' => '2026-11-02 04:00:00']);
    $filters = FinanceReports::validateFilters(['from' => '2026-11-01', 'to' => '2026-11-30', 'client' => (string) $client->id]);
    $bill = app(FinanceReports::class)->bills($filters)->sole();

    expect($service->fresh()->agent_fee)->toBe('0.00');
    expect($bill)->toMatchArray(['fee' => '0.00', 'balance' => '0.00', 'paid' => true, 'status' => 'Lunas']);
    expect(app(FinanceInvoicing::class)->available($client->id))->toBeEmpty();
})->with([SpbService::class, BunkerService::class]);

test('an issued invoice retains its original price when the service price is missing', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create();
    $service = BunkerService::factory()->for($client)->create(['status' => 'completed', 'agent_fee' => '250', 'completed_at' => '2026-11-02 04:00:00']);
    $invoice = app(FinanceInvoicing::class)->createManual($client->id, ['bunker:'.$service->id], '2026-11-15');
    $service->forceFill(['agent_fee' => null])->save();
    $filters = FinanceReports::validateFilters(['from' => '2026-11-01', 'to' => '2026-11-30', 'client' => (string) $client->id]);

    expect((string) app(FinanceInvoicing::class)->totals($invoice)['balance'])->toBe('250.00');
    expect(app(FinanceReports::class)->bills($filters)->sole())->toMatchArray(['fee' => '250.00', 'balance' => '250.00']);
});

test('filtered Excel export includes styled client profit totals after the table', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = Client::factory()->create(['name' => 'Client Ekspor']);
    SpbService::factory()->for($client)->create(['status' => 'completed', 'billable' => true, 'agent_fee' => '1000', 'pnbp_labuh' => '100', 'pnbp_tambat' => '20', 'pnbp_mastercable' => '5', 'completed_at' => '2026-11-02 04:00:00']);
    $response = $this->get(route('finance.reports.export', ['from' => '2026-11-01', 'to' => '2026-11-30', 'client' => $client->id, 'view' => 'unpaid']))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'finance-xlsx-');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $sheet = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
    $styles = $zip->getFromName('xl/styles.xml');
    $zip->close();
    unlink($path);
    $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    expect((string) $sheet->autoFilter['ref'])->toBe('A1:J2');
    expect((string) $sheet->cols->col[0]['width'])->not->toBe('');
    expect((string) $sheet->sheetData->row[0]->c[0]['s'])->toBe('1');
    expect((string) $sheet->xpath('//x:c[x:is/x:t="Profit bersih (Rp)"]/following-sibling::x:c/x:v')[0])->toBe('875');
    expect($styles)->toContain('cellXfs');
});

test('Excel export without a client filter does not include client profit totals', function () {
    $this->actingAs(User::factory()->admin()->create());
    $response = $this->get(route('finance.reports.export', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'finance-xlsx-');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    expect($sheet)->not->toContain('Ringkasan profit client');
});
