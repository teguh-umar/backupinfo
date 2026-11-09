<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Source/Run.php';
require BI_ROOT . '/src/Source/Time.php';
require BI_ROOT . '/src/Source/BackupObject.php';
require BI_ROOT . '/src/Source/Event.php';
require BI_ROOT . '/src/Source/Target.php';
require BI_ROOT . '/src/Veeam/RunMapper.php';
require BI_ROOT . '/src/Veeam/ObjectMapper.php';
require BI_ROOT . '/src/Veeam/TargetMapper.php';
require BI_ROOT . '/src/Veeam/EventMapper.php';

use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Source\Time;
use GlpiPlugin\Backupinfo\Source\BackupObject;
use GlpiPlugin\Backupinfo\Source\Event;
use GlpiPlugin\Backupinfo\Source\Target;
use GlpiPlugin\Backupinfo\Veeam\RunMapper;
use GlpiPlugin\Backupinfo\Veeam\ObjectMapper;
use GlpiPlugin\Backupinfo\Veeam\TargetMapper;
use GlpiPlugin\Backupinfo\Veeam\EventMapper;

date_default_timezone_set('Asia/Jakarta');

// ------------------------------------------------------------------
// RunMapper::status
// ------------------------------------------------------------------
check('status: Success is success', RunMapper::status(['result' => 'Success', 'isCanceled' => false]) === Run::SUCCESS);
check('status: Warning is abnormal', RunMapper::status(['result' => 'Warning', 'isCanceled' => false]) === Run::ABNORMAL);
check('status: Failed is failed', RunMapper::status(['result' => 'Failed', 'isCanceled' => false]) === Run::FAILED);
check('status: Failed with isCanceled is failed', RunMapper::status(['result' => 'Failed', 'isCanceled' => true]) === Run::FAILED);
check('status: Success with isCanceled is aborted', RunMapper::status(['result' => 'Success', 'isCanceled' => true]) === Run::ABORTED);
check('status: None with isCanceled is aborted', RunMapper::status(['result' => 'None', 'isCanceled' => true]) === Run::ABORTED);
check('status: Stopped None is abnormal', RunMapper::status(['result' => 'None', 'isCanceled' => false]) === Run::ABNORMAL);
check('status: Bogus is abnormal', RunMapper::status(['result' => 'Bogus', 'isCanceled' => false]) === Run::ABNORMAL);
check('status: missing result is abnormal', RunMapper::status(['isCanceled' => false]) === Run::ABNORMAL);

// ------------------------------------------------------------------
// RunMapper::map
// ------------------------------------------------------------------
$full = [
    'id'          => 's-1',
    'name'        => 'JOB-DAILY',
    'jobId'       => 'j-1',
    'creationTime'=> '2026-10-31T17:00:00Z',
    'endTime'     => '2026-10-31T18:00:00Z',
    'state'       => 'Stopped',
    'result'      => ['result' => 'Success', 'isCanceled' => false],
    'isCanceled'  => false,
];
$run = RunMapper::map($full);
check('map: full fixture not null', $run !== null);
if ($run !== null) {
    check('map: historyUuid', $run->historyUuid === 's-1');
    check('map: jobName', $run->jobName === 'JOB-DAILY');
    check('map: jobUuid', $run->jobUuid === 'j-1');
    check('map: start (Z -> Jakarta)', $run->start === '2026-11-01 00:00:00');
    check('map: finish (Z -> Jakarta)', $run->finish === '2026-11-01 01:00:00');
    check('map: status success', $run->status === Run::SUCCESS);
    check('map: statusText cut to 64', $run->statusText === 'Success');
    check('map: sizeText empty', $run->sizeText === '');
    check('map: durationS 3600', $run->durationS === 3600);
}

// state: Working gives null
check('map: state Working is null', RunMapper::map(['id' => 'x', 'state' => 'Working']) === null);

// missing state gives null
check('map: missing state is null', RunMapper::map(['id' => 'x']) === null);

// missing endTime -> finish null, durationS null
$noEnd = ['id' => 's2', 'name' => 'J', 'jobId' => 'j2', 'creationTime' => '2026-10-31T17:00:00Z',
    'endTime' => null, 'state' => 'Stopped', 'result' => ['result' => 'Success']];
$r2 = RunMapper::map($noEnd);
check('map: missing endTime finish null', $r2 !== null && $r2->finish === null);
check('map: missing endTime durationS null', $r2 !== null && $r2->durationS === null);

// non-array gives null
check('map: non-array is null', RunMapper::map('not an array') === null);

// empty id gives null
check('map: empty id is null', RunMapper::map(['id' => '', 'state' => 'Stopped']) === null);

// state not Stopped (case-insensitive) gives null
check('map: state Working is null', RunMapper::map(['id' => 'x', 'state' => 'Working']) === null);
// state stopped (lowercase) == Stopped case-insensitive -> stored
$rStop = RunMapper::map(['id' => 'xs', 'name' => 'J', 'jobId' => 'j', 'state' => 'stopped', 'result' => ['result' => 'Success']]);
check('map: state stopped lowercase stored', $rStop !== null && $rStop->status === 'success');

// ------------------------------------------------------------------
// RunMapper::item
// ------------------------------------------------------------------
check('item: with name', RunMapper::item(['name' => 'vm-app-01', 'result' => ['result' => 'Failed', 'message' => 'Disk full']])
    === ['vm_name' => 'vm-app-01', 'status' => 'failed', 'error' => 'Disk full']);
check('item: no name gives null', RunMapper::item(['result' => ['result' => 'Failed']]) === null);
check('item: empty name gives null', RunMapper::item(['name' => '', 'result' => ['result' => 'Failed']]) === null);
check('item: non-array gives null', RunMapper::item('x') === null);

// ------------------------------------------------------------------
// RunMapper::reason
// ------------------------------------------------------------------
$session = ['result' => ['result' => 'Success', 'message' => 'session msg']];
check('reason: prefers Failed task', RunMapper::reason([
    ['result' => ['result' => 'Warning', 'message' => 'warn']],
    ['result' => ['result' => 'Failed', 'message' => 'failed reason']],
], $session) === 'failed reason');
check('reason: then Warning task', RunMapper::reason([
    ['result' => ['result' => 'Warning', 'message' => 'warn reason']],
    ['result' => ['result' => 'Success', 'message' => 'ok']],
], $session) === 'warn reason');
check('reason: then session message', RunMapper::reason([
    ['result' => ['result' => 'Success', 'message' => 'task ok']],
], $session) === 'session msg');
check('reason: else empty string', RunMapper::reason([], ['result' => ['result' => 'Success']]) === '');

// ------------------------------------------------------------------
// ObjectMapper::newestPoints
// ------------------------------------------------------------------
$pts = [
    ['name' => 'vm-app-01', 'creationTime' => '2026-10-31T17:00:00Z', 'backupId' => 'b-old'],
    ['name' => 'vm-app-01', 'creationTime' => '2026-11-01T00:00:00Z', 'backupId' => 'b-new'],
    ['name' => 'VM-APP-01', 'creationTime' => '2026-10-31T17:30:00Z', 'backupId' => 'b-case'],
    ['name' => 'vm-app-01', 'creationTime' => 'n/a', 'backupId' => 'b-bad'],
    ['creationTime' => '2026-10-31T17:00:00Z', 'backupId' => 'b-noname'],
];
$np = ObjectMapper::newestPoints($pts);
check('newestPoints: newer wins', $np['vm-app-01']['time'] === Time::epoch('2026-11-01T00:00:00Z') && $np['vm-app-01']['backupId'] === 'b-new');
check('newestPoints: case-insensitive name', isset($np['vm-app-01']));
check('newestPoints: unparseable time ignored', $np['vm-app-01']['time'] !== Time::epoch('2026-10-31T17:30:00Z'));
check('newestPoints: no name ignored', !isset($np['']));

// ------------------------------------------------------------------
// ObjectMapper::map
// ------------------------------------------------------------------
$newest = ['vm-app-01' => ['time' => Time::epoch('2026-11-01T00:00:00Z'), 'backupId' => 'b-new']];
$backupNames = ['b-new' => 'JOB-DAILY'];
$objs = [
    ['id' => 'o1', 'name' => 'vm-app-01', 'type' => 'VM', 'restorePointsCount' => 7, 'backupId' => 'b-new'],
    ['id' => 'o2', 'name' => 'vm-db-01', 'type' => 'VM', 'restorePointsCount' => 0, 'backupId' => 'b-none'],
    ['id' => 'o3', 'name' => 'vm-agent-01', 'type' => 'Computer', 'restorePointsCount' => 3, 'backupId' => 'b-new'],
    ['name' => 'no-id', 'type' => 'VM', 'restorePointsCount' => 1],
    ['id' => 'o4', 'name' => '', 'type' => 'VM', 'restorePointsCount' => 1],
];
$mapped = ObjectMapper::map($objs, $newest, $backupNames);
check('map: 3 objects (no-id and empty-name skipped)', count($mapped) === 3, json_encode(array_map(fn($o) => $o->name, $mapped)));
$byName = [];
foreach ($mapped as $o) { $byName[$o->name] = $o; }
check('map: vm-app-01 lastBackup set + jobName', $byName['vm-app-01']->lastBackup === Time::epoch('2026-11-01T00:00:00Z') && $byName['vm-app-01']->jobName === 'JOB-DAILY');
check('map: vm-db-01 lastBackup null (count 0)', $byName['vm-db-01']->lastBackup === null);
check('map: Computer kind -> agent', $byName['vm-agent-01']->kind === 'agent');
check('map: VM kind -> vm', $byName['vm-app-01']->kind === 'vm');
check('map: sourceKey veeam: prefix', $byName['vm-app-01']->sourceKey === 'veeam:o1');
check('map: ip empty', $byName['vm-app-01']->ip === '');
check('map: dataSize empty', $byName['vm-app-01']->dataSize === '');
check('map: abnormalChain false', $byName['vm-app-01']->abnormalChain === false);
check('map: sourceDeleted false', $byName['vm-app-01']->sourceDeleted === false);
check('map: scheduleHours null', $byName['vm-app-01']->scheduleHours === null);
check('map: timepointUuid null', $byName['vm-app-01']->timepointUuid === null);

// count 0 + stray point -> lastBackup null
$newest2 = ['vm-db-01' => ['time' => Time::epoch('2026-11-01T00:00:00Z'), 'backupId' => 'b-stray']];
$mapped2 = ObjectMapper::map([['id' => 'o2', 'name' => 'vm-db-01', 'type' => 'VM', 'restorePointsCount' => 0]], $newest2, ['b-stray' => 'J']);
check('map: count 0 overrides point -> lastBackup null', $mapped2[0]->lastBackup === null);

// ------------------------------------------------------------------
// TargetMapper::map
// ------------------------------------------------------------------
$repos = [
    ['type' => 'LinuxHardened', 'hostName' => 'node-1', 'capacityGB' => 512.5, 'usedSpaceGB' => 100, 'freeGB' => 412.5, 'isOnline' => true, 'path' => '/mnt/backup'],
    ['type' => 'Smb', 'hostName' => 'node-2', 'capacityGB' => 1000, 'usedSpaceGB' => 200, 'freeGB' => 800, 'isOnline' => false, 'path' => '\\\\nas'],
    ['type' => 'AmazonS3', 'hostName' => 'node-3', 'capacityGB' => 2048, 'usedSpaceGB' => 500, 'path' => 's3://bucket'],
    ['type' => 'NewThing', 'hostName' => 'node-4', 'capacityGB' => 100, 'usedSpaceGB' => 10, 'freeGB' => 90, 'isOnline' => true, 'path' => '/x'],
    ['type' => 'ExtendableRepository', 'hostName' => 'sobr', 'capacityGB' => 9999, 'usedSpaceGB' => 100, 'freeGB' => 9899, 'isOnline' => true, 'path' => '/sobr'],
    ['type' => 'WinLocal', 'hostName' => 'node-5', 'capacityGB' => 'abc', 'usedSpaceGB' => null, 'freeGB' => null, 'isOnline' => null, 'path' => '/local'],
];
$tgts = TargetMapper::map($repos);
check('targets: 5 rows (ExtendableRepository skipped)', count($tgts) === 5);
$byType = [];
foreach ($tgts as $t) { $byType[$t['type']] = $t; }
check('targets: LinuxHardened kind disk', $byType['LinuxHardened']['kind'] === 'disk');
check('targets: Smb kind nas', $byType['Smb']['kind'] === 'nas');
check('targets: AmazonS3 kind cloud', $byType['AmazonS3']['kind'] === 'cloud');
check('targets: NewThing kind empty', $byType['NewThing']['kind'] === '');
check('targets: NewThing type kept', $byType['NewThing']['type'] === 'NewThing');
check('targets: capacityGB 512.5 kept', $byType['LinuxHardened']['total'] === '512.5 GB');
check('targets: missing freeGB empty', $byType['AmazonS3']['free'] === '');
check('targets: isOnline false -> no', $byType['Smb']['online'] === 'no');
check('targets: missing isOnline empty', $byType['AmazonS3']['online'] === '');
check('targets: detail path', $byType['LinuxHardened']['detail'] === '/mnt/backup');
check('targets: node hostName', $byType['LinuxHardened']['node'] === 'node-1');
check('targets: jobs empty', $byType['LinuxHardened']['jobs'] === '');

// ------------------------------------------------------------------
// EventMapper::authorization
// ------------------------------------------------------------------
$authRow = ['id' => 'e1', 'creationTime' => '2026-11-01 01:00:00', 'processedTime' => '2026-11-01 01:05:00',
    'createdBy' => 'admin', 'processedBy' => 'operator', 'name' => 'CreateJob', 'description' => 'job created',
    'state' => 'Pending'];
$evAuth = EventMapper::authorization($authRow);
check('auth: Event::make syslog', $evAuth['kind'] === 'syslog');
check('auth: time', $evAuth['time'] === '2026-11-01 01:00:00');
check('auth: end_time', $evAuth['end_time'] === '2026-11-01 01:05:00');
check('auth: ref', $evAuth['ref'] === 'e1');
check('auth: user with processedBy', $evAuth['user'] === 'admin → operator');
check('auth: level warning for Pending', $evAuth['level'] === 'warning');
check('auth: action name', $evAuth['action'] === 'CreateJob');
check('auth: message description', $evAuth['message'] === 'job created');
check('auth: status state', $evAuth['status'] === 'Pending');
check('auth: source_ip empty', $evAuth['source_ip'] === '');

// Rejected -> warning
$authRow2 = $authRow; $authRow2['state'] = 'Rejected';
$evAuth2 = EventMapper::authorization($authRow2);
check('auth: Rejected level warning', $evAuth2['level'] === 'warning');

// ------------------------------------------------------------------
// EventMapper::malware
// ------------------------------------------------------------------
$malRow = ['id' => 'm1', 'detectionTimeUtc' => '2026-11-01 02:00:00', 'createdBy' => 'secadmin',
    'type' => 'File', 'details' => 'EICAR', 'machine' => ['displayName' => 'vm-app-01'],
    'state' => 'Infected', 'severity' => 'High'];
$evMal = EventMapper::malware($malRow);
check('malware: Event::make sysalarm', $evMal['kind'] === 'sysalarm');
check('malware: time', $evMal['time'] === '2026-11-01 02:00:00');
check('malware: ref', $evMal['ref'] === 'm1');
check('malware: user', $evMal['user'] === 'secadmin');
check('malware: level critical for Infected', $evMal['level'] === 'critical');
check('malware: action type', $evMal['action'] === 'File');
check('malware: message details + machine', $evMal['message'] === 'EICAR · vm-app-01');
check('malware: vm', $evMal['vm'] === 'vm-app-01');
check('malware: status state', $evMal['status'] === 'Infected');
check('malware: item_status severity', $evMal['item_status'] === 'High');
check('malware: source_ip empty', $evMal['source_ip'] === '');

// ------------------------------------------------------------------
// EventMapper::copy
// ------------------------------------------------------------------
$copySession = ['id' => 'c1', 'name' => 'COPY-JOB', 'creationTime' => '2026-11-01 03:00:00',
    'endTime' => '2026-11-01 04:00:00', 'result' => ['result' => 'Failed', 'isCanceled' => false]];
$copyItem = ['vm_name' => 'vm-app-01', 'status' => 'failed', 'error' => 'target full'];
$evCopy = EventMapper::copy($copySession, $copyItem);
check('copy: Event::make copy', $evCopy['kind'] === 'copy');
check('copy: time from session', $evCopy['time'] === '2026-11-01 03:00:00');
check('copy: end_time from session', $evCopy['end_time'] === '2026-11-01 04:00:00');
check('copy: ref session id', $evCopy['ref'] === 'c1');
check('copy: job session name', $evCopy['job'] === 'COPY-JOB');
check('copy: vm from item', $evCopy['vm'] === 'vm-app-01');
check('copy: status from RunMapper', $evCopy['status'] === 'failed');
check('copy: item_status from item', $evCopy['item_status'] === 'failed');
check('copy: error from item', $evCopy['error'] === 'target full');

done();
