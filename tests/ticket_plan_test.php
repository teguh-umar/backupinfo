<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/Tickets/IncidentPlan.php';
require BI_ROOT . '/src/Tickets/ProblemPlan.php';
require BI_ROOT . '/src/Tickets/TicketInput.php';
require BI_ROOT . '/src/Tickets/FindingPlan.php';
require BI_ROOT . '/src/Tickets/FollowupPlan.php';

use GlpiPlugin\Backupinfo\Tickets\IncidentPlan;
use GlpiPlugin\Backupinfo\Tickets\ProblemPlan;
use GlpiPlugin\Backupinfo\Tickets\TicketInput;
use GlpiPlugin\Backupinfo\Tickets\FindingPlan;
use GlpiPlugin\Backupinfo\Tickets\FollowupPlan;

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

// --- template merge: GLPI 11 does not apply predefined fields on a code add
$pre = ['_users_id_requester' => '7', '_groups_id_assign' => ['3', '4'], '_suppliers_id_assign' => ['2'], 'itilcategories_id' => '9', 'urgency' => '4', 'name' => 'T', 'items_id' => ['Computer' => [1 => 1]], 'status' => '5'];
$in  = TicketInput::build($pre, ['name' => 'Backup failed: SRV-A', 'type' => 1, 'entities_id' => 3, 'items_id' => ['Computer' => [11]]]);
check('template: actors, category and urgency copied', $in['_groups_id_assign'] === ['3', '4'] && $in['_suppliers_id_assign'] === ['2'] && $in['_users_id_requester'] === '7' && $in['itilcategories_id'] === '9' && $in['urgency'] === '4');
check('template: own name, entity and Computer win; template status and items never copied', $in['name'] === 'Backup failed: SRV-A' && $in['entities_id'] === 3 && $in['items_id'] === ['Computer' => [11]] && !isset($in['status']));
check('template warnings: nobody assigned, no requester', count(TicketInput::warnings([], 'X')) === 2 && TicketInput::warnings($pre, 'X') === []);
check('template warnings: none selected', count(TicketInput::warnings([], '')) === 1);

// --- wiring
$read = static fn (string $rel): string => (string) @file_get_contents(BI_ROOT . '/' . $rel);
$svc  = $read('src/Tickets/TicketService.php');
check('service: off until enabled, dry run writes nothing', str_contains($svc, "if (!\$dry && empty(\$cfg['tickets_enabled']))") && substr_count($svc, 'if ($dry) {') === 3);
check('service: never updates or closes a ticket (adds and links only)', !preg_match('/(new Ticket\(\)|\$ticket)->update\(|->delete\(/', $svc) && !str_contains($svc, "'status' =>"));
check('service: link row written only after the add succeeded', strpos($svc, "if (!\$id) {") < strpos($svc, '$DB->insert(self::TABLE'));
check('service: templates created once, root entity, recursive', str_contains($svc, "'entities_id' => 0, 'is_recursive' => 1") && str_contains($read('install/install.php'), 'TicketService::ensureTemplates()'));
check('cron: tickets after the stores, never failing the sync', str_contains($read('src/Cron.php'), 'self::raiseTickets($now, $log);') && str_contains($read('src/Cron.php'), 'TicketService::run(false, $now)'));
$conf = $read('front/config.php');
check('setup: enabling stores the start time; dry run behind config UPDATE', str_contains($conf, 'SuiteConfig::withTicketsSince(') && str_contains($conf, 'TicketService::run(true, time())'));

// --- findings
$plan = FindingPlan::plan(['BCKP-COVERAGE' => [11, 12, 11], 'BCKP-RESTORE' => [13], 'BCKP-X' => [11]], [11 => 1, 12 => 2, 13 => 1], ['1|BCKP-REPEAT' => true, '1|BCKP-COVERAGE' => true]);
$key  = static fn (array $f): string => $f['entities_id'] . '|' . $f['code'] . '|' . implode(',', $f['computers']) . '|' . ($f['cleared'] ? 'c' : '');
check('findings: one per entity and code, never recursive across entities, unknown codes ignored', array_map($key, $plan) === ['1|BCKP-COVERAGE|11|', '2|BCKP-COVERAGE|12|', '1|BCKP-RESTORE|13|', '1|BCKP-REPEAT||c'], json_encode(array_map($key, $plan)));
check('findings: Computer without an entity skipped', FindingPlan::plan(['BCKP-RESTORE' => [99]], [], []) === []);
check('findings: content lists names, escaped', str_contains(FindingPlan::content('BCKP-RESTORE', ['<SRV>']), '&lt;SRV&gt;'));
$fs = $read('src/Tickets/FindingService.php');
check('findings service: guarded, never sets status, never recursive, A.8.13 linked', str_contains($fs, "isPluginActive('riskregister')") && !str_contains($fs, "'status'") && str_contains($fs, "'is_recursive'   => 0") && str_contains($fs, "'A.8.13'"));
check('cron: findings after tickets', strpos($read('src/Cron.php'), 'self::raiseTickets($now, $log);') < strpos($read('src/Cron.php'), 'self::raiseFindings($now, $log);'));

check('findings: rc1 BI-* codes renamed to BCKP-* once', str_contains($fs, "self::REF . 'BI-%'") && str_contains($fs, "'BCKP-' . substr("));
check('findings: codes are BCKP-*', array_keys(FindingPlan::CODES) === ['BCKP-COVERAGE', 'BCKP-RESTORE', 'BCKP-REPEAT']);

// --- optional follow-up "Backup succeeded again"
$open = [['tickets_id' => 501, 'computers_id' => 11, 'start_time' => '2026-11-10 01:00:00'], ['tickets_id' => 502, 'computers_id' => 12, 'start_time' => '2026-11-10 01:00:00']];
$rows = [$row(8, 11, 'success', '2026-11-12 01:00:00'), $row(9, 11, 'success', '2026-11-11 01:00:00'), $row(10, 12, 'success', '2026-11-09 01:00:00'), $row(11, 12, 'failed', '2026-11-12 01:00:00')];
$fu   = FollowupPlan::plan($open, $rows);
check('follow-up: first later success of that Computer only', count($fu) === 1 && $fu[0]['tickets_id'] === 501 && str_contains($fu[0]['content'], 'Backup succeeded again') && str_contains($fu[0]['content'], 'not closed'), json_encode($fu));
check('follow-up: no later success -> nothing', FollowupPlan::plan([$open[1]], $rows) === []);
$svc = $read('src/Tickets/TicketService.php');
check('follow-up: off by default, once per Incident, open Incidents only, never closes', str_contains($svc, "if (!empty(\$cfg['followup_on_success']))") && str_contains($svc, "'t.followup_done' => 0") && str_contains($svc, "'k.status' => [CommonITILObject::SOLVED, CommonITILObject::CLOSED]") && str_contains($read('install/install.php'), "'followup_on_success'    => 0"));

done();
