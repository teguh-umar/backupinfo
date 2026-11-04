<?php

require __DIR__ . '/bootstrap.php';

foreach ([
    'Text', 'Source/BackupObject', 'Source/Run', 'Source/BackupSource', 'Source/ProbeableSource',
    'Source/AuditProbeableSource', 'Source/RunHistorySource', 'Source/AuditSource', 'Source/PartialFetch',
    'Vinchin/Signer', 'Vinchin/Transport', 'Vinchin/Client', 'Vinchin/ItemMapper', 'Vinchin/JobHistoryMapper',
    'Vinchin/ScheduleMapper', 'Vinchin/RunMapper', 'Vinchin/VinchinSource', 'Sync/VerifyImport',
] as $f) {
    require BI_ROOT . "/src/$f.php";
}

use GlpiPlugin\Backupinfo\Sync\VerifyImport;
use GlpiPlugin\Backupinfo\Vinchin\Client;
use GlpiPlugin\Backupinfo\Vinchin\ItemMapper;
use GlpiPlugin\Backupinfo\Vinchin\VinchinSource;

require __DIR__ . '/fakes.php';

$read = static fn (string $rel): string => (string) @file_get_contents(BI_ROOT . '/' . $rel);

// --- the items list keeps the newest restore point per VM
$objs = ItemMapper::map([
    ['item_name' => 'VM-A', 'module_type_des' => 'Virtualization', 'last_time' => '2026-10-01 01:00:00', 'timepoint_uuid' => 'tp-old', 'task_name' => 'J'],
    ['item_name' => 'VM-A', 'module_type_des' => 'Virtualization', 'last_time' => '2026-10-02 01:00:00', 'timepoint_uuid' => 'tp-new', 'task_name' => 'J'],
    ['item_name' => 'VM-B', 'module_type_des' => 'Virtualization', 'last_time' => '2026-10-02 01:00:00', 'task_name' => 'J'],
]);
$byName = [];
foreach ($objs as $o) {
    $byName[$o->name] = $o;
}
check('items: newest timepoint per VM', ($byName['VM-A']->timepointUuid ?? null) === 'tp-new', json_encode(array_map(static fn ($o) => $o->timepointUuid, $objs)));
check('items: no timepoint -> null', $byName['VM-B']->timepointUuid === null);
check('withSchedule keeps the timepoint', $byName['VM-A']->withSchedule(24)->timepointUuid === 'tp-new');

// --- verify rows -> restore-test inputs
$row = ['timepoint' => '2026-10-02 01:00:00', 'timepoint_uuid' => 'tp-new', 'start_time' => '2026-10-02 03:00:00', 'end_time' => '2026-10-02 03:20:00',
    'ping_status' => 1, 'ping_status_des' => 'Success', 'heartbeat_status_des' => 'Success', 'screen_status_des' => '<b>Success</b>', 'integrity_check_status_des' => 'Success'];
$in = VerifyImport::toInput($row, 11);
check('verify: pass when every reported check succeeded', $in !== null && $in['result'] === 'pass' && $in['computers_id'] === 11);
check('verify: method, source, ref, date', $in['method'] === 'vinchin_verify' && $in['source'] === 'vinchin' && $in['source_ref'] === 'tp-new@2026-10-02 03:00:00' && $in['date'] === '2026-10-02');
check('verify: comment lists the checks in plain text', str_contains($in['comment'], 'Boot screenshot: Success') && str_contains($in['comment'], 'restore point 2026-10-02 01:00:00'));
check('verify: one check failed with others ok -> partial', VerifyImport::toInput(['ping_status_des' => 'Failed'] + $row, 11)['result'] === 'partial');
check('verify: all reported checks failed -> fail', VerifyImport::toInput(['ping_status_des' => 'Failed', 'heartbeat_status_des' => 'Failed', 'screen_status_des' => 'Failed', 'integrity_check_status_des' => 'Error'] + $row, 11)['result'] === 'fail');
check('verify: no readable check -> skipped, never guessed', VerifyImport::toInput(['timepoint_uuid' => 'tp', 'start_time' => '2026-10-02 03:00:00', 'ping_status_des' => 'Not enabled'], 11) === null);
check('verify: no time -> skipped', VerifyImport::toInput(['ping_status_des' => 'Success', 'timepoint_uuid' => 'tp'], 11) === null);
check('verify: end time used for the date', VerifyImport::toInput(['end_time' => '2026-10-03 00:10:00'] + $row, 11)['date'] === '2026-10-03');

// --- round-robin batches
check('batch: first slice and next cursor', VerifyImport::batch(['a', 'b', 'c', 'd', 'e'], 0, 2) === [['a', 'b'], 2]);
check('batch: wraps around', VerifyImport::batch(['a', 'b', 'c', 'd', 'e'], 4, 2) === [['e', 'a'], 1]);
check('batch: cursor past the end restarts', VerifyImport::batch(['a', 'b'], 9, 5) === [['a', 'b'], 0]);
check('batch: empty', VerifyImport::batch([], 3, 5) === [[], 0]);

// --- VinchinSource::verifications
$src = static fn (RouteT $t): VinchinSource => new VinchinSource(new Client('h', $t, 'K'), false, fn () => []);
$t = new RouteT();
$t->routes['GET /backup_data/verify_history'] = [okd(['total' => 1, 'rows' => [$row]])];
$v = $src($t)->verifications('tp-new');
check('verifications: rows for the restore point', $v['rows'] === [$row] && $v['error'] === '' && (query($t->calls[0]['url'])['timepoint_uuid'] ?? '') === 'tp-new');
$t = new RouteT();
check('verifications: refused -> error, no throw', ($src($t)->verifications('x')['error'] ?? '') !== '');

// --- audit probe samples the per-run detail of the first failed run
$t = new RouteT();
$t->routes['GET /jobs/history'] = [rows([['history_uuid' => 'uuid-1', 'job_name' => 'J', 'job_status' => 'Failed']])];
$t->routes['GET /jobs/history/uuid-1'] = [okd(['list' => [['vm_name' => 'VM-A', 'task_status' => 'Failed']]])];
$s = $src($t)->probeAudit(5, '');
check('probe: /jobs/history/{uuid} sampled with the first failed run', isset($s['raw']['/jobs/history/{uuid}']) && $s['raw']['/jobs/history/{uuid}'][0]['vm_name'] === 'VM-A');
$t = new RouteT();
$t->routes['GET /jobs/history'] = [rows([])];
$s = $src($t)->probeAudit(5, '');
check('probe: no failed run -> skipped with a note', in_array('/jobs/history/{uuid}: skipped, no failed run in the sample', $s['errors'], true));

// --- Cron and RestoreTest import wiring
$cron = $read('src/Cron.php');
check('cron: verification import after the runs', strpos($cron, 'self::storeRuns(') < strpos($cron, 'self::importVerifications(') && str_contains($cron, 'VerifyImport::batch('));
check('cron: only in-scope objects', str_contains($cron, 'mandatoryIds()') && str_contains($cron, "'timepoint_uuid'"));
check('cron: import never fails the sync', (bool) preg_match('/function importVerifications\(.*?catch \(\\\\Throwable \$e\)/s', $cron));
$rt = $read('src/RestoreTest.php');
check('import flag cannot come from a form (static, not input)', str_contains($rt, 'private static bool $importing = false;') && str_contains($rt, 'if (self::$importing) {'));
check('import dedupes on (source, source_ref)', str_contains($rt, "'source_ref' => \$input['source_ref']"));

done();
