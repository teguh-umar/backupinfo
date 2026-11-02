<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * A source whose last fetch() may have returned only part of what it protects (e.g. Vinchin's
 * job-history fallback: no non-VM objects, only recent jobs). The sync then upserts what it got
 * and deletes nothing.
 */
interface PartialFetch
{
    public function lastFetchWasPartial(): bool;
}
