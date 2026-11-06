<?php

namespace GlpiPlugin\Backupinfo;

/**
 * Rows of the Backup objects page (0.4.0-rc5): every object with its stored backup facts, the
 * result of its newest stored run and its Computer's newest restore test, so an auditor sees the
 * backup results that exist. No call to the backup system: runs come from
 * glpi_plugin_backupinfo_runs(_items), matched by connection and VM name (case-insensitive). Pure.
 */
final class ObjectsView
{
    public const COLUMNS = ['Name', 'IP', 'Kind', 'Job', 'Last backup', 'Restore points', 'Size', 'Last run', 'Result',
        'Restore test', 'Source', 'Match', 'Computer', 'Action'];

    /** Column indexes the page shows as HTML. */
    public const COL_NAME     = 0;
    public const COL_RESULT   = 8;
    public const COL_RESTORE  = 9;
    public const COL_COMPUTER = 12;
    public const COL_ACTION   = 13;

    /** Restore tests search option "Computer" (RestoreTest::rawSearchOptions). */
    private const SO_COMPUTER = 3;

    /**
     * Newest stored run per connection and VM name.
     *
     * @param iterable<array<string, mixed>> $runRows connections_id, vm_name, start_time, finish_time, status, item_status, reason
     * @return array<string, array<string, mixed>> "connection|vm (lower case)" => row
     */
    public static function lastRuns(iterable $runRows): array
    {
        $out = [];
        foreach ($runRows as $r) {
            $vm = mb_strtolower(trim((string) ($r['vm_name'] ?? '')));
            if ($vm === '') {
                continue;
            }
            $key = (int) ($r['connections_id'] ?? 0) . '|' . $vm;
            if (!isset($out[$key]) || (string) ($r['start_time'] ?? '') > (string) ($out[$key]['start_time'] ?? '')) {
                $out[$key] = $r;
            }
        }
        return $out;
    }

    /** The VM's own result when the run has one, else the run's. */
    public static function result(array $run): string
    {
        $item = strtolower(trim((string) ($run['item_status'] ?? '')));
        return $item !== '' ? $item : strtolower(trim((string) ($run['status'] ?? '')));
    }

    /**
     * Plain rows for AuditTable (filters and sort read these). The last cell is the object id; the
     * page turns it into the pin/unpin form.
     *
     * @param list<array<string, mixed>> $objects joined rows (computer_name, connection_name, connection_type)
     * @param array<string, array<string, mixed>> $last lastRuns()
     * @param array<int, array{date: string, result: string}> $tests newest restore test per Computer
     * @param array<string, string> $types source type => label
     * @param array<string, string> $matchLabels match_state => label
     * @return list<list<string>>
     */
    public static function rows(array $objects, array $last, array $tests, array $types, array $matchLabels): array
    {
        $out = [];
        foreach ($objects as $o) {
            $run   = $last[(int) $o['connections_id'] . '|' . mb_strtolower(trim((string) $o['name']))] ?? null;
            $match = (string) ($o['match_state'] ?? '');
            $cid   = (int) $o['items_id'];
            $test  = $cid > 0 ? ($tests[$cid] ?? null) : null;
            $out[] = [
                (string) $o['name'],
                (string) ($o['ip'] ?? ''),
                (string) ($o['kind'] ?? ''),
                (string) ($o['job_name'] ?? ''),
                (string) ($o['last_backup'] ?? ''),
                (string) (int) ($o['restore_points'] ?? 0),
                (string) ($o['data_size'] ?? ''),
                $run === null ? '' : (string) ($run['start_time'] ?? ''),
                $run === null ? '' : self::result($run),
                $test === null ? '' : trim($test['date'] . ' ' . $test['result']),
                ($types[(string) ($o['connection_type'] ?? '')] ?? (string) ($o['connection_type'] ?? '')) . ' · ' . (string) ($o['connection_name'] ?? ''),
                $matchLabels[$match] ?? $match,
                $cid > 0 ? (string) ($o['computer_name'] ?? ('#' . $cid)) : '',
                (string) (int) $o['id'],
            ];
        }
        return $out;
    }

    /** Badge colour of a run or test result: green success/pass, red failed/fail, yellow anything else. */
    public static function badge(string $result): string
    {
        return match ($result) {
            'success', 'pass' => 'bg-success-lt',
            'failed', 'fail'  => 'bg-danger-lt',
            ''                => '',
            default           => 'bg-warning-lt',
        };
    }

    /** Query string of the Restore tests list narrowed to one Computer. */
    public static function restoreListQuery(int $computerId): string
    {
        return http_build_query([
            'criteria' => [['field' => self::SO_COMPUTER, 'searchtype' => 'equals', 'value' => $computerId]],
            'reset'    => 'reset',
        ]);
    }
}
