<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Text;

/**
 * Failure reason for a run, taken from its job alarm. Job alarms carry an empty history_uuid (VM,
 * 2026-11-03), so they are joined by job name and time: alarm_time equalled finish_time to the second.
 * A run without a matching alarm gets no reason; it is never guessed.
 */
final class AlarmJoin
{
    public const MAX_REASON = 1000;

    /**
     * @param list<Run> $runs
     * @param list<array<string,mixed>> $alarms /alarm/job rows
     * @return array<string, string> historyUuid => reason
     */
    public static function reasons(array $runs, array $alarms, int $toleranceS = 60): array
    {
        $byJob = [];
        foreach ($alarms as $a) {
            $t = is_array($a) ? ItemMapper::parseTime($a['alarm_time'] ?? null) : null;
            if ($t !== null) {
                $byJob[self::key((string) ($a['job_name'] ?? ''))][] = [$t, (string) ($a['description'] ?? '')];
            }
        }

        $out = [];
        foreach ($runs as $run) {
            $t = ItemMapper::parseTime($run->finish ?? $run->start);
            if ($t === null) {
                continue;
            }
            $best = null;
            foreach ($byJob[self::key($run->jobName)] ?? [] as [$at, $text]) {
                $gap = abs($at - $t);
                if ($gap <= $toleranceS && ($best === null || $gap < $best[0])) {
                    $best = [$gap, $text];
                }
            }
            if ($best !== null) {
                $out[$run->historyUuid] = Text::plain($best[1], self::MAX_REASON);
            }
        }
        return $out;
    }

    private static function key(string $jobName): string
    {
        return mb_strtolower(trim($jobName));
    }
}
