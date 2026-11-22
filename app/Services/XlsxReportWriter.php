<?php

namespace App\Services;

use RuntimeException;
use XMLWriter;
use ZipArchive;

class XlsxReportWriter
{
    /**
     * @param  list<string>  $columns
     * @param  iterable<array<string, string|int|float|null>>  $rows
     */
    public function stream(array $columns, iterable $rows, array $summary = []): void
    {
        $temporaryFiles = [];
        $archive = new ZipArchive;
        $archiveOpen = false;

        try {
            $worksheetPath = $this->temporaryFile($temporaryFiles);
            $archivePath = $this->temporaryFile($temporaryFiles);
            $this->writeWorksheet($worksheetPath, $columns, $rows, $summary);

            if ($archive->open($archivePath, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('File XLSX tidak dapat dibuat.');
            }
            $archiveOpen = true;
            foreach ($this->packageParts() as $path => $xml) {
                if (! $archive->addFromString($path, $xml)) {
                    throw new RuntimeException('Struktur XLSX tidak dapat dibuat.');
                }
            }
            if (! $archive->addFile($worksheetPath, 'xl/worksheets/sheet1.xml')) {
                throw new RuntimeException('Worksheet XLSX tidak dapat ditambahkan.');
            }
            $closed = $archive->close();
            $archiveOpen = false;
            if (! $closed || readfile($archivePath) === false) {
                throw new RuntimeException('File XLSX tidak dapat dikirim.');
            }
        } finally {
            if ($archiveOpen) {
                $archive->close();
            }
            foreach ($temporaryFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @param list<string> $temporaryFiles */
    private function temporaryFile(array &$temporaryFiles): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fla-xlsx-');
        if ($path === false) {
            throw new RuntimeException('File sementara XLSX tidak dapat dibuat.');
        }
        $temporaryFiles[] = $path;

        return $path;
    }

    /**
     * @param  list<string>  $columns
     * @param  iterable<array<string, string|int|float|null>>  $rows
     */
    private function writeWorksheet(string $path, array $columns, iterable $rows, array $summary): void
    {
        if ($columns === [] || count($columns) > 16384) {
            throw new RuntimeException('Jumlah kolom melebihi batas worksheet XLSX.');
        }

        $xml = new XMLWriter;
        if (! $xml->openUri($path)) {
            throw new RuntimeException('Worksheet XLSX tidak dapat dibuka.');
        }
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('worksheet');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->startElement('sheetViews');
        $xml->startElement('sheetView');
        $xml->writeAttribute('workbookViewId', '0');
        $xml->startElement('pane');
        $xml->writeAttribute('ySplit', '1');
        $xml->writeAttribute('topLeftCell', 'A2');
        $xml->writeAttribute('activePane', 'bottomLeft');
        $xml->writeAttribute('state', 'frozen');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('cols');
        foreach ($columns as $index => $column) {
            $xml->startElement('col');
            $xml->writeAttribute('min', (string) ($index + 1));
            $xml->writeAttribute('max', (string) ($index + 1));
            $xml->writeAttribute('width', (string) max($index === 0 ? 28 : 18, min(38, mb_strlen($column) + 8)));
            $xml->writeAttribute('customWidth', '1');
            $xml->endElement();
        }
        $xml->endElement();
        $xml->startElement('sheetData');
        $rowNumber = 1;
        $this->writeRow($xml, $columns, $rowNumber, true);
        foreach ($rows as $row) {
            if (++$rowNumber > 1048576) {
                throw new RuntimeException('Jumlah baris melebihi batas worksheet XLSX.');
            }
            $this->writeRow($xml, array_values($row), $rowNumber);
            if ($rowNumber % 250 === 0) {
                $xml->flush();
            }
        }
        $lastDataRow = $rowNumber;
        if ($summary !== []) {
            $rowNumber++;
            foreach ($summary as $summaryRow) {
                if (++$rowNumber > 1048576) {
                    throw new RuntimeException('Jumlah baris melebihi batas worksheet XLSX.');
                }
                $this->writeRow($xml, array_values($summaryRow), $rowNumber, false, true);
            }
        }
        $xml->endElement();
        $xml->startElement('autoFilter');
        $xml->writeAttribute('ref', 'A1:'.$this->columnName(count($columns)).$lastDataRow);
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
    }

    /** @param list<string|int|float|null> $values */
    private function writeRow(XMLWriter $xml, array $values, int $rowNumber, bool $header = false, bool $summary = false): void
    {
        $xml->startElement('row');
        $xml->writeAttribute('r', (string) $rowNumber);
        if ($header) {
            $xml->writeAttribute('ht', '30');
            $xml->writeAttribute('customHeight', '1');
        }
        foreach ($values as $index => $value) {
            $xml->startElement('c');
            $xml->writeAttribute('r', $this->columnName($index + 1).$rowNumber);
            $style = $header ? 1 : ($summary ? (is_float($value) ? 4 : 3) : (is_float($value) ? 2 : 0));
            if ($style !== 0) {
                $xml->writeAttribute('s', (string) $style);
            }
            if (is_int($value) || is_float($value)) {
                $xml->writeElement('v', (string) $value);
            } else {
                $xml->writeAttribute('t', 'inlineStr');
                $xml->startElement('is');
                $xml->startElement('t');
                $xml->writeAttribute('xml:space', 'preserve');
                $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value) ?? '';
                $xml->text(mb_substr($text, 0, 32767));
                $xml->endElement();
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    /** @return array<string, string> */
    private function packageParts(): array
    {
        return [
            '[Content_Types].xml' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
                <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>
                XML,
            '_rels/.rels' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
                <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>
                XML,
            'xl/workbook.xml' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
                <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>
                XML,
            'xl/_rels/workbook.xml.rels' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
                <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>
                XML,
            'xl/styles.xml' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
                <styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FF1E3A5F"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="4" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>
                XML,
        ];
    }
}
