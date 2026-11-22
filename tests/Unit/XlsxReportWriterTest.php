<?php

use App\Services\XlsxReportWriter;

/**
 * @param  list<string>  $columns
 * @param  iterable<array<string, string|int|float|null>>  $rows
 */
function xlsxWriterWorksheet(array $columns, iterable $rows): SimpleXMLElement
{
    ob_start();
    try {
        (new XlsxReportWriter)->stream($columns, $rows);
        $content = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    $path = tempnam(sys_get_temp_dir(), 'fla-test-xlsx-');
    $archive = new ZipArchive;
    try {
        file_put_contents($path, $content);
        expect($archive->open($path))->toBeTrue();

        return simplexml_load_string($archive->getFromName('xl/worksheets/sheet1.xml'));
    } finally {
        $archive->close();
        unlink($path);
    }
}

test('xlsx writes numeric values as numbers and identifiers as text', function () {
    $xml = xlsxWriterWorksheet(['Count', 'Quantity', 'Identifier', 'Empty'], [
        ['count' => 12, 'quantity' => 1.25, 'identifier' => '00123', 'empty' => null],
    ]);

    $cells = $xml->sheetData->row[1]->c;
    expect((string) $cells[0]->v)->toBe('12');
    expect((string) $cells[1]->v)->toBe('1.25');
    expect((string) $cells[2]['t'])->toBe('inlineStr');
    expect((string) $cells[2]->is->t)->toBe('00123');
    expect((string) $cells[3]->is->t)->toBe('');
});

test('xlsx supports columns beyond Z and flushes rows without losing data', function () {
    $columns = array_map(fn (int $index): string => 'Column '.$index, range(1, 28));
    $rows = (function (): Generator {
        for ($index = 1; $index <= 501; $index++) {
            yield ['value' => 'Row '.$index];
        }
    })();

    $xml = xlsxWriterWorksheet($columns, $rows);

    expect((string) $xml->sheetData->row[0]->c[26]['r'])->toBe('AA1');
    expect((string) $xml->sheetData->row[0]->c[27]['r'])->toBe('AB1');
    expect($xml->sheetData->row)->toHaveCount(502);
    expect((string) $xml->sheetData->row[501]->c[0]->is->t)->toBe('Row 501');
    expect((string) $xml->autoFilter['ref'])->toBe('A1:AB502');
});

test('xlsx removes invalid XML characters and respects the cell text limit', function () {
    $xml = xlsxWriterWorksheet(['Text', 'Long text'], [
        ['text' => "Kapal\x00 & José\x0B\n第二行", 'long' => str_repeat('é', 33000)],
    ]);

    expect((string) $xml->sheetData->row[1]->c[0]->is->t)->toBe("Kapal & José\n第二行");
    expect(mb_strlen((string) $xml->sheetData->row[1]->c[1]->is->t))->toBe(32767);
});

test('xlsx removes temporary report files when row generation fails', function () {
    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'fla-xlsx-*');
    $rows = (function (): Generator {
        yield ['value' => 'Private report data'];
        throw new RuntimeException('Query failed');
    })();

    ob_start();
    try {
        expect(fn () => (new XlsxReportWriter)->stream(['Header'], $rows))
            ->toThrow(RuntimeException::class, 'Query failed');
        expect(ob_get_contents())->toBe('');
    } finally {
        ob_end_clean();
    }

    expect(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'fla-xlsx-*'))->toBe($before);
});

test('xlsx rejects worksheets with no columns or too many columns', function (array $columns) {
    expect(fn () => (new XlsxReportWriter)->stream($columns, []))
        ->toThrow(RuntimeException::class, 'Jumlah kolom melebihi batas worksheet XLSX.');
})->with(['empty' => [[]], 'too many' => [array_fill(0, 16385, 'Header')]]);
