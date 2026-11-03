<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

/**
 * Job schedule interval from GET /jobs/{ID} time_strategy. Format per the Vinchin v9 RESTful
 * API doc (create/modify job and archive sections; the GET /jobs/{ID} section is out of date)
 * and the VM sample of 2026-11-02:
 * - type: 1 daily, 2 weekly, 3 monthly.
 * - days: daily []; weekly a 7-flag mask, index 0 = Monday ([1,0,0,0,1,0,0] = Mon + Fri);
 *   monthly a 31-flag mask, index 0 = day 1. Flags may be 0/1 or booleans.
 * - frequency: weekly only, "s<N>" = every N weeks, s1-s20; blank = every week.
 * - time_strategy_backup_type: "strategy" (scheduled), "oncetime", "manual" (no schedule).
 *
 * The interval is the longest gap between two scheduled runs; with several strategies (full
 * weekly + incremental daily) the shortest wins, since any backup refreshes the last backup.
 * A strategy it does not understand makes the whole schedule unknown (null): it could be the
 * shorter one, and a guess would silently give wrong verdicts.
 */
final class ScheduleMapper
{
    /** Highest frequency the API doc allows ("s1-s20"); anything above is unknown. */
    public const MAX_WEEKS = 20;

    public static function intervalHours(array $detail): ?int
    {
        $kind = $detail['time_strategy_backup_type'] ?? 'strategy';
        if ($kind !== 'strategy') {
            return null; // one-time or manual job: no schedule to measure against
        }
        $strategies = $detail['time_strategy'] ?? null;
        if (!is_array($strategies)) {
            return null;
        }

        $best = null;
        foreach ($strategies as $s) {
            $hours = is_array($s) ? self::strategyHours($s) : null;
            if ($hours === null) {
                return null;
            }
            $best = $best === null ? $hours : min($best, $hours);
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

    /** Masks as documented; a list of day numbers (older doc wording) still works. */
    private static function strategyHours(array $s): ?int
    {
        $raw = $s['days'] ?? [];
        switch ((int) ($s['type'] ?? 0)) {
            case 1:
                return 24;
            case 2:
                $weeks = self::weeks($s['frequency'] ?? '');
                if ($weeks === null) {
                    return null;
                }
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

    /**
     * Weeks between runs from the documented shapes only: "s<N>" (also N or "N"), and blank
     * or null = every week. Anything else ("d3", "m1", "s21", 0) is null, never guessed or
     * capped (orchestrator 2026-11-02).
     */
    private static function weeks(mixed $v): ?int
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return 1;
        }
        if (is_string($v) && preg_match('/^\s*s?(\d+)\s*$/i', $v, $m) === 1) {
            $v = (int) $m[1];
        }
        return is_int($v) && $v >= 1 && $v <= self::MAX_WEEKS ? $v : null;
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
