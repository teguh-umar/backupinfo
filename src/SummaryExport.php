<?php

namespace GlpiPlugin\Backupinfo;

/**
 * Excel export of the backup summary (spec 2026-11-11 §2.7): sheet "Summary" follows the cards
 * (source choice only), sheet "Objects" follows the table (source and filters). Pure.
 */
final class SummaryExport
{
    /**
     * @param array<string, mixed> $model Summary::build()
     * @param list<string> $columns ObjectsView::COLUMNS (Action is dropped)
     * @param list<list<string>> $rows ObjectsView::rows() of the filtered table
     * @param array<string, scalar|null> $meta header block from the page
     * @return list<array> two Xlsx::sheet() arrays
     */
    public static function sheets(array $model, array $columns, array $rows, array $meta): array
    {
        $pct = static fn(?float $v): string => $v === null ? '—' : sprintf('%.1f%%', $v);
        $n   = (int) $model['count'];

        $reasonLine = [];
        foreach (Summary::REASONS as $key => $label) {
            if (!empty($model['reasonCounts'][$key])) {
                $reasonLine[] = $label . ' ' . (int) $model['reasonCounts'][$key];
            }
        }
        $out = [
            ['KPI', 'Protected objects', (string) $n, sprintf('%d kinds · %d sources', (int) $model['kinds'], (int) $model['sources'])],
            ['KPI', 'Fresh backups', $pct($model['freshPct']), sprintf('%d objects ≤ 24 hours', (int) $model['fresh'])],
            ['KPI', 'Total data', ($model['sizeReported'] ?? true) ? Summary::human((int) $model['bytes']) : 'Not reported by this source', 'protected data size'],
            ['KPI', 'Restore points', (string) (int) $model['points'], $model['pointsAvg'] === null ? '—' : sprintf('average %.1f per object', $model['pointsAvg'])],
            ['KPI', 'Needs attention', (string) count($model['attention']), implode(' · ', $reasonLine)],
        ];
        foreach (Summary::AGE_BUCKETS as $key => $label) {
            $count = (int) $model['age'][$key];
            $out[] = ['Last backup age', $label, (string) $count, $n === 0 ? '0%' : sprintf('%d%%', (int) round($count * 100 / $n))];
        }
        foreach ($model['byKind'] as $k) {
            $out[] = ['By kind', (string) $k['label'], (string) (int) $k['count'], Summary::human((int) $k['bytes']) . ' · ' . sprintf('%d%% ≤ 24 hours', (int) round($k['freshPct']))];
        }
        foreach ($model['attention'] as $a) {
            $age   = $a['ageDays'] === null ? 'No backup' : sprintf('Last backup %d days ago', (int) $a['ageDays']);
            $out[] = ['Needs attention', (string) $a['name'], implode(', ', array_map(static fn(string $r): string => Summary::REASONS[$r], $a['reasons'])),
                $age . ($a['job'] !== '' ? ' · ' . $a['job'] : '')];
        }
        foreach ($model['largest'] as $l) {
            $out[] = ['Largest objects', (string) $l['name'], Summary::human((int) $l['bytes']), ''];
        }
        foreach ($model['notes'] as $note) {
            $out[] = ['Note', '', (string) $note, ''];
        }

        $drop  = array_search('Action', $columns, true);
        $cols  = $columns;
        $table = $rows;
        if ($drop !== false) {
            unset($cols[$drop]);
            $cols  = array_values($cols);
            $table = array_map(static function (array $r) use ($drop): array {
                unset($r[$drop]);
                return array_values($r);
            }, $rows);
        }

        return [
            Xlsx::sheet('Summary', $meta + ['Counts' => 'Cards: source choice only'], ['Section', 'Item', 'Value', 'Detail'], $out),
            Xlsx::sheet('Objects', $meta + ['Rows' => 'Table: source and filters'], $cols, $table),
        ];
    }
}
