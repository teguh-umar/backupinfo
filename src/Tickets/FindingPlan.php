<?php

namespace GlpiPlugin\Backupinfo\Tickets;

/**
 * Risk Register findings (0.4.0), one per entity and code, mapped to ISO 27001:2022 A.8.13:
 * BCKP-COVERAGE (not protected), BCKP-RESTORE (no passed restore test in its interval), BCKP-REPEAT
 * (an open plugin Problem). A finding of an entity whose signal is gone gets "cleared". Pure.
 */
final class FindingPlan
{
    public const CODES = [
        'BCKP-COVERAGE' => ['Backup coverage gap', 'Computers that must be backed up have no recent successful backup.', 'High',
            'Fix the backup job or the object match of each listed Computer, then check the next run.'],
        'BCKP-RESTORE'  => ['Restore test overdue', 'Computers that must be backed up have no passed restore test within their interval.', 'Medium',
            'Run and record a restore test for each listed Computer.'],
        'BCKP-REPEAT'   => ['Repeated backup failures', 'Computers with a Problem opened by Backup Info for repeated backup failures.', 'High',
            'Work the linked Problem to its root cause.'],
    ];

    /**
     * @param array<string, list<int>> $signals  code => Computers raising it now
     * @param array<int, int>          $entityOf Computer => entity
     * @param array<string, true>      $existing "entity|code" of the plugin's findings already there
     * @return list<array{entities_id: int, code: string, computers: list<int>, cleared: bool}>
     */
    public static function plan(array $signals, array $entityOf, array $existing): array
    {
        $by = [];
        foreach ($signals as $code => $ids) {
            if (!isset(self::CODES[$code])) {
                continue;
            }
            foreach (array_unique($ids) as $c) {
                if (isset($entityOf[$c])) {
                    $by[$entityOf[$c] . '|' . $code][] = (int) $c;
                }
            }
        }
        $out = [];
        foreach ($by as $key => $ids) {
            [$e, $code] = explode('|', $key, 2);
            sort($ids);
            $out[] = ['entities_id' => (int) $e, 'code' => $code, 'computers' => $ids, 'cleared' => false];
        }
        foreach (array_keys($existing) as $key) {
            if (!isset($by[$key])) {
                [$e, $code] = explode('|', $key, 2);
                $out[] = ['entities_id' => (int) $e, 'code' => $code, 'computers' => [], 'cleared' => true];
            }
        }
        return $out;
    }

    /** Finding content: the signal and the Computers by name (they are also linked as items). */
    public static function content(string $code, array $names): string
    {
        $html = '<p>' . htmlspecialchars(self::CODES[$code][1], ENT_QUOTES, 'UTF-8') . '</p><ul>';
        foreach ($names as $n) {
            $html .= '<li>' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        return $html . '</ul><p>Kept up to date by Backup Info at each sync.</p>';
    }
}
