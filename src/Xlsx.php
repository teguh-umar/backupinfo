<?php

namespace GlpiPlugin\Backupinfo;

/**
 * Minimal Excel workbook (.xlsx) for auditors: one sheet per table, a bold header block saying what
 * the sheet is, then the table with a frozen header and a filter. Text is always written as inline
 * text, never as a formula, so a cell like "=1+1" cannot run (no CSV-style quote needed). Built as XML
 * strings (low memory, unlike a full spreadsheet object); zipped with the ZipStream library GLPI 11
 * ships, or ext-zip. Without either, the caller falls back to CSV.
 */
final class Xlsx
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const MAX_CELL = 32767;
    private const WIDTH_SAMPLE = 500;

    /**
     * @param array<string, scalar|null> $meta label => value, written above the table
     * @param list<string> $columns
     * @param list<list<scalar|null>> $rows
     * @return array{name: string, meta: array<string, scalar|null>, columns: list<string>, rows: list<list<scalar|null>>}
     */
    public static function sheet(string $name, array $meta, array $columns, array $rows): array
    {
        return ['name' => $name, 'meta' => $meta, 'columns' => $columns, 'rows' => $rows];
    }

    /**
     * Every file of the workbook, path => XML. Pure.
     *
     * @param list<array{name: string, meta: array, columns: list<string>, rows: list<list<scalar|null>>}> $sheets
     * @return array<string, string>
     */
    public static function parts(array $sheets): array
    {
        $names  = [];
        $parts  = [];
        $list   = '';
        $rels   = '';
        $types  = '';
        $filter = '';
        foreach (array_values($sheets) as $i => $sheet) {
            $n       = $i + 1;
            $name    = self::sheetName($sheet['name'], $names);
            $names[] = $name;
            [$xml, $ref] = self::worksheet($sheet);
            $parts["xl/worksheets/sheet$n.xml"] = $xml;
            $list   .= '<sheet name="' . self::esc($name, true) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels   .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $types  .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $filter .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">'
                . self::esc("'" . str_replace("'", "''", $name) . "'!" . self::absolute($ref)) . '</definedName>';
        }
        $n = count($names);

        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $parts['[Content_Types].xml'] = $head
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $types . '</Types>';
        $parts['_rels/.rels'] = $head
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $parts['xl/workbook.xml'] = $head
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $list . '</sheets><definedNames>' . $filter . '</definedNames></workbook>';
        $parts['xl/_rels/workbook.xml.rels'] = $head
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels
            . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
        // Style 0 = normal, 1 = bold.
        $parts['xl/styles.xml'] = $head
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
        return $parts;
    }

    /** True when a zip writer is there (ZipStream from GLPI's vendor, or ext-zip). */
    public static function available(): bool
    {
        return class_exists(\ZipStream\ZipStream::class) || class_exists(\ZipArchive::class);
    }

    /**
     * Zips the parts into $out (a writable stream, e.g. php://output).
     *
     * @param resource $out
     * @param array<string, string> $parts
     */
    public static function write($out, array $parts): bool
    {
        // [Content_Types].xml first, as Office writes it.
        uksort($parts, static fn(string $a, string $b): int => ($b === '[Content_Types].xml') <=> ($a === '[Content_Types].xml'));
        if (class_exists(\ZipStream\ZipStream::class)) {
            // Sizes in the local headers and no Zip64: what Excel opens without a repair prompt.
            $zip = new \ZipStream\ZipStream(outputStream: $out, enableZip64: false, defaultEnableZeroHeader: false, sendHttpHeaders: false);
            foreach ($parts as $path => $xml) {
                $zip->addFile($path, $xml);
            }
            $zip->finish();
            return true;
        }
        if (class_exists(\ZipArchive::class)) {
            $tmp = tempnam(sys_get_temp_dir(), 'bix');
            $zip = new \ZipArchive();
            if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
                return false;
            }
            foreach ($parts as $path => $xml) {
                $zip->addFromString($path, $xml);
            }
            $zip->close();
            $in = fopen($tmp, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            @unlink($tmp);
            return true;
        }
        return false;
    }

    /** 0 => A, 25 => Z, 26 => AA. */
    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    /** Excel sheet name: no []:*?/\, 31 characters at most, unique among $taken. */
    public static function sheetName(string $name, array $taken): string
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '_', $name));
        $name = $name === '' ? 'Sheet' : mb_substr($name, 0, 31);
        $base = $name;
        for ($k = 2; in_array($name, $taken, true); $k++) {
            $suffix = " ($k)";
            $name   = mb_substr($base, 0, 31 - mb_strlen($suffix)) . $suffix;
        }
        return $name;
    }

    /** @return array{0: string, 1: string} worksheet XML and the table range (for the filter) */
    private static function worksheet(array $sheet): array
    {
        $r    = 0;
        $data = '';
        foreach ($sheet['meta'] as $label => $value) {
            $r++;
            $data .= '<row r="' . $r . '">' . self::cell('A' . $r, (string) $label, 1) . self::cell('B' . $r, $value) . '</row>';
        }
        if ($r > 0) {
            $r++; // blank row between the block and the table
        }
        $headerRow = ++$r;
        $data .= '<row r="' . $r . '">';
        foreach ($sheet['columns'] as $c => $title) {
            $data .= self::cell(self::col($c) . $r, (string) $title, 1);
        }
        $data .= '</row>';
        foreach ($sheet['rows'] as $row) {
            $r++;
            $data .= '<row r="' . $r . '">';
            foreach (array_values($row) as $c => $value) {
                $data .= self::cell(self::col($c) . $r, $value);
            }
            $data .= '</row>';
        }

        $last  = self::col(max(0, count($sheet['columns']) - 1));
        $ref   = 'A' . $headerRow . ':' . $last . $r;
        $cols  = '';
        foreach (self::widths($sheet) as $c => $w) {
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $data . '</sheetData>'
            . '<autoFilter ref="' . $ref . '"/>'
            . '</worksheet>';
        return [$xml, $ref];
    }

    /** Column widths from the header and the first rows, 8 to 60 characters. */
    private static function widths(array $sheet): array
    {
        $w = [];
        foreach ($sheet['columns'] as $c => $title) {
            $w[$c] = mb_strlen((string) $title);
        }
        foreach (array_slice($sheet['rows'], 0, self::WIDTH_SAMPLE) as $row) {
            foreach (array_values($row) as $c => $value) {
                $w[$c] = max($w[$c] ?? 0, mb_strlen((string) $value));
            }
        }
        if ($sheet['meta'] !== []) {
            $w[0] = max($w[0] ?? 0, ...array_map(static fn($l): int => mb_strlen((string) $l), array_keys($sheet['meta'])));
        }
        return array_map(static fn(int $n): int => min(60, max(8, $n + 2)), $w);
    }

    private static function cell(string $ref, mixed $value, int $style = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $s = $style > 0 ? ' s="' . $style . '"' : '';
        if (is_bool($value)) {
            $value = (int) $value;
        }
        if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
            return '<c r="' . $ref . '"' . $s . '><v>' . $value . '</v></c>';
        }
        $text = mb_substr(self::clean((string) $value), 0, self::MAX_CELL);
        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc($text) . '</t></is></c>';
    }

    /** Drops what XML 1.0 cannot carry (control characters, broken UTF-8). */
    private static function clean(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    private static function esc(string $text, bool $attribute = false): string
    {
        return htmlspecialchars($text, ENT_XML1 | ($attribute ? ENT_QUOTES : ENT_NOQUOTES), 'UTF-8');
    }

    /** A4:C6 => $A$4:$C$6 */
    private static function absolute(string $ref): string
    {
        return (string) preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $ref);
    }
}
