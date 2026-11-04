<?php

namespace GlpiPlugin\Backupinfo\Sync;

use GlpiPlugin\Backupinfo\Source\Run;

/** Stored backup runs (audit evidence). Append-only: a stored run is never updated. */
interface RunStore
{
    /**
     * @param list<string> $uuids
     * @return array<string, true> the uuids already stored for this connection
     */
    public function known(int $connectionId, array $uuids): array;

    /**
     * Inserts the run and its items. A run already stored is left as it is (returns false).
     *
     * @param list<array{computers_id: int, vm_name: string, item_status: string}> $items
     */
    public function insert(int $connectionId, Run $run, string $reason, array $items): bool;

    /** Deletes runs (and their items) started more than $months months before $nowS; 0 = keep all. */
    public function purgeOlderThan(int $months, int $nowS): int;
}
