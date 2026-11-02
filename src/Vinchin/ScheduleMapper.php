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

    private static function strategyHours(array $s): ?int
    {
        $days = self::days($s['days'] ?? []);
        switch ((int) ($s['type'] ?? 0)) {
            case 1:
                return 24;
            case 2:
                $weeks = max(1, min(self::MAX_WEEKS, (int) ($s['frequency'] ?? 1)));
                $days  = array_values(array_unique(array_map(static fn(int $d): int => $d % 7, $days)));
                return self::longestGap($days, 7 * $weeks) * 24;
            case 3:
                return self::longestGap(array_values(array_filter($days, static fn(int $d): bool => $d >= 1 && $d <= 31)), 31) * 24;
            default:
                return null;
        }
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
