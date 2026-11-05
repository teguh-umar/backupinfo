<?php

namespace GlpiPlugin\Backupinfo\Tickets;

/**
 * Which Incidents to open (0.4.0): one per failed run x in-scope Computer, once ever, only for runs
 * that started after the feature was enabled. Rows are the resolved runs of the audit page
 * (RunResolve), so every brand is covered. Only Failed items count, never Warning. Pure.
 */
final class IncidentPlan
{
    /**
     * @param list<array<string, mixed>> $rows   resolved run x Computer rows
     * @param array<string, true>        $linked "runs_id|computers_id" already ticketed
     * @param array<int, string>         $scope  in-scope Computers, id => name
     * @return list<array{runs_id: int, computers_id: int, start_time: string, name: string, content: string}>
     */
    public static function plan(array $rows, array $linked, string $since, array $scope): array
    {
        $out = [];
        foreach ($rows as $r) {
            $c   = (int) ($r['computers_id'] ?? 0);
            $key = (int) $r['runs_id'] . '|' . $c;
            if ($c <= 0 || !isset($scope[$c]) || isset($linked[$key]) || isset($out[$key])
                || strtolower((string) ($r['item_status'] ?? '')) !== 'failed'
                || (string) $r['start_time'] < $since) {
                continue;
            }
            $out[$key] = [
                'runs_id'      => (int) $r['runs_id'],
                'computers_id' => $c,
                'start_time'   => (string) $r['start_time'],
                'name'         => sprintf('Backup failed: %s (%s, %s)', $scope[$c], $r['job_name'], $r['start_time']),
                'content'      => self::content($r, $scope[$c]),
            ];
        }
        return array_values($out);
    }

    private static function content(array $r, string $computer): string
    {
        $reason = trim(strip_tags((string) ($r['reason'] ?? '')));
        $lines  = [
            'Computer' => $computer,
            'VM'       => (string) ($r['vm_name'] ?? ''),
            'Job'      => (string) $r['job_name'],
            'Start'    => (string) $r['start_time'],
            'End'      => (string) ($r['finish_time'] ?? ''),
            'Reason'   => $reason !== '' ? $reason : 'not given by the backup system',
            'Connection' => (string) ($r['connection'] ?? ''),
            'Run reference' => (string) ($r['history_uuid'] ?? ''),
        ];
        $html = '';
        foreach ($lines as $k => $v) {
            $html .= '<p><b>' . $k . ':</b> ' . htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        return $html . '<p>Opened automatically by Backup Info from the stored backup run.</p>';
    }
}
