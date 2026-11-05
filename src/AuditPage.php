<?php

namespace GlpiPlugin\Backupinfo;

/**
 * The seven sections of the audit evidence page, built from plain arrays (no GLPI): each is
 * array{columns: list<string>, rows: list<list<scalar|null>>, note: string}. Column names are English
 * on purpose: they are the CSV headers auditors receive. The page and the CSV show the same rows.
 */
final class AuditPage
{
    public const SECTIONS = ['policy', 'coverage', 'population', 'failures', 'restore', 'copies', 'activity', 'targets'];

    /** @return array<string, string> section => title */
    public static function titles(): array
    {
        return [
            'policy'     => __('1. Backup policy (test of design)', 'backupinfo'),
            'coverage'   => __('2. Coverage', 'backupinfo'),
            'population' => __('3. Backup runs (population)', 'backupinfo'),
            'failures'   => __('4. Failures', 'backupinfo'),
            'restore'    => __('5. Restore tests', 'backupinfo'),
            'copies'     => __('6. Off-site copies', 'backupinfo'),
            'activity'   => __('7. Changes, access and alarms', 'backupinfo'),
            'targets'    => __('8. Backup targets', 'backupinfo'),
        ];
    }

    /**
     * Why the page is empty, when it is: nothing is marked as must be backed up, or the marked
     * Computers are outside the user's active entities. Null when some are in scope.
     */
    public static function scopeNote(int $mandatoryTotal, int $visible): ?string
    {
        if ($visible > 0) {
            return null;
        }
        if ($mandatoryTotal === 0) {
            return __('No Computer is marked as must be backed up ("Wajib Backup" = Yes, or the manual list), or the Fields mapping is not set (Setup > Backup Info). Sections 1 to 6 stay empty.', 'backupinfo');
        }
        return sprintf(
            __('%d Computers are marked as must be backed up, but none is in your active entities. Select their entity (or a parent one with sub-entities) and reload.', 'backupinfo'),
            $mandatoryTotal
        );
    }

    /**
     * @param array<int, string> $computers in-scope Computers, id => name
     * @param array<int, list<array<string, mixed>>> $objectsByItem matched objects per Computer
     */
    public static function policy(array $computers, array $objectsByItem, Rpo $rpo): array
    {
        $rows = [];
        foreach ($computers as $id => $name) {
            $objects = $objectsByItem[$id] ?? [];
            if ($objects === []) {
                $rows[] = [$name, '', '', '', '', ''];
            }
            foreach ($objects as $o) {
                $rows[] = [
                    $name,
                    (string) ($o['name'] ?? ''),
                    (string) ($o['source_label'] ?? ''),
                    (string) ($o['job_name'] ?? ''),
                    $rpo->label($o),
                    $o['schedule_hours'] ?? '',
                ];
            }
        }
        return [
            'columns' => ['Computer', 'Backup object', 'Source', 'Job', 'RPO', 'Schedule (h)'],
            'rows'    => $rows,
            'note'    => __('As configured now in the backup system. A Computer without a backup object has an empty row.', 'backupinfo'),
        ];
    }

    /**
     * @param array<int, string> $computers
     * @param list<array<string, mixed>> $platformVms rows of AuditSource::platformVms()
     */
    public static function coverage(array $computers, array $objectsByItem, Rpo $rpo, array $platformVms, int $nowS): array
    {
        $platform = [];
        foreach ($platformVms as $vm) {
            $platform[mb_strtolower(trim((string) ($vm['vm_name'] ?? '')))] = (int) ($vm['backup_status'] ?? 0) !== 0 ? 'protected' : 'not protected';
        }

        $rows = [];
        foreach ($computers as $id => $name) {
            $objects = $objectsByItem[$id] ?? [];
            $last    = null;
            $report  = '';
            foreach ($objects as $o) {
                $t    = $o['last_backup'] ?? null;
                $last = ($t !== null && $t !== '' && ($last === null || (int) $t > $last)) ? (int) $t : $last;
                $report = $report !== '' ? $report : ($platform[mb_strtolower(trim((string) ($o['name'] ?? '')))] ?? '');
            }
            $report = $report !== '' ? $report : ($platform[mb_strtolower(trim($name))] ?? '');
            $rows[] = [
                $name,
                Status::of($objects, $rpo, $nowS),
                $last === null ? '' : date('Y-m-d H:i:s', $last),
                implode(', ', array_map(static fn(array $o): string => (string) ($o['name'] ?? ''), $objects)),
                $report,
            ];
        }
        return [
            'columns' => ['Computer', 'Status', 'Last backup', 'Backup objects', 'Platform report'],
            'rows'    => $rows,
            'note'    => __('Backup status as of now, not at the end of the period. "Platform report" is the backup system\'s own list of VMs on the hypervisors, from the newest daily snapshot in the period.', 'backupinfo'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $runs stored runs x Computers in the period (see AuditData::runs)
     */
    public static function population(array $computers, array $runs): array
    {
        $rows = [];
        foreach ($runs as $r) {
            $rows[] = [
                $computers[(int) $r['computers_id']] ?? ('#' . (int) $r['computers_id']),
                (string) $r['vm_name'],
                (string) $r['job_name'],
                (string) ($r['start_time'] ?? ''),
                (string) ($r['finish_time'] ?? ''),
                (string) $r['status'],
                (string) $r['item_status'],
                $r['duration_s'] ?? '',
                (string) ($r['size_text'] ?? ''),
                (string) $r['history_uuid'],
                (string) ($r['connection'] ?? ''),
            ];
        }
        return [
            'columns' => ['Computer', 'VM', 'Job', 'Start', 'Finish', 'Run status', 'Item status', 'Duration (s)', 'Size', 'Run ID', 'Connection'],
            'rows'    => $rows,
            'note'    => __('Every stored backup run of the in-scope Computers that started in the period, one line per run and Computer. Stored by the sync and never changed, so it remains after the backup system purges its history.', 'backupinfo'),
        ];
    }

    public static function failures(array $computers, array $runs): array
    {
        $rows = [];
        foreach ($runs as $r) {
            if ((string) $r['item_status'] !== 'failed') {
                continue;
            }
            $rows[] = [
                $computers[(int) $r['computers_id']] ?? ('#' . (int) $r['computers_id']),
                (string) $r['job_name'],
                (string) ($r['start_time'] ?? ''),
                (string) ($r['finish_time'] ?? ''),
                (string) $r['vm_name'],
                (string) ($r['reason'] ?? ''),
                (string) $r['history_uuid'],
                (string) ($r['connection'] ?? ''),
            ];
        }
        return [
            'columns' => ['Computer', 'Job', 'Start', 'Finish', 'VM', 'Reason', 'Run ID', 'Connection'],
            'rows'    => $rows,
            'note'    => __('Failed backups of in-scope Computers in the period. The reason comes from the backup system\'s job alarm; it is empty when no alarm matched.', 'backupinfo'),
        ];
    }

    /**
     * Restore-test status at the end of the period. Only a passed test restarts the clock.
     *
     * @param list<array<string, mixed>> $tests every test of these Computers up to $toDate
     * @param array<int, int> $overrides computers_id => months
     */
    public static function restore(array $computers, array $tests, array $overrides, int $globalMonths, string $fromDate, string $toDate): array
    {
        $byComputer = [];
        foreach ($tests as $t) {
            if ((string) $t['date'] <= $toDate) {
                $byComputer[(int) $t['computers_id']][] = $t;
            }
        }

        $end  = (int) strtotime($toDate . ' 12:00:00');
        $rows = [];
        foreach ($computers as $id => $name) {
            $months   = RestoreInterval::effective($globalMonths, $overrides[$id] ?? null);
            $lastPass = null;
            $last     = null;
            $inPeriod = 0;
            foreach ($byComputer[$id] ?? [] as $t) {
                $date = (string) $t['date'];
                if ($t['result'] === 'pass' && ($lastPass === null || $date > $lastPass)) {
                    $lastPass = $date;
                }
                if ($last === null || $date > (string) $last['date']) {
                    $last = $t;
                }
                if ($date >= $fromDate) {
                    $inPeriod++;
                }
            }
            $rows[] = [
                $name,
                $months,
                $lastPass ?? '',
                RestoreInterval::nextDue($lastPass, $months) ?? '',
                RestoreInterval::status($lastPass, $months, $end),
                $last === null ? '' : sprintf('%s (%s)', $last['date'], $last['result']),
                $inPeriod,
            ];
        }
        return [
            'columns' => ['Computer', 'Interval (months)', 'Last passed test', 'Next due', 'Status at period end', 'Last test', 'Tests in period'],
            'rows'    => $rows,
            'note'    => __('Manual records and imported backup-system verifications. Interval 0 = not required. A failed test is listed but does not count as tested.', 'backupinfo'),
        ];
    }

    /** @param list<array<string, mixed>> $copyRows rows of AuditSource::copyRuns() */
    public static function copies(array $computers, array $objectsByItem, array $copyRows): array
    {
        $byName = [];
        foreach ($computers as $id => $name) {
            $byName[mb_strtolower(trim($name))] = $name;
            foreach ($objectsByItem[$id] ?? [] as $o) {
                $byName[mb_strtolower(trim((string) ($o['name'] ?? '')))] = $name;
            }
        }
        $rows = [];
        foreach ($copyRows as $c) {
            $computer = $byName[mb_strtolower(trim((string) ($c['vm'] ?? '')))] ?? null;
            if ($computer === null) {
                continue;
            }
            $rows[] = [$computer, (string) $c['vm'], (string) ($c['job'] ?? ''), (string) ($c['time'] ?? ''), (string) ($c['end_time'] ?? ''),
                (string) ($c['status'] ?? ''), (string) ($c['item_status'] ?? ''), $c['restore_points'] ?? '', (string) ($c['size'] ?? ''), (string) ($c['error'] ?? '')];
        }
        return [
            'columns' => ['Computer', 'VM', 'Copy job', 'Start', 'End', 'Run status', 'Item status', 'Restore points', 'Size', 'Error'],
            'rows'    => $rows,
            'note'    => __('Backup copy and replication runs in the period for in-scope Computers (off-site copy), as stored by the sync.', 'backupinfo'),
        ];
    }

    /**
     * Job log, system log and system alarms (common events) merged, newest first. Logins from this
     * GLPI server ($ownIps: the plugin's own API sessions, several per sync) are left out unless
     * $showOwn; other actions from it stay.
     *
     * @param list<string> $ownIps
     */
    public static function activity(array $jobLogs, array $systemLogs, array $systemAlarms, array $ownIps = [], bool $showOwn = true): array
    {
        $own    = array_flip(array_filter(array_map('trim', $ownIps)));
        $hidden = 0;
        $rows   = [];
        foreach ($jobLogs as $e) {
            $rows[] = [(string) $e['time'], 'Job log', (string) $e['user'], (string) $e['level'], trim((string) $e['job'] . ': ' . (string) $e['message'], ': '), ''];
        }
        foreach ($systemLogs as $e) {
            if (!$showOwn && isset($own[(string) $e['source_ip']]) && stripos((string) $e['action'] . ' ' . (string) $e['message'], 'login') !== false) {
                $hidden++;
                continue;
            }
            $rows[] = [(string) $e['time'], 'System log', (string) $e['user'], (string) $e['action'], (string) $e['message'], (string) $e['source_ip']];
        }
        foreach ($systemAlarms as $e) {
            $rows[] = [(string) $e['time'], 'System alarm', '', trim((string) $e['level'] . ' / ' . (string) $e['status'], ' /'), (string) $e['message'], ''];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($b[0], $a[0]));
        $note = __('From the backup system, as stored by the sync: job changes, logins and admin actions, system alarms.', 'backupinfo');
        if ($hidden > 0) {
            $note .= ' ' . sprintf(__('%d logins from this GLPI server (the plugin\'s own API sessions) are hidden; tick "Show this server\'s logins" to list them.', 'backupinfo'), $hidden);
        }
        return [
            'columns' => ['Time', 'Kind', 'User', 'Level / type', 'Description', 'Source IP'],
            'rows'    => $rows,
            'note'    => $note,
        ];
    }

    /** @param list<array<string, string>> $targets Target::make() rows of every connection */
    /**
     * @param array<int, array{taken_at: string, rows: list<array<string, string>>}> $latest newest snapshot of the period per connection
     * @param array<int, array{taken_at: string, rows: list<array<string, string>>}> $first  oldest snapshot of the period per connection
     * @param array<int, string> $connections id => name
     */
    public static function targets(array $latest, array $first, array $connections): array
    {
        $rows = [];
        $asOf = [];
        foreach ($latest as $c => $snap) {
            $start = [];
            foreach ($first[$c]['rows'] ?? [] as $t) {
                $start[$t['kind'] . '|' . $t['name']] = $t['used'];
            }
            $asOf[] = ($connections[$c] ?? '#' . $c) . ' ' . $snap['taken_at'];
            foreach ($snap['rows'] as $t) {
                $rows[] = [$t['kind'], $t['name'], $t['type'], $t['node'], $t['total'], $start[$t['kind'] . '|' . $t['name']] ?? '',
                    $t['used'], $t['free'], $t['online'], $t['jobs'], $t['detail'], $snap['taken_at']];
            }
        }
        return [
            'columns' => ['Kind', 'Name', 'Type', 'Node', 'Total', 'Used at period start', 'Used', 'Free', 'Online', 'Jobs writing to it', 'Detail', 'As of'],
            'rows'    => $rows,
            'note'    => $latest === []
                ? __('No snapshot of the backup targets in this period. The sync stores one a day (from 0.3.0-rc7).', 'backupinfo')
                : sprintf(__('Where backups are written (disk, NAS, tape), as stored by the daily sync, latest in the period: %s.', 'backupinfo'), implode(', ', $asOf)),
        ];
    }
}
