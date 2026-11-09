<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * Neutral time parsing for Veeam (ISO 8601 with Z or an offset, or a plain local string).
 * Every time is written as `Y-m-d H:i:s` in PHP's default timezone (GLPI's).
 */
final class Time
{
    /** @return string|null `Y-m-d H:i:s` in date_default_timezone_get(), or null */
    public static function local(mixed $v): ?string
    {
        $ts = self::epoch($v);
        return $ts === null ? null : date('Y-m-d H:i:s', $ts);
    }

    /** @return int|null epoch seconds, or null */
    public static function epoch(mixed $v): ?int
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $v)) {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($v, new \DateTimeZone(date_default_timezone_get()));
            return (int) $dt->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
