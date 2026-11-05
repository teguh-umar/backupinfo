<?php

namespace GlpiPlugin\Backupinfo\Sync;

/** Where SnapshotSync keeps the daily state of a connection (one list per kind and day). */
interface SnapshotStore
{
    /** Stores the list taken at $takenAt, replacing the one of the same day. */
    public function put(int $connectionId, string $kind, string $takenAt, array $rows): void;

    public function purgeOlderThan(int $months, int $nowS): int;
}
