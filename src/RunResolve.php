<?php

namespace GlpiPlugin\Backupinfo;

/**
 * Which Computer a stored backup run belongs to, decided when it is read, from the objects' current
 * match (automatic or pinned by hand). Stored runs never change, so a VM matched or pinned later
 * still brings its older runs into the evidence. Pure.
 */
final class RunResolve
{
    /**
     * Name and job lookups per connection. Names: matched objects only. Jobs: every object (an
     * unmatched one with computers_id 0), so the sync still stores the VM name of each run.
     *
     * @param list<array<string, mixed>> $objects connections_id, name, job_name, items_id
     * @return array<int, array{names: array<string, int>, jobs: array<string, list<array{computers_id: int, vm_name: string}>>}>
     */
    public static function lookups(array $objects): array
    {
        $out = [];
        foreach ($objects as $o) {
            $conn = (int) $o['connections_id'];
            $name = trim((string) $o['name']);
            $id   = (int) $o['items_id'];
            $out[$conn] ??= ['names' => [], 'jobs' => []];
            if ($id > 0 && $name !== '') {
                $out[$conn]['names'][mb_strtolower($name)] = $id;
            }
            // job_name holds ", "-joined names: split, never LIKE
            foreach (explode(',', (string) $o['job_name']) as $job) {
                if (trim($job) !== '') {
                    $out[$conn]['jobs'][mb_strtolower(trim($job))][] = ['computers_id' => $id, 'vm_name' => $name];
                }
            }
        }
        return $out;
    }

    /**
     * Runs x Computers of the in-scope set, one row per run and Computer (a failed item wins).
     * Per stored item: the VM's current match, else the Computer stored with it. A run without
     * items: the objects of its job.
     *
     * @param list<array<string, mixed>> $rows runs LEFT JOIN items (vm_name, computers_id, item_status null when no item)
     * @param array<int, true> $inScope Computer id => true
     * @return list<array<string, mixed>>
     */
    public static function resolve(array $rows, array $lookups, array $inScope): array
    {
        $byRun = [];
        foreach ($rows as $row) {
            $byRun[(int) $row['runs_id']][] = $row;
        }

        $out = [];
        foreach ($byRun as $runRows) {
            $run   = $runRows[0];
            $conn  = (int) $run['connections_id'];
            $names = $lookups[$conn]['names'] ?? [];
            $items = [];
            foreach ($runRows as $r) {
                if (($r['vm_name'] ?? null) !== null && (string) $r['vm_name'] !== '') {
                    $items[] = [(string) $r['vm_name'], (int) ($r['computers_id'] ?? 0), (string) ($r['item_status'] ?? $run['status'])];
                }
            }
            if ($items === []) {
                foreach ($lookups[$conn]['jobs'][mb_strtolower(trim((string) $run['job_name']))] ?? [] as $o) {
                    $items[] = [$o['vm_name'], $o['computers_id'], (string) $run['status']];
                }
            }

            $perComputer = [];
            foreach ($items as [$vm, $stored, $status]) {
                $cid = $names[mb_strtolower(trim($vm))] ?? $stored;
                if ($cid <= 0 || !isset($inScope[$cid])) {
                    continue;
                }
                if (!isset($perComputer[$cid]) || ($status === 'failed' && $perComputer[$cid]['item_status'] !== 'failed')) {
                    $perComputer[$cid] = ['computers_id' => $cid, 'vm_name' => $vm, 'item_status' => $status] + $run;
                }
            }
            array_push($out, ...array_values($perComputer));
        }
        return $out;
    }
}
