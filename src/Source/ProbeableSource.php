<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * A source that can pull a small read-only sample for the Probe page. Each source type
 * (Vinchin now; Sangfor SCP, Hyper-V later) implements it next to fetch().
 */
interface ProbeableSource
{
    /**
     * One page per endpoint, at most $limit rows each. An endpoint that fails adds one line
     * to errors (no secret) and the others still run.
     *
     * @return array{raw: array<string, list<array>>, objects: list<BackupObject>, errors: list<string>}
     */
    public function probe(int $limit): array;
}
