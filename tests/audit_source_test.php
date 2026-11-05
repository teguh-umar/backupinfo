<?php

require __DIR__ . '/bootstrap.php';

foreach ([
    'Text', 'Source/BackupObject', 'Source/Run', 'Source/BackupSource', 'Source/ProbeableSource',
    'Source/AuditProbeableSource', 'Source/RunHistorySource', 'Source/AuditSource', 'Source/PartialFetch',
    'Vinchin/Signer', 'Vinchin/Transport', 'Vinchin/Client', 'Vinchin/ItemMapper', 'Vinchin/JobHistoryMapper',
    'Vinchin/ScheduleMapper', 'Vinchin/RunMapper', 'Source/Event', 'Source/Target', 'Vinchin/EventMapper', 'Vinchin/VinchinSource',
] as $f) {
    require BI_ROOT . "/src/$f.php";
}

use GlpiPlugin\Backupinfo\Source\AuditSource;
use GlpiPlugin\Backupinfo\Vinchin\Client;
use GlpiPlugin\Backupinfo\Vinchin\VinchinSource;

require __DIR__ . '/fakes.php';

$src = static fn (RouteT $t): VinchinSource => new VinchinSource(new Client('h', $t, 'K'), false, fn () => []);
$from = '2026-10-01 00:00:00';
$to   = '2026-10-31 23:59:59';

check('VinchinSource is an AuditSource', (new \ReflectionClass(VinchinSource::class))->implementsInterface(AuditSource::class));

// --- platform VMs: all pages, keyword-free, no hypervisor IP kept
$t = new RouteT();
$t->routes['GET /backupmanager/report/vm'] = [
    okd(['total' => 3, 'rows' => [['vm_name' => 'VM-A', 'vm_type' => 47, 'vcenter_ip' => '10.0.0.9:4430', 'last_backup_time' => '--', 'backup_status' => 0, 'backup_count' => 0, 'backup_data' => '0B']]]),
    okd(['total' => 3, 'rows' => [['vm_name' => 'VM-B', 'backup_status' => 1, 'backup_count' => 4, 'last_backup_time' => '2026-10-30 01:00:00'], ['vm_name' => 'VM-C', 'backup_status' => 1]]]),
];
$r = $src($t)->platformVms();
check('platformVms: every page', array_column($r['rows'], 'vm_name') === ['VM-A', 'VM-B', 'VM-C'] && $r['error'] === '');
check('platformVms: hypervisor IP not kept', !array_key_exists('vcenter_ip', $r['rows'][0]));
check('platformVms: "--" time -> empty', $r['rows'][0]['last_backup_time'] === '' && $r['rows'][1]['last_backup_time'] === '2026-10-30 01:00:00');
check('platformVms: status and count as ints', $r['rows'][0]['backup_status'] === 0 && $r['rows'][1]['backup_count'] === 4);

// --- job logs: period cut, newest first, stop at $from, HTML stripped
$t = new RouteT();
$t->routes['GET /logs/job'] = [
    okd(['total' => 5, 'rows' => [
        ['op_time' => '2026-11-02 00:00:00', 'job_name' => 'J', 'log_description' => 'after'],
        ['op_time' => '2026-10-20 04:07:37', 'job_name' => 'J', 'user_name' => 'admin', 'log_level_des' => 'Success', 'log_description' => "Job '<span class=\"font-green\">J</span>' has been scheduled on."],
    ]]),
    okd(['total' => 5, 'rows' => [['op_time' => '2026-10-01 00:00:00', 'log_description' => 'edge'], ['op_time' => '2026-09-30 23:59:59', 'log_description' => 'before']]]),
    okd(['total' => 5, 'rows' => [['op_time' => '2026-09-01 00:00:00']]]),
];
$r = $src($t)->jobLogs($from, $to);
check('jobLogs: only the period, edges inclusive', array_column($r['rows'], 'time') === ['2026-10-20 04:07:37', '2026-10-01 00:00:00'], json_encode(array_column($r['rows'], 'time')));
check('jobLogs: stopped at the page older than from', count($t->to('GET', '/logs/job')) === 2);
check('jobLogs: common event, plain text', $r['rows'][0]['kind'] === 'joblog' && $r['rows'][0]['message'] === "Job 'J' has been scheduled on." && $r['rows'][0]['user'] === 'admin' && $r['rows'][0]['level'] === 'Success' && $r['rows'][0]['job'] === 'J');
$q = query($t->to('GET', '/logs/job')[0]['url']);
check('jobLogs: sort op_time desc', ($q['sort'] ?? '') === 'op_time' && ($q['order'] ?? '') === 'desc');

// --- system logs: never a keyword (a VM keyword returned 0 rows on the VM), sort fallback
$t = new RouteT();
$t->routes['GET /logs/system'] = [
    ['status' => 200, 'headers' => [], 'body' => json_encode(['success' => false, 'code' => 1, 'message' => 'bad sort'])],
    okd(['total' => 1, 'rows' => [['op_time' => '2026-10-03 17:30:55', 'op_type' => 'SYSTEM_USER_LOGIN_SUCCESS', 'description' => 'login from <span>10.0.0.1</span>']]]),
];
$r = $src($t)->systemLogs($from, $to);
$calls = $t->to('GET', '/logs/system');
check('systemLogs: sort create_time then op_time', array_map(static fn ($c) => query($c['url'])['sort'] ?? '', $calls) === ['create_time', 'op_time']);
check('systemLogs: no search keyword sent', !isset(query($calls[1]['url'])['search']) || query($calls[1]['url'])['search'] === '');
check('systemLogs: common event with source IP', count($r['rows']) === 1 && $r['rows'][0]['message'] === 'login from 10.0.0.1' && $r['rows'][0]['source_ip'] === '10.0.0.1' && $r['rows'][0]['action'] === 'SYSTEM_USER_LOGIN_SUCCESS');

// --- system alarms
$t = new RouteT();
$t->routes['GET /alarm/system'] = [okd(['total' => 2, 'rows' => [
    ['alarm_time' => '2026-10-03 15:52:42', 'level_des' => 'Failed', 'solved_des' => 'Pending', 'description' => 'Failed to delete time point <span>x</span>'],
    ['alarm_time' => '2026-09-03 15:52:42', 'description' => 'old'],
]])];
$r = $src($t)->systemAlarms($from, $to);
check('systemAlarms: period and plain text', count($r['rows']) === 1 && $r['rows'][0]['message'] === 'Failed to delete time point x' && $r['rows'][0]['status'] === 'Pending' && $r['rows'][0]['kind'] === 'sysalarm');

// --- copy runs: per copy/replica job, flattened per item, "(Platform)" suffix stripped
$t = new RouteT();
$t->routes['GET /jobs'] = [okd(['total' => 3, 'rows' => [
    ['job_name' => 'J', 'job_uuid' => 'ua', 'job_type' => 'Backup'],
    ['job_name' => 'COPY-1', 'job_uuid' => 'uc', 'job_type' => 'Backup Copy'],
    ['job_name' => 'REP-1', 'job_uuid' => 'ur', 'job_type' => 'Replication'],
]])];
$t->routes['GET /copy/job/history'] = [
    okd(['total' => 2, 'rows' => [
        ['status' => ' success', 'start_time' => '2026-10-02 14:54:35', 'end_time' => '2026-10-02 16:07:27', 'details' => ['info' => [
            ['item_name' => 'VM-A(Some Platform)', 'timepoint_count' => 72, 'item_status' => ' success', 'valid_size' => '843GB', 'error_code' => ''],
            ['item_name' => 'VM-B', 'item_status' => 'failed', 'error_code' => 'E9'],
        ]]],
        ['status' => 'success', 'start_time' => '2026-09-02 14:54:35', 'details' => ['info' => [['item_name' => 'VM-OLD']]]],
    ]]),
    okd(['total' => 0, 'rows' => []]),
];
$r = $src($t)->copyRuns($from, $to);
check('copyRuns: one row per item in the period', array_column($r['rows'], 'vm') === ['VM-A', 'VM-B'], json_encode($r));
check('copyRuns: fields', $r['rows'][0]['job'] === 'COPY-1' && $r['rows'][0]['status'] === 'success' && $r['rows'][0]['item_status'] === 'success'
    && $r['rows'][0]['restore_points'] === 72 && $r['rows'][0]['time'] === '2026-10-02 14:54:35' && $r['rows'][1]['error'] === 'E9' && $r['rows'][0]['kind'] === 'copy');
$copyCalls = array_map(static fn ($c) => query($c['url'])['task_uuid'] ?? '', $t->to('GET', '/copy/job/history'));
check('copyRuns: asked for copy and replica jobs only', $copyCalls === ['uc', 'ur']);

// --- one refused endpoint: that method's error only
$t = new RouteT();
$r = $src($t)->systemAlarms($from, $to);
check('refused: error set, rows empty, no throw', $r['rows'] === [] && str_contains($r['error'], '/alarm/system') && !str_contains($r['error'], 'K'));
$t = new RouteT();
$t->routes['GET /jobs'] = [okd(['total' => 0, 'rows' => []])];
check('copyRuns: no copy job -> empty, no error', $src($t)->copyRuns($from, $to) === ['rows' => [], 'error' => '']);

// --- backup targets: storages (10 = tape group, 6 = NFS), tape jobs per group, libraries
$t = new RouteT();
$t->routes['GET /backupmanager/report/storage'] = [okd(['total' => 2, 'rows' => [
    ['storage_nickname' => 'Tape_Group_2', 'storage_type' => 10, 'total' => '21.83TB', 'rest' => '10.31TB', 'used' => '11.52TB', 'storage_status' => 1, 'node_name' => 'NODE-1'],
    ['storage_nickname' => 'NFS Share1', 'storage_type' => 6, 'total' => '5TB', 'storage_status' => 0],
]])];
$t->routes['GET /tapes/job'] = [okd(['total' => 2, 'rows' => [
    ['job_name' => 'WEEKLY COPY-A', 'group_name' => 'Tape_Group_2'], ['job_name' => 'WEEKLY COPY-B', 'group_name' => 'tape_group_2'],
]])];
$t->routes['GET /tapes'] = [okd(['total' => 1, 'rows' => [['id' => 4, 'name' => 'LIB-1', 'lib_name' => 'Scalar i3', 'vendor' => 'QUANTUM', 'slot_num' => 20]]])];
$r = $src($t)->targets();
check('targets: storages then libraries', array_column($r['rows'], 'kind') === ['tape', 'nas', 'tape library'], json_encode($r));
check('targets: tape group with its jobs, capacity, online', $r['rows'][0]['jobs'] === 'WEEKLY COPY-A, WEEKLY COPY-B' && $r['rows'][0]['free'] === '10.31TB' && $r['rows'][0]['online'] === 'yes'
    && $r['rows'][1]['online'] === 'no' && $r['rows'][2]['detail'] === '20 slots');
$t = new RouteT();
$t->routes['GET /backupmanager/report/storage'] = [okd(['total' => 1, 'rows' => [['storage_nickname' => 'D1', 'storage_type' => 1]]])];
check('targets: no tape module -> storages still listed', $src($t)->targets()['rows'][0]['type'] === 'Storage type 1');

done();
