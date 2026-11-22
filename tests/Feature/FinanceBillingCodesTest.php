<?php

use App\Models\SpbService;
use App\Models\User;
use App\Models\Vessel;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('finance recaps departure and arrival billing codes and excludes services without codes', function () {
    $vessel = Vessel::factory()->create(['vessel_name' => 'KM Rekap']);
    $spb = SpbService::factory()->for($vessel)->create([
        'service_number' => 'SPB-REKAP', 'status' => 'completed',
        'billing_code_labuh' => '00001234', 'arrival_billing_code_rambu' => 'RAMBU-ARR',
    ]);
    SpbService::factory()->create(['service_number' => 'SPB-KOSONG']);

    Livewire::test('pages::dashboard.finance')->assertSee('Rekap kode billing')
        ->assertSee('SPB-REKAP')->assertSee('KM Rekap')->assertSee('00001234')
        ->assertSee('RAMBU-ARR')->assertSee('Keberangkatan')->assertSee('Kedatangan')
        ->assertSee(route('spb.show', $spb), false)->assertDontSee('SPB-KOSONG');
});

test('billing type and phase filters select only matching codes', function () {
    SpbService::factory()->create(['service_number' => 'SPB-A', 'billing_code_labuh' => 'DEPART-LABUH', 'arrival_billing_code_rambu' => 'ARR-RAMBU']);
    SpbService::factory()->create(['service_number' => 'SPB-B', 'arrival_billing_code_labuh' => 'ARR-LABUH']);

    $page = Livewire::test('pages::dashboard.finance')
        ->set('billingType', 'labuh')->set('phase', 'arrival')
        ->assertSee('SPB-B')->assertSee('ARR-LABUH')->assertDontSee('SPB-A');
    $page->set('billingType', 'rambu')->assertSee('SPB-A')->assertSee('ARR-RAMBU')
        ->assertDontSee('DEPART-LABUH')->assertDontSee('SPB-B');
    $page->set('phase', 'departure')->assertSee('Tidak ada kode billing sesuai filter.');
});

test('search, vessel and WITA departure date filters combine correctly', function () {
    $vessel = Vessel::factory()->create(['vessel_name' => 'Kapal Utama']);
    $other = Vessel::factory()->create(['vessel_name' => 'Kapal Lain']);
    SpbService::factory()->for($vessel)->create(['service_number' => 'SPB-TARGET', 'billing_code_mastercable' => 'MC-007', 'estimated_departure_at' => '2026-11-03 16:00:00']);
    SpbService::factory()->for($vessel)->create(['service_number' => 'SPB-BEFORE', 'billing_code_mastercable' => 'MC-008', 'estimated_departure_at' => '2026-11-03 15:59:59']);
    SpbService::factory()->for($other)->create(['service_number' => 'SPB-OTHER', 'billing_code_mastercable' => 'MC-007', 'estimated_departure_at' => '2026-11-04 15:59:59']);
    SpbService::factory()->for($vessel)->create(['service_number' => 'SPB-AFTER', 'billing_code_mastercable' => 'MC-007', 'estimated_departure_at' => '2026-11-04 16:00:00']);

    Livewire::test('pages::dashboard.finance')->set('dateFrom', '2026-11-04')->set('dateTo', '2026-11-04')
        ->set('vessel', (string) $vessel->id)->set('search', 'MC-007')
        ->assertSee('SPB-TARGET')->assertDontSee('SPB-BEFORE')->assertDontSee('SPB-OTHER')->assertDontSee('SPB-AFTER');
});

test('finance access is limited to authorized users', function () {
    $this->actingAs(User::factory()->create(['role' => 'staff']))->get(route('finance.index'))->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'finance']))->get(route('finance.index'))->assertOk();
});

test('finance filters can be reset together', function () {
    SpbService::factory()->create(['service_number' => 'SPB-RESET', 'billing_code_labuh' => 'CODE-01']);
    Livewire::test('pages::dashboard.finance')
        ->set('search', 'not-found')->set('billingType', 'labuh')->set('phase', 'arrival')
        ->set('dateFrom', '2026-11-05')->set('dateTo', '2026-11-04')
        ->assertSee('Tanggal akhir harus sama dengan atau setelah tanggal awal.')
        ->call('resetFilters')
        ->assertSet('search', '')->assertSet('billingType', '')->assertSet('phase', '')
        ->assertSet('dateFrom', '')->assertSet('dateTo', '')->assertSee('SPB-RESET');
});

test('Excel export includes all filtered pages and preserves billing codes as text', function () {
    $vessel = Vessel::factory()->create(['vessel_name' => 'Kapal Export']);
    foreach (range(1, 12) as $number) {
        SpbService::factory()->for($vessel)->create([
            'service_number' => 'SPB-EXPORT-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'estimated_departure_at' => '2026-11-04 01:00:00',
            'arrival_billing_code_labuh' => $number === 1 ? '00001234' : 'LABUH-'.$number,
            'billing_code_labuh' => 'DEPART-SECRET',
        ]);
    }
    SpbService::factory()->for($vessel)->create(['service_number' => 'SPB-OUTSIDE', 'estimated_departure_at' => '2026-11-04 16:00:00', 'arrival_billing_code_labuh' => 'OUTSIDE']);

    $page = Livewire::test('pages::dashboard.finance')
        ->set('phase', 'arrival')->set('billingType', 'labuh')
        ->set('dateFrom', '2026-11-04')->set('dateTo', '2026-11-04')
        ->call('exportExcel')->assertFileDownloaded('rekap-kode-billing.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'finance-test-');
    file_put_contents($path, base64_decode($page->effects['download']['content']));
    $archive = new ZipArchive;
    expect($archive->open($path))->toBeTrue();
    $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
    $archive->close();
    unlink($path);

    expect($sheet)->toContain('SPB-EXPORT-01', 'SPB-EXPORT-12', '00001234', 'Kapal Export', '04-11-2026 09:00', 'Kedatangan · Labuh')
        ->not->toContain('SPB-OUTSIDE', 'DEPART-SECRET', '<f>');
    $xml = simplexml_load_string($sheet);
    $cells = $xml->xpath('//*[local-name()="c"]');
    $codeCells = array_filter($cells, fn (SimpleXMLElement $cell): bool => (string) $cell->is->t === '00001234');
    expect($codeCells)->toHaveCount(1)->and((string) reset($codeCells)['t'])->toBe('inlineStr');
});
