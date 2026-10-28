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
            'data_size'      => (int) $row['data_size'],
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

        $labels = Registry::types();
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

    /** @return array<string, mixed> last_backup as int epoch or null */
    private static function fromDb(array $row): array
    {
        $last = $row['last_backup'] ?? null;
        $row['last_backup']    = ($last === null || $last === '') ? null : (int) strtotime((string) $last);
        $row['items_id']       = (int) $row['items_id'];
        $row['connections_id'] = (int) $row['connections_id'];
        return $row;
    }
}
