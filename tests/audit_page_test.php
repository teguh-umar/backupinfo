<?php

require __DIR__ . '/bootstrap.php';

foreach (['Rpo', 'Status', 'RestoreInterval', 'AuditPeriod', 'AuditPage', 'Csv', 'Source/Event', 'Source/Target', 'Source/Registry', 'Source/Run', 'Source/RunHistorySource', 'Source/PartialFetch', 'Source/ProbeableSource', 'Source/AuditProbeableSource', 'Source/AuditSource', 'Source/BackupObject', 'Source/BackupSource', 'Source/Time', 'Source/VerifyingSource', 'Text', 'Http/Transport', 'Http/CurlTransport', 'Veeam/ApiError', 'Veeam/Client', 'Veeam/EventMapper', 'Veeam/ObjectMapper', 'Veeam/RunMapper', 'Veeam/TargetMapper', 'Veeam/VeeamSource', 'Vinchin/AlarmJoin', 'Vinchin/Signer', 'Vinchin/VerifyMapper', 'Vinchin/VinchinSource', 'Probe'] as $f) {
    require BI_ROOT . "/src/$f.php";
}

use GlpiPlugin\Backupinfo\AuditPage;
use GlpiPlugin\Backupinfo\AuditPeriod;
use GlpiPlugin\Backupinfo\Csv;
use GlpiPlugin\Backupinfo\Rpo;
use GlpiPlugin\Backupinfo\Source\Event;
use GlpiPlugin\Backupinfo\Source\Target;

$read = static fn (string $rel): string => (string) @file_get_contents(BI_ROOT . '/' . $rel);
$now  = strtotime('2026-11-04 10:00:00');

// --- period
$p = AuditPeriod::parse('', $now);
check('period: default = last full month', $p['from'] === '2026-10-01 00:00:00' && $p['to'] === '2026-10-31 23:59:59' && $p['key'] === '2026-10');
$p = AuditPeriod::parse('2026-02', $now);
check('period: month, inclusive end of day', $p['from'] === '2026-02-01 00:00:00' && $p['to'] === '2026-02-28 23:59:59' && $p['label'] === '2026-02');
$p = AuditPeriod::parse('2026-Q3', $now);
check('period: quarter', $p['from'] === '2026-07-01 00:00:00' && $p['to'] === '2026-09-30 23:59:59' && $p['key'] === '2026-Q3');
$p = AuditPeriod::parse('2025', $now);
check('period: year', $p['from'] === '2025-01-01 00:00:00' && $p['to'] === '2025-12-31 23:59:59');
foreach (['2026-13', '2026-Q5', 'x', '2026-1', '1999', '2026-10; DROP'] as $bad) {
    check("period: bad '$bad' -> default", AuditPeriod::parse($bad, $now)['key'] === '2026-10');
}
check('period: future month -> default', AuditPeriod::parse('2027-01', $now)['key'] === '2026-10');
check('period: current month allowed (to = now cut later)', AuditPeriod::parse('2026-11', $now)['key'] === '2026-11');
$keys = array_map('strval', array_keys(AuditPeriod::choices($now))); // a year key becomes an int key in PHP
check('period choices: months, quarters, years, newest first', $keys[0] === '2026-11' && in_array('2026-Q4', $keys, true) && in_array('2025', $keys, true) && in_array('2025-11', $keys, true));

// --- builders
$rpo       = new Rpo(26, 24);
$computers = [11 => 'SRV-A', 12 => 'SRV-B', 13 => 'SRV-C'];
$objects   = [
    11 => [['name' => 'VM-A', 'job_name' => 'JOB-1', 'schedule_hours' => 24, 'last_backup' => $now - 3600, 'source_label' => 'Vinchin']],
    12 => [['name' => 'VM-B', 'job_name' => 'JOB-1, JOB-2', 'schedule_hours' => null, 'last_backup' => $now - 30 * 86400, 'source_label' => 'Vinchin']],
];

$s = AuditPage::policy($computers, $objects, $rpo);
check('policy: one row per object, Computer without object listed too', count($s['rows']) === 3 && $s['rows'][2][0] === 'SRV-C' && $s['rows'][2][1] === '');
check('policy: RPO label from schedule or global', $s['rows'][0][4] === '48 h (schedule 24 h + 24 h)' && $s['rows'][1][4] === '26 h (global)', json_encode($s['rows']));

$platform = [['vm_name' => 'vm-b', 'backup_status' => 1, 'backup_count' => 3, 'last_backup_time' => ''], ['vm_name' => 'SRV-C', 'backup_status' => 0, 'backup_count' => 0, 'last_backup_time' => '']];
$s = AuditPage::coverage($computers, $objects, $rpo, $platform, $now);
check('coverage: status per Computer', array_column($s['rows'], 1) === ['protected', 'expired', 'none'], json_encode($s['rows']));
check('coverage: platform report matched by VM or Computer name', $s['rows'][1][4] === 'protected' && $s['rows'][2][4] === 'not protected' && $s['rows'][0][4] === '');
check('coverage: note says it is current, not historical', str_contains($s['note'], 'now'));

$runs = [
    ['computers_id' => 11, 'vm_name' => 'VM-A', 'job_name' => 'JOB-1', 'start_time' => '2026-10-02 01:00:00', 'finish_time' => '2026-10-02 01:10:00', 'status' => 'success', 'item_status' => 'success', 'duration_s' => 600, 'size_text' => '1GB', 'history_uuid' => 'u1', 'reason' => null, 'connection' => 'C1'],
    ['runs_id' => 2, 'computers_id' => 12, 'vm_name' => 'VM-B', 'job_name' => 'JOB-1', 'start_time' => '2026-10-03 01:00:00', 'finish_time' => '2026-10-03 01:00:09', 'status' => 'failed', 'item_status' => 'failed', 'duration_s' => 9, 'size_text' => '0B', 'history_uuid' => 'u2', 'reason' => 'Failed to prepare', 'connection' => 'C1'],
    ['computers_id' => 11, 'vm_name' => 'VM-A', 'job_name' => 'JOB-1', 'start_time' => '2026-10-03 01:00:00', 'finish_time' => '2026-10-03 01:00:09', 'status' => 'failed', 'item_status' => 'success', 'duration_s' => 9, 'size_text' => '0B', 'history_uuid' => 'u2', 'reason' => 'Failed to prepare', 'connection' => 'C1'],
];
$s = AuditPage::population($computers, $runs);
check('population: one line per run x Computer', count($s['rows']) === 3 && $s['rows'][0][0] === 'SRV-A' && $s['rows'][0][9] === 'u1');
check('population: columns', $s['columns'][0] === 'Computer' && in_array('Run ID', $s['columns'], true));
$s = AuditPage::failures($computers, $runs);
check('failures: only items that failed (a VM that succeeded in a failed run is not a failure)', count($s['rows']) === 1 && $s['rows'][0][0] === 'SRV-B' && $s['rows'][0][5] === 'Failed to prepare');
$s = AuditPage::failures($computers, $runs, ['2|12' => ['tickets_id' => 501, 'problems_id' => 0]]);
check('failures: Incident and Problem of the plugin shown (follow-up evidence)', $s['rows'][0][8] === '#501' && $s['rows'][0][9] === '' && $s['columns'][8] === 'Incident');

$tests = [
    ['computers_id' => 11, 'date' => '2026-10-15', 'method' => 'vm_restore', 'result' => 'pass', 'source' => 'manual'],
    ['computers_id' => 12, 'date' => '2025-09-01', 'method' => 'file_restore', 'result' => 'pass', 'source' => 'manual'],
    ['computers_id' => 12, 'date' => '2026-10-20', 'method' => 'file_restore', 'result' => 'fail', 'source' => 'manual'],
];
$s = AuditPage::restore($computers, $tests, [13 => 0], 12, '2026-10-01', '2026-10-31');
$byName = array_column($s['rows'], null, 0);
check('restore: passed in period -> ok', $byName['SRV-A'][4] === 'ok' && $byName['SRV-A'][2] === '2026-10-15' && $byName['SRV-A'][6] === 1);
check('restore: failed test does not restart the clock -> overdue at period end', $byName['SRV-B'][4] === 'overdue' && $byName['SRV-B'][2] === '2025-09-01' && $byName['SRV-B'][5] === '2026-10-20 (fail)');
check('restore: override 0 -> exempt', $byName['SRV-C'][1] === 0 && $byName['SRV-C'][4] === 'exempt');
check('restore: tests after the period are ignored', AuditPage::restore([11 => 'SRV-A'], $tests, [], 12, '2026-09-01', '2026-09-30')['rows'][0][4] === 'none');

$copies = [
    Event::make('copy', ['job' => 'COPY-1', 'time' => '2026-10-02 14:54:35', 'end_time' => '2026-10-02 16:07:27', 'status' => 'success', 'vm' => 'vm-a', 'item_status' => 'success', 'restore_points' => 72, 'size' => '1GB']),
    Event::make('copy', ['job' => 'COPY-1', 'time' => '2026-10-02 14:54:35', 'status' => 'success', 'vm' => 'VM-OTHER', 'item_status' => 'success', 'restore_points' => 1]),
];
$s = AuditPage::copies($computers, $objects, $copies);
check('copies: only in-scope VMs, matched by object name', count($s['rows']) === 1 && $s['rows'][0][0] === 'SRV-A' && $s['rows'][0][7] === 72);

$job    = [Event::make('joblog', ['time' => '2026-10-20 04:07:37', 'user' => 'u1', 'level' => 'Success', 'job' => 'JOB-1', 'message' => 'scheduled'])];
$sys    = [
    Event::make('syslog', ['time' => '2026-10-21 17:30:55', 'user' => 'u2', 'action' => 'System Login', 'message' => "login from IP: '10.0.0.9'", 'source_ip' => '10.0.0.9']),
    Event::make('syslog', ['time' => '2026-10-21 17:31:00', 'user' => 'admin', 'action' => 'System Login', 'message' => "login from IP: '10.0.0.5'", 'source_ip' => '10.0.0.5']),
    Event::make('syslog', ['time' => '2026-10-21 17:32:00', 'user' => 'admin', 'action' => 'Modify job', 'message' => 'from 10.0.0.5', 'source_ip' => '10.0.0.5']),
];
$alarms = [Event::make('sysalarm', ['time' => '2026-10-19 15:52:42', 'level' => 'Failed', 'status' => 'Pending', 'message' => 'disk'])];
$s = AuditPage::activity($job, $sys, $alarms, ['10.0.0.5'], false);
check('activity: merged newest first with kinds', array_column($s['rows'], 1) === ['System log', 'System log', 'Job log', 'System alarm'], json_encode(array_column($s['rows'], 1)));
check('activity: logins from this GLPI server hidden, other actions from it kept', !in_array('2026-10-21 17:31:00', array_column($s['rows'], 0), true)
    && in_array('2026-10-21 17:32:00', array_column($s['rows'], 0), true));
check('activity: says how many were hidden', str_contains($s['note'], '1 login'));
check('activity: show all keeps them', count(AuditPage::activity($job, $sys, $alarms, ['10.0.0.5'], true)['rows']) === 5);
check('activity: no own IP known -> nothing hidden', count(AuditPage::activity($job, $sys, $alarms, [], false)['rows']) === 5);
check('activity: source IP column', in_array('10.0.0.9', array_column($s['rows'], 5), true));

$tape = ['kind' => 'tape', 'name' => 'Tape_Group_2', 'type' => 'Tape group', 'node' => 'NODE-1', 'total' => '21.83TB', 'used' => '11.52TB', 'free' => '10.31TB', 'online' => 'yes', 'jobs' => 'WEEKLY COPY-A'];
$s = AuditPage::targets(
    [7 => ['taken_at' => '2026-10-31 23:00:00', 'rows' => [Target::make($tape), Target::make(['kind' => 'tape library', 'name' => 'LIB-1', 'type' => 'QUANTUM Scalar', 'detail' => '20 slots'])]]],
    [7 => ['taken_at' => '2026-10-01 23:00:00', 'rows' => [Target::make(['used' => '9.10TB'] + $tape)]]],
    [7 => 'Vinchin A']
);
$col = array_flip($s['columns']);
check('targets: one row per target, every column', count($s['rows']) === 2 && $s['rows'][0][0] === 'tape' && $s['rows'][0][$col['Jobs writing to it']] === 'WEEKLY COPY-A' && $s['columns'][0] === 'Kind');
check('targets: used at period start from the oldest snapshot', $s['rows'][0][$col['Used at period start']] === '9.10TB' && $s['rows'][0][$col['Used']] === '11.52TB' && $s['rows'][1][$col['Used at period start']] === '');
check('targets: as of the snapshot, named in the note', $s['rows'][0][$col['As of']] === '2026-10-31 23:00:00' && str_contains($s['note'], 'Vinchin A 2026-10-31 23:00:00'));
check('targets: no snapshot in the period -> says so', AuditPage::targets([], [], [])['rows'] === [] && str_contains(AuditPage::targets([], [], [])['note'], 'No snapshot'));
check('targets: in the sections list', in_array('targets', AuditPage::SECTIONS, true) && isset(AuditPage::titles()['targets']));

// --- withGaps: declared gaps appended to the section note
$t = ['note' => 'N', 'columns' => [], 'rows' => []];
$g = AuditPage::withGaps($t, [['connection' => 'VBR-1', 'sentence' => 'Tape is not available from Veeam REST.']]);
check('withGaps: appends gap sentence', $g['note'] === 'N Not available from VBR-1: Tape is not available from Veeam REST.');
check('withGaps: no gaps returns table unchanged', AuditPage::withGaps($t, []) === $t);

// --- gapsFor: one sentence per connection type, not a foreach over a string
check('gapsFor: veeam targets gives one gap', AuditPage::gapsFor([['name' => 'VBR-1', 'type' => 'veeam']], 'targets') === [['connection' => 'VBR-1', 'sentence' => 'tape is not available from Veeam REST.']]);
check('gapsFor: veeam activity gives one gap', count(AuditPage::gapsFor([['name' => 'VBR-1', 'type' => 'veeam']], 'activity')) === 1 && str_contains(AuditPage::gapsFor([['name' => 'VBR-1', 'type' => 'veeam']], 'activity')[0]['sentence'], 'Four-eyes'));
check('gapsFor: vinchi gives none', AuditPage::gapsFor([['name' => 'VC-1', 'type' => 'vinchin']], 'targets') === []);
check('gapsFor: empty connections gives none', AuditPage::gapsFor([], 'targets') === []);

// --- CSV
$csv = Csv::render(['Period' => '2026-10', 'Generated by' => 'u1'], ['columns' => ['A', 'B'], 'rows' => [
    ['=1+1', '+x'], ['-5', '@SUM(A1)'], ["\tx", "\rx"], [-5, 'normal'], ['é ü', 'a,b "q"'],
]]);
check('csv: UTF-8 BOM', str_starts_with($csv, "\xEF\xBB\xBF"));
$lines = preg_split('/\r?\n/', substr($csv, 3));
check('csv: header block, blank line, then the table', $lines[0] === 'Period,2026-10' && $lines[1] === '"Generated by",u1' && $lines[2] === '' && $lines[3] === 'A,B', json_encode(array_slice($lines, 0, 4)));
check('csv: formula cells neutralised', $lines[4] === "'=1+1,'+x" && $lines[5] === "'-5,'@SUM(A1)", json_encode([$lines[4], $lines[5]]));
check('csv: tab and CR neutralised', str_contains($csv, "\"'\tx\"") && str_contains($csv, "'\rx"));
check('csv: real numbers untouched', in_array('-5,normal', $lines, true));
check('csv: unicode and quoting kept', in_array('"é ü","a,b ""q"""', $lines, true));

// --- page wiring
$page = $read('front/audit.php');
check('page: report READ', str_contains($page, 'Session::checkRight(RightDefinition::REPORT, READ);'));
check('page: export before Html::header', ($e = strpos($page, "Csv::render(")) !== false && $e < strpos($page, 'Html::header('));
check('page: export exits', (bool) preg_match('/Csv::render\([^;]*;\s*exit;/s', $page) || (bool) preg_match('/echo Csv::render\(.*?\);\s*exit;/s', $page));
check('page: sub-nav', str_contains($page, "renderSubNav('backupinfo', 'audit')"));
check('page: writes nothing', !preg_match('/->(add|update|delete)\(|\$DB->(insert|update|delete|doQuery)|setConfigurationValues/', $page));
check('page: no entity list of its own', !str_contains($page, 'glpi_entities'));
$data = $read('src/AuditData.php');
check('data: in-scope Computers restricted to the user entities', str_contains($data, "getEntitiesRestrictCriteria('glpi_computers')") && str_contains($data, 'mandatoryIds()'));
check('data: system logs/alarms behind config READ (logins carry IPs)', str_contains($data, 'Session::haveRight(RightDefinition::CONFIG, READ)'));
$menu = $read('src/Menu.php');
check('menu: audit evidence behind report READ', (bool) preg_match("/\\\$options\['audit'\] = \[\s*'title' => __\('Audit evidence', 'backupinfo'\),/", $menu));

done();
