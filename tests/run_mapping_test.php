<?php

require __DIR__ . '/bootstrap.php';

foreach (['Text', 'Source/BackupObject', 'Source/Run', 'Vinchin/ItemMapper', 'Vinchin/JobHistoryMapper',
    'Vinchin/RunMapper', 'Vinchin/AlarmJoin'] as $f) {
    require BI_ROOT . "/src/$f.php";
}

use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Text;
use GlpiPlugin\Backupinfo\Vinchin\AlarmJoin;
use GlpiPlugin\Backupinfo\Vinchin\RunMapper;

// --- Text::plain
check('plain: tags removed, entities decoded', Text::plain('Failed on <span class="x">VM-A</span> &quot;ok&quot;', 100) === 'Failed on VM-A "ok"');
check('plain: whitespace collapsed and trimmed', Text::plain("  a \n\t b  ", 100) === 'a b');
check('plain: multibyte cut', Text::plain('ééééé', 3) === 'ééé');
check('plain: empty', Text::plain('', 10) === '');
check('plain: escaped quotes in attributes', Text::plain('x <span  class=\"font-green\">VM-A</span> y', 100) === 'x VM-A y');

// --- status mapping from the TEXT (job_status_value is not the doc's 1-4)
$cases = [
    'Failed' => 'failed', ' Failed' => 'failed', 'Incomplete' => 'failed', 'Error' => 'failed',
    'Terminated' => 'aborted', 'Aborted' => 'aborted', 'Canceled' => 'aborted', 'Stopped' => 'aborted',
    'Abnormal' => 'abnormal', 'Success' => 'success', ' success' => 'success', 'Completed' => 'success',
    'Running' => 'abnormal', '' => 'abnormal', '失败' => 'failed', '成功' => 'success',
];
foreach ($cases as $text => $want) {
    check("status '$text' -> $want", RunMapper::status($text) === $want, RunMapper::status($text));
}

// --- RunMapper::map
$row = [
    'history_uuid' => 'uuid-1', 'job_name' => 'JOB-1', 'job_uuid' => 7, 'job_type' => ' Backup',
    'start_time' => '2026-07-31 00:05:01', 'finish_time' => '2026-07-31 00:05:09', 'job_status' => 'Failed',
    'job_status_value' => 3005, 'all_size' => '0B', 'execution_duration' => '01:02:03',
];
$r = RunMapper::map($row);
check('map: run built', $r instanceof Run);
check('map: fields', $r->historyUuid === 'uuid-1' && $r->jobName === 'JOB-1' && $r->jobUuid === '7'
    && $r->start === '2026-07-31 00:05:01' && $r->finish === '2026-07-31 00:05:09' && $r->status === 'failed'
    && $r->statusText === 'Failed' && $r->sizeText === '0B' && $r->durationS === 3723);
check('map: no uuid -> null', RunMapper::map(['job_name' => 'J', 'job_status' => 'Success']) === null);
check('map: restore job skipped', RunMapper::map(['history_uuid' => 'u', 'job_type' => 'Restore', 'job_status' => 'Success']) === null);
check('map: verify job skipped', RunMapper::map(['history_uuid' => 'u', 'job_type' => 'Backup Verification', 'job_status' => 'Success']) === null);
check('map: bad duration -> null', RunMapper::map(['history_uuid' => 'u', 'execution_duration' => 'n/a'])->durationS === null);
check('map: ISO time normalised, not shifted', RunMapper::map(['history_uuid' => 'u', 'start_time' => '2026-07-31T10:00'])->start === '2026-07-31 10:00:00');
check('map: garbage time -> null', RunMapper::map(['history_uuid' => 'u', 'start_time' => '--'])->start === null);
check('map: transfer size used when all_size missing', RunMapper::map(['history_uuid' => 'u', 'transfer_size' => '5GB'])->sizeText === '5GB');
check('map: non-array -> null', RunMapper::map('x') === null);

// --- AlarmJoin
$failed = [
    RunMapper::map(['history_uuid' => 'u1', 'job_name' => 'JOB-1', 'job_status' => 'Failed', 'finish_time' => '2026-07-31 00:05:09']),
    RunMapper::map(['history_uuid' => 'u2', 'job_name' => 'JOB-2', 'job_status' => 'Failed', 'finish_time' => '2026-07-31 01:00:00']),
    RunMapper::map(['history_uuid' => 'u3', 'job_name' => 'JOB-3', 'job_status' => 'Failed', 'finish_time' => '2026-07-31 02:00:00']),
];
$alarms = [
    ['job_name' => 'job-1 ', 'alarm_time' => '2026-07-31 00:05:09', 'description' => 'Failed to prepare <span>VM-A</span>'],
    ['job_name' => 'JOB-2', 'alarm_time' => '2026-07-31 01:01:01', 'description' => 'too late'],
    ['job_name' => 'JOB-3', 'alarm_time' => '2026-07-31 02:00:50', 'description' => 'far'],
    ['job_name' => 'JOB-3', 'alarm_time' => '2026-07-31 01:59:58', 'description' => 'near'],
    ['job_name' => 'OTHER', 'alarm_time' => '2026-07-31 00:05:09', 'description' => 'other job'],
];
$reasons = AlarmJoin::reasons($failed, $alarms);
check('join: exact second, case/space-insensitive job name, HTML stripped', ($reasons['u1'] ?? '') === 'Failed to prepare VM-A');
check('join: 61 s apart -> no reason', !isset($reasons['u2']));
check('join: nearest alarm wins', ($reasons['u3'] ?? '') === 'near');
check('join: no alarms -> empty', AlarmJoin::reasons($failed, []) === []);
check('join: reason capped at 1000', mb_strlen(AlarmJoin::reasons([$failed[0]], [['job_name' => 'JOB-1', 'alarm_time' => '2026-07-31 00:05:09', 'description' => str_repeat('x', 1500)]])['u1']) === 1000);

done();
