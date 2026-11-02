<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

/**
 * Job schedule interval from GET /jobs/{ID} time_strategy (Vinchin v9 RESTful API):
 * type 1 daily, 2 weekly (frequency = every N weeks, days = weekdays), 3 monthly (days = dates).
 * The interval is the longest gap between two scheduled runs; with several strategies (full
 * weekly + incremental daily) the shortest wins, since any backup refreshes the last backup.
 */
final class ScheduleMapper
{
    /** A bad frequency must not turn into a months-long RPO. */
    public const MAX_WEEKS = 8;

    public static function intervalHours(array $detail): ?int
    {
        $strategies = $detail['time_strategy'] ?? null;
        if (!is_array($strategies)) {
            return null;
        }

        $best = null;
        foreach ($strategies as $s) {
            $hours = is_array($s) ? self::strategyHours($s) : null;
            if ($hours !== null && ($best === null || $hours < $best)) {
                $best = $hours;
            }
        }
        return $best;
    }

    /**
     * Shortest known schedule among an object's jobs ("A, B" as ItemMapper joins them).
     *
     * @param array<string, int> $byJob lowercased job name => interval hours
     */
    public static function forObject(string $jobNames, array $byJob): ?int
    {
        $whole = mb_strtolower(trim($jobNames));
        if ($whole === '') {
            return null;
        }
        $names = isset($byJob[$whole]) ? [$whole] : array_map('trim', explode(',', $whole));

        $best = null;
        foreach ($names as $name) {
            $hours = $byJob[$name] ?? null;
            if ($hours !== null && ($best === null || $hours < $best)) {
                $best = $hours;
            }
        }
        return $best;
    }

    /**
     * The VM's Vinchin (sample 2026-11-02) differs from the API doc: weekly days is a 7-flag
     * weekday mask ([0,0,0,0,1,0,0]) and frequency a string ("s2" = every 2 weeks). Both
     * shapes are read; a list of day numbers still works.
     */
    private static function strategyHours(array $s): ?int
    {
        $raw = $s['days'] ?? [];
        switch ((int) ($s['type'] ?? 0)) {
            case 1:
                return 24;
            case 2:
                $weeks = max(1, min(self::MAX_WEEKS, self::number($s['frequency'] ?? 1) ?? 1));
                $days  = self::mask($raw, 7) ?? array_map(static fn(int $d): int => $d % 7, self::days($raw));
                return self::longestGap(array_values(array_unique($days)), 7 * $weeks) * 24;
            case 3:
                $mask = self::mask($raw, 31);
                $days = $mask !== null ? array_map(static fn(int $i): int => $i + 1, $mask) : self::days($raw);
                return self::longestGap(array_values(array_filter($days, static fn(int $d): bool => $d >= 1 && $d <= 31)), 31) * 24;
            default:
                return null;
        }
    }

    /** First whole number in 2, "2" or "s2"; null when there is none. */
    private static function number(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        return is_string($v) && preg_match('/\d+/', $v, $m) === 1 ? (int) $m[0] : null;
    }

    /**
     * Indexes set in a flag mask of exactly $length 0/1 values, or null when $raw is not one.
     *
     * @return list<int>|null
     */
    private static function mask(mixed $raw, int $length): ?array
    {
        if (!is_array($raw) || count($raw) !== $length) {
            return null;
        }
        $set = [];
        foreach (array_values($raw) as $i => $flag) {
            $flag = is_bool($flag) ? (int) $flag : (is_scalar($flag) ? trim((string) $flag) : '');
            if ($flag !== 0 && $flag !== 1 && $flag !== '0' && $flag !== '1') {
                return null;
            }
            if ((int) $flag === 1) {
                $set[] = $i;
            }
        }
        return $set;
    }

    /** Longest gap in days between sorted run days in a cycle of $cycle days. */
    private static function longestGap(array $days, int $cycle): int
    {
        if ($days === []) {
            return $cycle;
        }
        sort($days);
        $gap = $cycle - end($days) + $days[0];
        for ($i = 1, $n = count($days); $i < $n; $i++) {
            $gap = max($gap, $days[$i] - $days[$i - 1]);
        }
        return $gap;
    }

    /** @return list<int> */
    private static function days(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $d) {
            if (is_int($d) || (is_string($d) && ctype_digit(trim($d)))) {
                $out[] = (int) $d;
            }
        }
        return $out;
    }
}
