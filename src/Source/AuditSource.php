<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * Reads for the audit evidence (0.3.0), the same for every backup brand. Times are `Y-m-d H:i:s`;
 * $from and $to are inclusive. Each returns array{rows: list<array<string, mixed>>, error: string}
 * and never throws: a refused call is the `error` line (no secret). Texts are plain (HTML stripped).
 * The four event reads return Event::make() rows and are stored by the sync (EventSync); the
 * other two are read live.
 */
interface AuditSource
{
    /** Every VM on the virtualization platforms, with its backup state (protected or not). Live. */
    public function platformVms(): array;

    /** Where backups are written: Target::make() rows. Live. */
    public function targets(): array;

    /** Off-site copy / replica runs in the period, one Event "copy" per copied VM. */
    public function copyRuns(string $from, string $to): array;

    /** Job operation log (created, modified, scheduled ...): Event "joblog". */
    public function jobLogs(string $from, string $to): array;

    /** System log (logins with source IP, admin actions): Event "syslog". */
    public function systemLogs(string $from, string $to): array;

    /** System alarms (storage, node, license ...): Event "sysalarm". */
    public function systemAlarms(string $from, string $to): array;
}
