<?php

namespace GlpiPlugin\Backupinfo\Tickets;

/**
 * Optional (off by default, 0.4.0-rc3): one follow-up "Backup succeeded again" on an open plugin
 * Incident, once, when a later run of the same Computer succeeded. It never solves or closes the
 * Incident; the technician decides. Pure.
 */
final class FollowupPlan
{
    /**
     * @param list<array{tickets_id: int, computers_id: int, start_time: string}> $open open Incidents without the follow-up yet
     * @param list<array<string, mixed>> $rows resolved run x Computer rows (RunResolve)
     * @return list<array{tickets_id: int, content: string}>
     */
    public static function plan(array $open, array $rows): array
    {
        $out   = [];
        foreach ($open as $i) {
            $best = null;
            foreach ($rows as $r) {
                if ((int) ($r['computers_id'] ?? 0) !== $i['computers_id'] || strtolower((string) ($r['item_status'] ?? '')) !== 'success'
                    || (string) $r['start_time'] <= $i['start_time']) {
                    continue;
                }
                if ($best === null || (string) $r['start_time'] < (string) $best['start_time']) {
                    $best = $r;
                }
            }
            if ($best !== null) {
                $out[] = [
                    'tickets_id' => $i['tickets_id'],
                    'content'    => '<p>' . htmlspecialchars(sprintf(
                        'Backup succeeded again at %s (job %s, VM %s). This Incident is not closed automatically.',
                        $best['finish_time'] ?: $best['start_time'],
                        $best['job_name'],
                        $best['vm_name'] ?? ''
                    ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
                ];
            }
        }
        return $out;
    }
}
