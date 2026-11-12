<?php

namespace GlpiPlugin\Backupinfo\Sync;

use GlpiPlugin\Backupinfo\Source\Registry;

/** ObjectStore over glpi_plugin_backupinfo_objects. Needs GLPI ($DB). */
final class DbObjectStore implements ObjectStore
{
    private const TABLE = 'glpi_plugin_backupinfo_objects';

    public function existing(int $connectionId): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['connections_id' => $connectionId]]) as $row) {
            $out[(string) $row['source_key']] = self::fromDb($row);
        }
        return $out;
    }

    public function upsert(int $connectionId, array $row): void
    {
        global $DB;

        $data = [
            'name'           => $row['name'],
            'ip'             => $row['ip'],
            'kind'           => $row['kind'],
            'job_name'       => $row['job_name'],
            'last_backup'    => $row['last_backup'] === null ? null : date('Y-m-d H:i:s', (int) $row['last_backup']),
            'restore_points' => (int) $row['restore_points'],
            'data_size'      => (string) $row['data_size'],
            'schedule_hours' => ($row['schedule_hours'] ?? null) === null ? null : (int) $row['schedule_hours'],
            'timepoint_uuid' => ($row['timepoint_uuid'] ?? null) === null ? null : (string) $row['timepoint_uuid'],
            'job_type'       => (string) ($row['job_type'] ?? ''),
            'storage'        => (string) ($row['storage'] ?? ''),
            'abnormal_chain' => (int) $row['abnormal_chain'],
            'source_deleted' => (int) $row['source_deleted'],
            'itemtype'       => $row['itemtype'],
            'items_id'       => (int) $row['items_id'],
            'match_state'    => $row['match_state'],
            'date_mod'       => date('Y-m-d H:i:s'),
        ];
        $where = ['connections_id' => $connectionId, 'source_key' => $row['source_key']];

        if (count($DB->request(['FROM' => self::TABLE, 'WHERE' => $where])) > 0) {
            $DB->update(self::TABLE, $data, $where);
        } else {
            $DB->insert(self::TABLE, $data + $where);
        }
    }

    public function delete(int $connectionId, array $sourceKeys): void
    {
        global $DB;

        if ($sourceKeys !== []) {
            $DB->delete(self::TABLE, ['connections_id' => $connectionId, 'source_key' => $sourceKeys]);
        }
    }

    public function purgeInactive(): array
    {
        global $DB;

        $active = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_backupinfo_connections', 'WHERE' => ['is_active' => 1]]) as $c) {
            $active[] = (int) $c['id'];
        }

        $where  = $active === [] ? [] : ['NOT' => ['connections_id' => $active]];
        $items  = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where]) as $row) {
            if ((int) $row['items_id'] > 0) {
                $items[(int) $row['items_id']] = true;
            }
        }
        $DB->delete(self::TABLE, $where === [] ? ['id' => ['>', 0]] : $where);

        return array_keys($items);
    }

    public function matched(): array
    {
        global $DB;

        $labels = Registry::labels();
        $types  = [];
        foreach ($DB->request(['SELECT' => ['id', 'type'], 'FROM' => 'glpi_plugin_backupinfo_connections', 'WHERE' => ['is_active' => 1]]) as $c) {
            $types[(int) $c['id']] = (string) $c['type'];
        }

        $out = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['itemtype' => 'Computer', 'items_id' => ['>', 0]],
        ]) as $row) {
            if (!isset($types[(int) $row['connections_id']])) {
                continue; // inactive or deleted connection: no longer counts as a backup
            }
            $r = self::fromDb($row);
            $r['source_label'] = $labels[$types[(int) $row['connections_id']] ?? ''] ?? '';
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Every object of the active connections (matched or not), only what the run lookups need.
     *
     * @return list<array{connections_id: int, name: string, job_name: string, items_id: int}>
     */
    public function forLookups(): array
    {
        global $DB;

        $active = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_backupinfo_connections', 'WHERE' => ['is_active' => 1]]) as $c) {
            $active[] = (int) $c['id'];
        }
        if ($active === []) {
            return [];
        }
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['connections_id', 'name', 'job_name', 'itemtype', 'items_id'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['connections_id' => $active],
        ]) as $row) {
            $out[] = [
                'connections_id' => (int) $row['connections_id'],
                'name'           => (string) $row['name'],
                'job_name'       => (string) $row['job_name'],
                'items_id'       => (string) $row['itemtype'] === 'Computer' ? (int) $row['items_id'] : 0,
            ];
        }
        return $out;
    }

    /**
     * Objects matched to one Computer, from active connections, each with source_label
     * and the connection's last_sync (for the asset tab).
     *
     * @return list<array<string, mixed>>
     */
    public function forItem(int $itemsId): array
    {
        global $DB;

        $labels = Registry::labels();
        $conns  = [];
        foreach ($DB->request(['SELECT' => ['id', 'type', 'name', 'last_sync'], 'FROM' => 'glpi_plugin_backupinfo_connections', 'WHERE' => ['is_active' => 1]]) as $c) {
            $conns[(int) $c['id']] = $c;
        }

        $out = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['itemtype' => 'Computer', 'items_id' => $itemsId],
            'ORDER' => ['last_backup DESC'],
        ]) as $row) {
            $conn = $conns[(int) $row['connections_id']] ?? null;
            if ($conn === null) {
                continue;
            }
            $r = self::fromDb($row);
            $r['source_label']    = $labels[(string) $conn['type']] ?? (string) $conn['type'];
            $r['connection_name'] = (string) $conn['name'];
            $r['last_sync']       = (string) ($conn['last_sync'] ?? '');
            $out[] = $r;
        }
        return $out;
    }

    /** @return array<string, mixed> last_backup as int epoch or null */
    private static function fromDb(array $row): array
    {
        $last = $row['last_backup'] ?? null;
        $row['last_backup']    = ($last === null || $last === '') ? null : (int) strtotime((string) $last);
        $row['items_id']       = (int) $row['items_id'];
        $row['connections_id'] = (int) $row['connections_id'];
        $sched                 = $row['schedule_hours'] ?? null;
        $row['schedule_hours'] = ($sched === null || $sched === '') ? null : (int) $sched;
        return $row;
    }
}
