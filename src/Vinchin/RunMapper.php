<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

use GlpiPlugin\Backupinfo\Source\Run;

/**
 * /jobs/history row -> Run. The status comes from the text: on the VM `job_status_value` was 3005 for
 * "Failed", not the doc's 1-4 (2026-11-03). An unknown text is `abnormal`, never `success`.
 */
final class RunMapper
{
    private const ABORTED  = '/terminat|abort|cancel|stop|取消|终止/iu';
    private const ABNORMAL = '/abnormal|异常/iu';

    public static function map(mixed $row): ?Run
    {
        if (!is_array($row)) {
            return null;
        }
        $uuid = trim((string) ($row['history_uuid'] ?? ''));
        if ($uuid === '' || preg_match(JobHistoryMapper::SKIP, (string) ($row['job_type'] ?? ''))) {
            return null;
        }
        $text = trim((string) ($row['job_status'] ?? ''));

        return new Run(
            $uuid,
            trim((string) ($row['job_name'] ?? '')),
            trim((string) ($row['job_uuid'] ?? '')),
            self::time($row['start_time'] ?? null),
            self::time($row['finish_time'] ?? null),
            self::status($text),
            mb_substr($text, 0, 64),
            mb_substr(trim((string) ($row['all_size'] ?? $row['transfer_size'] ?? '')), 0, 64),
            self::duration((string) ($row['execution_duration'] ?? '')),
        );
    }

    public static function status(string $text): string
    {
        return match (true) {
            (bool) preg_match(self::ABORTED, $text)                  => Run::ABORTED,
            (bool) preg_match(JobHistoryMapper::FAILED, $text)       => Run::FAILED,
            (bool) preg_match(self::ABNORMAL, $text)                 => Run::ABNORMAL,
            (bool) preg_match(JobHistoryMapper::SUCCESS, $text)      => Run::SUCCESS,
            default                                                  => Run::ABNORMAL,
        };
    }

    /** Wall-clock string normalised to `Y-m-d H:i:s` in the same zone (no shift), or null. */
    public static function time(mixed $v): ?string
    {
        $ts = ItemMapper::parseTime($v);
        return $ts === null ? null : (new \DateTimeImmutable('@' . $ts))
            ->setTimezone(new \DateTimeZone(ItemMapper::SERVER_TZ))->format('Y-m-d H:i:s');
    }

    private static function duration(string $hms): ?int
    {
        if (!preg_match('/^\s*(\d+):(\d{2}):(\d{2})\s*$/', $hms, $m)) {
            return null;
        }
        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }
}
