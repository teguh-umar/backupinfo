<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * A source that can pull a read-only sample of the endpoints an audit asks for (failed runs,
 * alarms, logs, verification, copies), for the Probe page. Stage 1 of the audit evidence
 * work (user, 2026-11-03): shows what this API key may read before anything is built on it.
 */
interface AuditProbeableSource
{
    /**
     * One page per endpoint, at most $limit rows each, keyed by a label that names the
     * endpoint. An endpoint that fails or cannot be asked adds one line to errors (no secret)
     * and the others still run.
     *
     * @return array{raw: array<string, list<array>>, errors: list<string>}
     */
    public function probeAudit(int $limit, string $search = ''): array;
}
