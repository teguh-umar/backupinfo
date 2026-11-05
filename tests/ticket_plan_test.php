<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/Tickets/IncidentPlan.php';
require BI_ROOT . '/src/Tickets/ProblemPlan.php';

use GlpiPlugin\Backupinfo\Tickets\IncidentPlan;
use GlpiPlugin\Backupinfo\Tickets\ProblemPlan;

$row = static fn (int $run, int $c, string $item = 'failed', string $start = '2026-11-10 01:00:00', string $reason = 'Disk full'): array => [
    'runs_id' => $run, 'computers_id' => $c, 'vm_name' => 'VM-' . $c, 'item_status' => $item, 'status' => 'failed',
    'job_name' => 'JOB-1', 'start_time' => $start, 'finish_time' => '2026-11-10 01:30:00', 'reason' => $reason,
    'history_uuid' => 'u' . $run, 'connection' => 'Conn A',
];
$scope = [11 => 'SRV-A', 12 => 'SRV-B'];
$since = '2026-11-05 00:00:00';

// --- incidents
$p = IncidentPlan::plan([$row(1, 11), $row(1, 12, 'success'), $row(2, 11), $row(2, 11)], [], $since, $scope);
check('one incident per failed run x Computer, success items skipped, duplicates once', array_map(static fn ($i) => [$i['runs_id'], $i['computers_id']], $p) === [[1, 11], [2, 11]], json_encode($p));
check('title names the Computer, job and start', $p[0]['name'] === 'Backup failed: SRV-A (JOB-1, 2026-11-10 01:00:00)');
check('content has the reason, VM, run reference and connection', str_contains($p[0]['content'], 'Disk full') && str_contains($p[0]['content'], 'VM-11') && str_contains($p[0]['content'], 'u1') && str_contains($p[0]['content'], 'Conn A'));
check('already linked: nothing again, ever', IncidentPlan::plan([$row(1, 11)], ['1|11' => true], $since, $scope) === []);
check('runs before the start time never open a ticket', IncidentPlan::plan([$row(3, 11, 'failed', '2026-11-04 23:59:59')], [], $since, $scope) === []);
check('out of scope and unmatched (0) skipped', IncidentPlan::plan([$row(4, 99), $row(4, 0)], [], $since, $scope) === []);
check('warning items do not open a ticket (Failed only)', IncidentPlan::plan([$row(5, 11, 'warning')], [], $since, $scope) === []);
check('no reason: says so, never guessed', str_contains(IncidentPlan::plan([$row(6, 11, 'failed', '2026-11-10 01:00:00', '')], [], $since, $scope)[0]['content'], 'not given by the backup system'));
check('reason HTML from the source never reaches the ticket', !str_contains(IncidentPlan::plan([$row(7, 11, 'failed', '2026-11-10 01:00:00', '<b>x</b>')], [], $since, $scope)[0]['content'], '<b>x</b>'));

// --- problems: 3 failures in 30 days
$now = strtotime('2026-11-20 00:00:00');
$f   = static fn (int $t, string $start, int $prob = 0): array => ['tickets_id' => $t, 'start_time' => $start, 'problems_id' => $prob];
$r   = ProblemPlan::plan([11 => [$f(101, '2026-11-01 01:00:00'), $f(102, '2026-11-10 01:00:00'), $f(103, '2026-11-19 01:00:00')]], [], 3, 30, $now);
check('3 failures in 30 days: one problem with the 3 incidents', $r['open'] === [11 => [101, 102, 103]] && $r['link'] === [], json_encode($r));
$r = ProblemPlan::plan([11 => [$f(101, '2026-10-01 01:00:00'), $f(102, '2026-11-10 01:00:00'), $f(103, '2026-11-19 01:00:00')]], [], 3, 30, $now);
check('outside the window: not counted', $r['open'] === []);
$r = ProblemPlan::plan([11 => [$f(101, '2026-11-01 01:00:00', 500), $f(104, '2026-11-19 01:00:00')]], [11 => 500], 3, 30, $now);
check('open problem: new incidents linked to it, no new problem', $r['open'] === [] && $r['link'] === [[500, 104]], json_encode($r));
$r = ProblemPlan::plan([11 => [$f(101, '2026-11-01 01:00:00', 400), $f(102, '2026-11-02 01:00:00', 400), $f(103, '2026-11-03 01:00:00', 400), $f(104, '2026-11-19 01:00:00')]], [], 3, 30, $now);
check('after a solved problem: its incidents do not count for a new streak', $r['open'] === []);
check('threshold from Setup', ProblemPlan::plan([12 => [$f(201, '2026-11-18 01:00:00'), $f(202, '2026-11-19 01:00:00')]], [], 2, 30, $now)['open'] === [12 => [201, 202]]);

done();
