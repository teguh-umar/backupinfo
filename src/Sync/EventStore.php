<?php

namespace GlpiPlugin\Backupinfo\Sync;

/**
 * Stored audit events (copy runs, job and system logs, system alarms). Append-only: an event is
 * stored once, as first seen, and never updated.
 */
interface EventStore
{
    /** Newest stored event time of this kind for the connection, or null when none. */
    public function latest(int $connectionId, string $kind): ?string;

    /** Stores the event unless its fingerprint is already there (then false). */
    public function insert(int $connectionId, string $kind, string $time, string $fingerprint, array $data): bool;

    /** Deletes events older than $months months before $nowS; 0 = keep all. */
    public function purgeOlderThan(int $months, int $nowS): int;
}
