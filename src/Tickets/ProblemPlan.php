<?php

namespace GlpiPlugin\Backupinfo\Tickets;

/**
 * Repeated failures (0.4.0): a Computer with at least $count plugin Incidents in $days and no open
 * plugin Problem gets one, linked to those Incidents. While a Problem is open, new Incidents are
 * linked to it. Incidents already tied to a (solved) Problem never count for a new streak. Pure.
 */
final class ProblemPlan
{
    /**
     * @param array<int, list<array{tickets_id: int, start_time: string, problems_id: int}>> $incidents per Computer
     * @param array<int, int> $openProblems Computer => its open plugin Problem
     * @return array{open: array<int, list<int>>, link: list<array{0: int, 1: int}>}
     */
    public static function plan(array $incidents, array $openProblems, int $count, int $days, int $nowS): array
    {
        $out    = ['open' => [], 'link' => []];
        $cutoff = date('Y-m-d H:i:s', $nowS - $days * 86400);
        foreach ($incidents as $c => $list) {
            $free = array_values(array_filter($list, static fn(array $i): bool => (int) $i['problems_id'] === 0));
            if (isset($openProblems[$c])) {
                foreach ($free as $i) {
                    $out['link'][] = [(int) $openProblems[$c], (int) $i['tickets_id']];
                }
                continue;
            }
            $recent = array_values(array_filter($free, static fn(array $i): bool => (string) $i['start_time'] >= $cutoff));
            if (count($recent) >= max(1, $count)) {
                $out['open'][(int) $c] = array_map(static fn(array $i): int => (int) $i['tickets_id'], $recent);
            }
        }
        return $out;
    }
}
