<?php

namespace GlpiPlugin\Backupinfo;

/**
 * CSV for auditors: UTF-8 with BOM (Excel reads the accents), a header block saying what the file
 * is, a blank line, then the table. A text cell that a spreadsheet would run as a formula gets a
 * leading quote (CSV injection); real numbers are left alone.
 */
final class Csv
{
    /**
     * @param array<string, scalar> $meta header block, label => value
     * @param array{columns: list<string>, rows: list<list<scalar|null>>} $section
     */
    public static function render(array $meta, array $section): string
    {
        $fh = fopen('php://temp', 'r+');
        foreach ($meta as $label => $value) {
            fputcsv($fh, [self::cell($label), self::cell($value)], ',', '"', '');
        }
        fwrite($fh, "\n");
        fputcsv($fh, array_map([self::class, 'cell'], $section['columns']), ',', '"', '');
        foreach ($section['rows'] as $row) {
            fputcsv($fh, array_map([self::class, 'cell'], $row), ',', '"', '');
        }
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $out;
    }

    public static function cell(mixed $value): string|int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $text = (string) $value;
        return ($text !== '' && str_contains("=+-@\t\r", $text[0])) ? "'" . $text : $text;
    }
}
