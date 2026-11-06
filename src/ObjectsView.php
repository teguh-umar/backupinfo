<?php

namespace GlpiPlugin\Backupinfo;

/**
 * Rows of the Backup objects page (0.4.0-rc4): every object with its stored backup facts and the
 * result of its newest stored run, so an auditor sees the backup results that exist. No call to the
 * backup system: runs come from glpi_plugin_backupinfo_runs(_items), matched by connection and VM
 * name (case-insensitive). Pure.
 */
final class ObjectsView
{
    public const COLUMNS = ['Name', 'IP', 'Kind', 'Job', 'Last backup', 'Restore points', 'Size', 'Last run', 'Result',
        'Source', 'Match', 'Computer', 'Action'];

    /** Column indexes the page shows as HTML. */
    public const COL_RESULT   = 8;
    public const COL_COMPUTER = 11;
    public const COL_ACTION   = 12;

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
     * @param array<string, string> $types source type => label
     * @param array<string, string> $matchLabels match_state => label
     * @return list<list<string>>
     */
    public static function rows(array $objects, array $last, array $types, array $matchLabels): array
    {
        $out = [];
        foreach ($objects as $o) {
            $run   = $last[(int) $o['connections_id'] . '|' . mb_strtolower(trim((string) $o['name']))] ?? null;
            $match = (string) ($o['match_state'] ?? '');
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
                ($types[(string) ($o['connection_type'] ?? '')] ?? (string) ($o['connection_type'] ?? '')) . ' · ' . (string) ($o['connection_name'] ?? ''),
                $matchLabels[$match] ?? $match,
                (int) $o['items_id'] > 0 ? (string) ($o['computer_name'] ?? ('#' . (int) $o['items_id'])) : '',
                (string) (int) $o['id'],
            ];
        }
        return $out;
    }

    /** Badge colour of a run result: green success, red failed, yellow anything else. */
    public static function badge(string $result): string
    {
        return match ($result) {
            'success' => 'bg-success-lt',
            'failed'  => 'bg-danger-lt',
            ''        => '',
            default   => 'bg-warning-lt',
        };
    }
}
