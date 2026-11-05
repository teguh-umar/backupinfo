<?php

namespace GlpiPlugin\Backupinfo\Sync;

/** EventStore over glpi_plugin_backupinfo_events. Needs GLPI ($DB). */
final class DbEventStore implements EventStore
{
    public const TABLE = 'glpi_plugin_backupinfo_events';

    public function latest(int $connectionId, string $kind): ?string
    {
        global $DB;

        foreach ($DB->request([
            'SELECT' => ['event_time'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['connections_id' => $connectionId, 'kind' => $kind],
            'ORDER'  => ['event_time DESC'],
            'LIMIT'  => 1,
        ]) as $row) {
            return (string) $row['event_time'];
        }
        return null;
    }

    public function insert(int $connectionId, string $kind, string $time, string $fingerprint, array $data): bool
    {
        global $DB;

        if (count($DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => ['connections_id' => $connectionId, 'kind' => $kind, 'fingerprint' => $fingerprint]])) > 0) {
            return false;
        }
        return (bool) $DB->insert(self::TABLE, [
            'connections_id' => $connectionId,
            'kind'           => $kind,
            'event_time'     => $time,
            'fingerprint'    => $fingerprint,
            'data'           => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'date_creation'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function purgeOlderThan(int $months, int $nowS): int
    {
        global $DB;

        if ($months <= 0) {
            return 0;
        }
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-$months months", $nowS));
        $DB->delete(self::TABLE, ['event_time' => ['<', $cutoff]]);
        return (int) $DB->affectedRows();
    }

    /**
     * Stored events of one kind in [from, to], newest first, as the rows the source returned.
     *
     * @param list<int> $connectionIds
     * @return list<array<string, mixed>>
     */
    public function rows(string $kind, string $from, string $to, array $connectionIds): array
    {
        global $DB;

        if ($connectionIds === []) {
            return [];
        }
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['data'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'kind'           => $kind,
                'connections_id' => $connectionIds,
                ['event_time' => ['>=', $from]],
                ['event_time' => ['<=', $to]],
            ],
            'ORDER'  => ['event_time DESC'],
        ]) as $row) {
            $data = json_decode((string) $row['data'], true);
            if (is_array($data)) {
                $out[] = $data;
            }
        }
        return $out;
    }
}
