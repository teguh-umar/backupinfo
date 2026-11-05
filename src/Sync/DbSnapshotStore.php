<?php

namespace GlpiPlugin\Backupinfo\Sync;

/** SnapshotStore over glpi_plugin_backupinfo_snapshots. Needs GLPI ($DB). */
final class DbSnapshotStore implements SnapshotStore
{
    public const TABLE = 'glpi_plugin_backupinfo_snapshots';

    public function put(int $connectionId, string $kind, string $takenAt, array $rows): void
    {
        global $DB;

        $day = substr($takenAt, 0, 10);
        $DB->delete(self::TABLE, ['connections_id' => $connectionId, 'kind' => $kind, 'snap_date' => $day]);
        $DB->insert(self::TABLE, [
            'connections_id' => $connectionId,
            'kind'           => $kind,
            'snap_date'      => $day,
            'taken_at'       => $takenAt,
            'data'           => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    public function purgeOlderThan(int $months, int $nowS): int
    {
        global $DB;

        if ($months <= 0) {
            return 0;
        }
        $DB->delete(self::TABLE, ['snap_date' => ['<', date('Y-m-d', (int) strtotime("-$months months", $nowS))]]);
        return (int) $DB->affectedRows();
    }

    /**
     * Per connection, the newest snapshot taken in [from, to] ($last) or the oldest ($last false).
     *
     * @param list<int> $connectionIds
     * @return array<int, array{taken_at: string, rows: list<array<string, mixed>>}>
     */
    public function pick(string $kind, array $connectionIds, string $from, string $to, bool $last = true): array
    {
        global $DB;

        $out = [];
        if ($connectionIds === []) {
            return $out;
        }
        foreach ($DB->request([
            'SELECT' => ['connections_id', 'taken_at', 'data'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'kind'           => $kind,
                'connections_id' => $connectionIds,
                ['taken_at' => ['>=', $from]],
                ['taken_at' => ['<=', $to]],
            ],
            'ORDER'  => [$last ? 'taken_at DESC' : 'taken_at ASC'],
        ]) as $row) {
            $c = (int) $row['connections_id'];
            if (isset($out[$c])) {
                continue;
            }
            $rows    = json_decode((string) $row['data'], true);
            $out[$c] = ['taken_at' => (string) $row['taken_at'], 'rows' => is_array($rows) ? $rows : []];
        }
        return $out;
    }
}
