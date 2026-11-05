<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/RunResolve.php';

use GlpiPlugin\Backupinfo\RunResolve;

$read = static fn (string $rel): string => (string) @file_get_contents(BI_ROOT . '/' . $rel);

// Objects as stored: one pinned/matched, one unmatched sharing a job, one on another connection.
$objects = [
    ['connections_id' => 1, 'name' => 'VM-FILE-02', 'job_name' => 'VM BACKUP_VM-FILE-02 (DAILY&WEEKLY), WEEKLY COPY BACKUP_VM-FILE-01', 'items_id' => 848],
    ['connections_id' => 1, 'name' => 'VM-FILE-01', 'job_name' => 'WEEKLY COPY BACKUP_VM-FILE-01', 'items_id' => 848],
    ['connections_id' => 1, 'name' => 'VM-OTHER', 'job_name' => 'JOB-B', 'items_id' => 0],
    ['connections_id' => 2, 'name' => 'VM-FILE-02', 'job_name' => 'VM BACKUP_VM-FILE-02 (DAILY&WEEKLY)', 'items_id' => 900],
];
$l = RunResolve::lookups($objects);
check('lookups: names only for matched objects, lower-cased', ($l[1]['names'] ?? []) === ['vm-file-02' => 848, 'vm-file-01' => 848]);
check('lookups: jobs split on comma, unmatched objects kept with 0', count($l[1]['jobs']['weekly copy backup_vm-file-01']) === 2
    && $l[1]['jobs']['job-b'] === [['computers_id' => 0, 'vm_name' => 'VM-OTHER']]);
check('lookups: per connection', $l[2]['names'] === ['vm-file-02' => 900]);

$run = static fn (int $id, string $job, string $status, ?string $vm = null, ?int $cid = null, ?string $item = null, int $conn = 1): array => [
    'runs_id' => $id, 'connections_id' => $conn, 'history_uuid' => "u$id", 'job_name' => $job, 'start_time' => "2026-11-0$id 01:00:00",
    'finish_time' => null, 'status' => $status, 'size_text' => '', 'duration_s' => null, 'reason' => '',
    'vm_name' => $vm, 'computers_id' => $cid, 'item_status' => $item,
];

// Stored before the pin: no items at all. Resolved now through the job.
$rows = RunResolve::resolve([$run(1, 'VM BACKUP_VM-FILE-02 (DAILY&WEEKLY)', 'success')], $l, [848 => true]);
check('run with no items: Computers from its job, at read time', count($rows) === 1 && $rows[0]['computers_id'] === 848 && $rows[0]['vm_name'] === 'VM-FILE-02' && $rows[0]['item_status'] === 'success');

// Stored item with computers_id 0: the current match wins.
$rows = RunResolve::resolve([$run(2, 'X', 'failed', 'vm-file-02', 0, 'failed')], $l, [848 => true]);
check('stored item unmatched then: current name match used', count($rows) === 1 && $rows[0]['computers_id'] === 848 && $rows[0]['item_status'] === 'failed');

// Stored item whose VM is no longer matched: the stored Computer stays.
$rows = RunResolve::resolve([$run(3, 'X', 'success', 'VM-GONE', 848, 'success')], $l, [848 => true]);
check('VM no longer matched: stored Computer kept', count($rows) === 1 && $rows[0]['computers_id'] === 848);

// Two VMs of one run on the same Computer: one row, the failure wins.
$rows = RunResolve::resolve([
    $run(4, 'WEEKLY COPY BACKUP_VM-FILE-01', 'failed', 'VM-FILE-02', 848, 'success'),
    $run(4, 'WEEKLY COPY BACKUP_VM-FILE-01', 'failed', 'VM-FILE-01', 848, 'failed'),
], $l, [848 => true]);
check('one row per run and Computer, failed item kept', count($rows) === 1 && $rows[0]['item_status'] === 'failed');

// Out of scope and unknown jobs drop out; connection matters.
check('out of scope dropped', RunResolve::resolve([$run(5, 'VM BACKUP_VM-FILE-02 (DAILY&WEEKLY)', 'success')], $l, [1 => true]) === []);
check('unknown job: nothing', RunResolve::resolve([$run(6, 'NOPE', 'success')], $l, [848 => true]) === []);
check('job looked up on the run\'s own connection', RunResolve::resolve([$run(7, 'VM BACKUP_VM-FILE-02 (DAILY&WEEKLY)', 'success', null, null, null, 2)], $l, [848 => true, 900 => true])[0]['computers_id'] === 900);
check('job name match ignores case and spaces', count(RunResolve::resolve([$run(8, ' vm backup_vm-file-02 (daily&weekly) ', 'success')], $l, [848 => true])) === 1);

// Wiring
$data = $read('src/AuditData.php');
check('audit reads runs through the resolver', str_contains($data, 'RunResolve::resolve(') && str_contains($data, 'RunResolve::lookups('));
check('audit: runs selected by period, not by stored Computer', !str_contains($data, "'i.computers_id' => \$chunk"));
$cron = $read('src/Cron.php');
check('cron: same lookups as the page', str_contains($cron, 'RunResolve::lookups(') && !str_contains($cron, 'function objectLookups'));
$store = $read('src/Sync/DbObjectStore.php');
check('object store: all objects of active connections for lookups', str_contains($store, 'public function forLookups(): array'));

done();
