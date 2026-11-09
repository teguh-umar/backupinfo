<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Source/Run.php';
require BI_ROOT . '/src/Source/Time.php';
require BI_ROOT . '/src/Veeam/RunMapper.php';

use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Source\Time;
use GlpiPlugin\Backupinfo\Veeam\RunMapper;

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

done();
