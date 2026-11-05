<?php

namespace GlpiPlugin\Backupinfo\Sync;

/**
 * Copies the backup system's audit events (common Event rows, any brand) into GLPI so they outlive
 * its own retention: off-site copy runs, job logs, system logs and system alarms. Each kind is read from one day before its
 * newest stored event (the first time: FIRST_DAYS back, bounded by each read's row cap) and stored
 * once by fingerprint. A kind that fails is reported and the others go on. Pure (store and source
 * are passed in).
 */
final class EventSync
{
    public const FIRST_DAYS = 400;

    /** kind => [AuditSource method, time fields in order, identity fields (all present) or the whole event] */
    private const KINDS = [
        'copy'     => ['copyRuns', ['time', 'end_time'], ['job', 'time', 'vm']],
        'joblog'   => ['jobLogs', ['time'], ['ref']],
        'syslog'   => ['systemLogs', ['time'], ['ref']],
        'sysalarm' => ['systemAlarms', ['time'], ['ref']],
    ];

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }

    /**
     * @param object $source an AuditSource
     * @return array{new: array<string, int>, errors: list<string>}
     */
    public static function run(int $connectionId, object $source, EventStore $store, int $nowS): array
    {
        $out = ['new' => [], 'errors' => []];
        $to  = date('Y-m-d H:i:s', $nowS);
        foreach (self::KINDS as $kind => [$method, $timeKeys]) {
            $out['new'][$kind] = 0;
            $latest = $store->latest($connectionId, $kind);
            $fromS  = $latest === null ? $nowS - self::FIRST_DAYS * 86400 : (int) strtotime($latest) - 86400;
            $res    = $source->$method(date('Y-m-d H:i:s', $fromS), $to);
            if ($res['error'] !== '') {
                $out['errors'][] = $kind . ': ' . $res['error'];
                continue;
            }
            foreach ($res['rows'] as $row) {
                $time = self::time($row, $timeKeys);
                if ($time === null) {
                    continue;
                }
                if ($store->insert($connectionId, $kind, $time, self::fingerprint($kind, $row), $row)) {
                    $out['new'][$kind]++;
                }
            }
        }
        return $out;
    }

    /** sha1 of the event's identity fields when all are there, else of the whole row (keys sorted). */
    public static function fingerprint(string $kind, array $row): string
    {
        $ids  = self::KINDS[$kind][2] ?? [];
        $pick = [];
        foreach ($ids as $k) {
            if (!isset($row[$k]) || (string) $row[$k] === '') {
                $pick = null;
                break;
            }
            $pick[$k] = (string) $row[$k];
        }
        $basis = $pick ?? $row;
        ksort($basis);
        return sha1($kind . '|' . json_encode($basis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function time(array $row, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = trim((string) ($row[$k] ?? ''));
            if ($v !== '' && ($t = strtotime($v)) !== false) {
                return date('Y-m-d H:i:s', $t);
            }
        }
        return null;
    }
}
