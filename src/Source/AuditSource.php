<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * Live reads for the audit evidence page (0.3.0). Times are `Y-m-d H:i:s`; $from and $to are
 * inclusive. Each returns array{rows: list<array<string, mixed>>, error: string} and never throws: a
 * refused call is the `error` line (no secret). Descriptions come back as plain text (HTML stripped).
 */
interface AuditSource
{
    /** Every VM on the virtualization platforms, with its backup state (protected or not). */
    public function platformVms(): array;

    /** Off-site copy / replica runs in the period, one row per copied VM. */
    public function copyRuns(string $from, string $to): array;

    /** Job operation log (created, modified, scheduled ...). */
    public function jobLogs(string $from, string $to): array;

    /** System log (logins with source IP, admin actions). */
    public function systemLogs(string $from, string $to): array;

    /** System alarms (storage, node, license ...). */
    public function systemAlarms(string $from, string $to): array;
}
