<?php

use App\Models\BunkerService;
use App\Models\SpbService;
use App\Models\User;
use App\Models\VesselDocument;
use App\Services\ReportService;

require_once __DIR__.'/../ReportsFixtures.php';

/** @return array<string, string> */
function reportXlsxParts(string $content): array
{
    $path = tempnam(sys_get_temp_dir(), 'fla-test-xlsx-');
    $archive = new ZipArchive;
    try {
        file_put_contents($path, $content);
        expect($archive->open($path))->toBeTrue();
        $parts = [];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            $xml = $archive->getFromIndex($index);
            expect(simplexml_load_string($xml))->not->toBeFalse();
            $parts[$name] = $xml;
        }

        return $parts;
    } finally {
        $archive->close();
        unlink($path);
    }
}

test('every xlsx export requires an authorized account', function (string $kind) {
    $url = route('reports.export', ['kind' => $kind]);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 'external']))->get($url)->assertForbidden();
})->with(array_keys(ReportService::REPORTS));

test('xlsx exports reuse report filters and include all pages with correct WITA dates', function () {
    $this->actingAs(User::factory()->admin()->create());
    SpbService::factory()->count(11)->create(['created_at' => '2026-09-30 16:00:00', 'destination' => 'Makassar']);
    $excluded = BunkerService::factory()->create(['created_at' => '2026-10-01']);
    SpbService::factory()->create(['created_at' => '2026-09-30 15:59:59', 'service_number' => 'OUTSIDE']);
    $filters = ['date_from' => '2026-10-01', 'date_to' => '2026-10-01', 'service_type' => 'spb'];
    $response = $this->get(route('reports.export', ['kind' => 'services'] + $filters))
        ->assertDownload('services-report-2026-10-01_2026-10-01.xlsx')
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $parts = reportXlsxParts($response->streamedContent());
    expect($parts)->toHaveKeys(['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml']);
    $worksheet = $parts['xl/worksheets/sheet1.xml'];
    expect($worksheet)->toContain('Destination', 'Hasil SPB', 'Billing', '01 Oct 2026 00:00', 'Makassar')->not->toContain($excluded->service_number, 'OUTSIDE');
    expect(substr_count($worksheet, 'SPB-TEST-'))->toBe(11);
    expect(simplexml_load_string($worksheet)->sheetData->row)->toHaveCount(12);
    expect((string) simplexml_load_string($worksheet)->autoFilter['ref'])->toBe('A1:R12');
});

test('document export includes filtered archived versions but never private paths or urls', function () {
    $this->actingAs(User::factory()->admin()->create());
    $doc = VesselDocument::factory()->create(['file_path' => 'private/current-secret.pdf', 'certificate_number' => 'CURRENT']);
    $doc->versions()->create(['certificate_number' => 'ARCHIVE', 'file_path' => 'private/old-secret.pdf', 'original_filename' => 'old.pdf', 'status' => 'superseded', 'archived_at' => now()]);
    $content = $this->get(route('reports.export', ['kind' => 'documents', 'document_scope' => 'versions']))->assertOk()->streamedContent();
    $worksheet = reportXlsxParts($content)['xl/worksheets/sheet1.xml'];
    expect($worksheet)->toContain('ARCHIVE', 'Version')->not->toContain('CURRENT', 'private/', 'file_path', '/download', 'http://');
});

test('xlsx stores formula-like user controlled fields as literal text', function (string $value) {
    $this->actingAs(User::factory()->admin()->create());
    SpbService::factory()->create(['service_number' => $value, 'created_at' => '2026-10-01']);
    $content = $this->get(route('reports.export', ['kind' => 'services', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']))->assertOk()->streamedContent();
    $worksheet = simplexml_load_string(reportXlsxParts($content)['xl/worksheets/sheet1.xml']);
    $cell = $worksheet->sheetData->row[1]->c[0];
    expect((string) $cell['t'])->toBe('inlineStr');
    expect((string) $cell->is->t)->toBe($value);
    expect($worksheet->xpath('//*[local-name()="f"]'))->toBeEmpty();
})->with(['=HYPERLINK("bad")', '+SUM(1,2)', '-1+2', '@SUM(A1)', '  =1+1']);

test('every report exports a valid xlsx with headers even when no rows match', function (string $kind, string $header) {
    $this->actingAs(User::factory()->admin()->create());
    $content = $this->get(route('reports.export', ['kind' => $kind, 'date_from' => '2020-01-01', 'date_to' => '2020-01-31']))
        ->assertDownload($kind.'-report-2020-01-01_2020-01-31.xlsx')->streamedContent();
    $worksheet = reportXlsxParts($content)['xl/worksheets/sheet1.xml'];
    expect($worksheet)->toContain($header);
})->with([['services', 'Service Number'], ['documents', 'Document Number'], ['vessels', 'Vessel'], ['staff', 'Staff'], ['clients', 'Client'], ['tasks', 'Task']]);

test('xlsx preserves leading zeroes unicode whitespace and XML characters', function () {
    $this->actingAs(User::factory()->admin()->create());
    $value = "00123 – Kapal & <José>\nBaris kedua";
    SpbService::factory()->create(['service_number' => $value, 'created_at' => '2026-10-01']);

    $content = $this->get(route('reports.export', ['kind' => 'services', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']))->assertOk()->streamedContent();
    $worksheet = simplexml_load_string(reportXlsxParts($content)['xl/worksheets/sheet1.xml']);

    expect((string) $worksheet->sheetData->row[1]->c[0]->is->t)->toBe($value);
});

test('unknown export types and invalid filter values are rejected', function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->get(route('reports.export', ['kind' => 'private-files']))->assertNotFound();
    $this->getJson(route('reports.export', ['kind' => 'services', 'service_type' => 'users']))->assertUnprocessable()->assertJsonValidationErrors('service_type');
});
