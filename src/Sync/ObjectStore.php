<?php

namespace GlpiPlugin\Backupinfo\Sync;

/**
 * Storage of backup objects (glpi_plugin_backupinfo_objects). Rows use the table's
 * column names; last_backup is an int epoch in seconds or null.
 */
interface ObjectStore
{
    /** @return array<string, array<string, mixed>> rows of one connection keyed by source_key */
    public function existing(int $connectionId): array;

    /** Insert or update by (connections_id, source_key). */
    public function upsert(int $connectionId, array $row): void;

    /** @param list<string> $sourceKeys */
    public function delete(int $connectionId, array $sourceKeys): void;

    /**
     * Every row currently matched to a Computer (itemtype Computer, items_id > 0), all
     * connections. Rows may carry 'source_label' (e.g. "Vinchin") for the media text.
     *
     * @return list<array<string, mixed>>
     */
    public function matched(): array;
}
