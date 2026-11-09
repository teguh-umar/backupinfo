<?php

namespace GlpiPlugin\Backupinfo\Sync;

use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Source\RunHistorySource;

/**
 * Stores the runs a source has that are not stored yet (the ICOFR population). Pure of GLPI classes;
 * Cron supplies the source, the store and the object lookups.
 *
 * Failed runs get their per-VM result (one call each) and the reason from the matching job alarm.
 * Other runs take the Computers of their job from the stored objects, with no call per run.
 */
final class RunSync
{
    /** Per-VM detail calls per sync; a first backfill can hold hundreds of failed runs. */
    public const MAX_ITEM_CALLS = 100;

    /**
     * @param callable(string): int $vmToComputer object name -> Computer id (0 = not matched)
     * @param callable(string): list<array{computers_id: int, vm_name: string}> $jobComputers job name -> its matched objects
     * @return array{new: int, failed: int, errors: list<string>}
     */
    public function run(int $connectionId, RunHistorySource $source, RunStore $store, callable $vmToComputer, callable $jobComputers, int $maxPages): array
    {
        $out = ['new' => 0, 'failed' => 0, 'errors' => []];

        // A page with no run is never "known", or an empty answer would look like "up to date".
        $fetched = $source->runs(
            static fn(array $uuids): bool => $uuids !== [] && count($store->known($connectionId, $uuids)) >= count(array_unique($uuids)),
            $maxPages
        );
        array_push($out['errors'], ...$fetched['errors']);

        $known = $store->known($connectionId, array_map(static fn(Run $r): string => $r->historyUuid, $fetched['runs']));
        $new   = array_values(array_filter($fetched['runs'], static fn(Run $r): bool => !isset($known[$r->historyUuid])));
        if ($new === []) {
            return $out;
        }

        $failed  = array_values(array_filter($new, static fn(Run $r): bool => $r->status === Run::FAILED));
        $reasons = [];
        if ($failed !== []) {
            $res = $source->failureReasons($failed);
            if ($res['error'] !== '') {
                $out['errors'][] = $res['error'];
            }
            $reasons = $res['reasons'];
        }

        // Oldest first: if the run stops half-way, the newest runs stay unknown and the next sync
        // fetches this page again instead of stopping on it.
        // Detail calls go to the newest failed runs; older ones beyond the cap use their job's Computers.
        $detailed = array_flip(array_slice(array_map(static fn(Run $r): string => $r->historyUuid, $failed), 0, self::MAX_ITEM_CALLS));
        $detailRefused = false;
        foreach (array_reverse($new) as $run) {
            $items = [];
            if (isset($detailed[$run->historyUuid]) && !$detailRefused) {
                $res = $source->runItems($run->historyUuid);
                if ($res['error'] !== '') {
                    // Refused once, refused for all (code -1 on the VM, 2026-11-05): stop asking this sync.
                    $out['errors'][] = $res['error'];
                    $detailRefused   = true;
                }
                foreach ($res['items'] as $i) {
                    $items[] = ['computers_id' => $vmToComputer($i['vm_name']), 'vm_name' => $i['vm_name'], 'item_status' => $i['status']];
                }
            }
            // No per-VM result (refused, unknown shape, or a successful run): the job's Computers,
            // with the run's own status. Without this a refused endpoint would hide every failure.
            if ($items === []) {
                foreach ($jobComputers($run->jobName) as $o) {
                    $items[] = ['computers_id' => $o['computers_id'], 'vm_name' => $o['vm_name'], 'item_status' => $run->status];
                }
            }

            if ($store->insert($connectionId, $run, $reasons[$run->historyUuid] ?? '', self::unique($items))) {
                $out['new']++;
                if ($run->status === Run::FAILED) {
                    $out['failed']++;
                }
            }
        }
        return $out;
    }

    /** One row per VM name (case-insensitive), as the table's unique key wants. */
    private static function unique(array $items): array
    {
        $seen = [];
        $out  = [];
        foreach ($items as $i) {
            $k = mb_strtolower($i['vm_name']);
            if ($k !== '' && !isset($seen[$k])) {
                $seen[$k] = true;
                $out[]    = $i;
            }
        }
        return $out;
    }
}
