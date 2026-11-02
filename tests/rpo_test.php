<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Rpo.php';
require BI_ROOT . '/src/Status.php';
require BI_ROOT . '/src/Vinchin/ScheduleMapper.php';

use GlpiPlugin\Backupinfo\Rpo;
use GlpiPlugin\Backupinfo\Status;
use GlpiPlugin\Backupinfo\Vinchin\ScheduleMapper;

$now = 1800000000;
$h = 3600;

// --- Rpo: schedule + grace when the job schedule is known, else the global RPO
$rpo = new Rpo(26, 24);
check('known schedule: schedule + grace', $rpo->hoursFor(['schedule_hours' => 336]) === 360);
check('known schedule: source "schedule"', $rpo->sourceFor(['schedule_hours' => 336]) === Rpo::SOURCE_SCHEDULE);
check('unknown schedule (null): global', $rpo->hoursFor(['schedule_hours' => null]) === 26 && $rpo->sourceFor(['schedule_hours' => null]) === Rpo::SOURCE_GLOBAL);
check('unknown schedule (missing, "", 0, "0"): global',
    $rpo->hoursFor([]) === 26 && $rpo->hoursFor(['schedule_hours' => '']) === 26
    && $rpo->hoursFor(['schedule_hours' => 0]) === 26 && $rpo->hoursFor(['schedule_hours' => '0']) === 26);
check('DB string value is read as int', $rpo->hoursFor(['schedule_hours' => '168']) === 192);
check('Rpo::of(int) keeps schedules, default grace', Rpo::of(26)->hoursFor(['schedule_hours' => 24]) === 24 + Rpo::DEFAULT_GRACE);
check('Rpo::of(Rpo) is the same object', Rpo::of($rpo) === $rpo);
check('fromValues: bad values fall back to defaults',
    Rpo::fromValues(['rpo_hours' => 0, 'rpo_grace_hours' => -1])->globalHours === Rpo::DEFAULT_GLOBAL
    && Rpo::fromValues(['rpo_hours' => 0, 'rpo_grace_hours' => -1])->graceHours === Rpo::DEFAULT_GRACE);
check('fromValues: grace 0 is allowed', Rpo::fromValues(['rpo_hours' => 30, 'rpo_grace_hours' => '0'])->graceHours === 0);
check('fromValues: missing keys use defaults', Rpo::fromValues([])->globalHours === 26 && Rpo::fromValues([])->graceHours === 24);
check('label: schedule', $rpo->label(['schedule_hours' => 336]) === '360 h (schedule 336 h + 24 h)');
check('label: global', $rpo->label([]) === '26 h (global)');

// --- Status with per-object RPO
check('two-week job, 254 h old: protected (user case VM-TEST-02)',
    Status::of([['last_backup' => $now - 254 * $h, 'schedule_hours' => 336]], $rpo, $now) === Status::PROTECTED);
check('two-week job, 361 h old: expired',
    Status::of([['last_backup' => $now - 361 * $h, 'schedule_hours' => 336]], $rpo, $now) === Status::EXPIRED);
check('same 254 h, unknown schedule: expired by the global RPO',
    Status::of([['last_backup' => $now - 254 * $h]], $rpo, $now) === Status::EXPIRED);
check('any object within its own RPO protects the computer',
    Status::of([
        ['last_backup' => $now - 30 * $h, 'schedule_hours' => null],
        ['last_backup' => $now - 100 * $h, 'schedule_hours' => 168],
    ], $rpo, $now) === Status::PROTECTED);
check('int RPO still accepted (global for unknown schedules)', Status::of([['last_backup' => $now - 25 * $h]], 26, $now) === Status::PROTECTED);

// --- ScheduleMapper: time_strategy -> interval hours (Vinchin v9 GET /jobs/{ID})
$d = static fn(array ...$s): array => ['time_strategy' => $s];
check('daily: 24 h', ScheduleMapper::intervalHours($d(['mode' => 2, 'type' => 1])) === 24);
check('weekly, one day: 168 h', ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => 1, 'days' => [5]])) === 168);
check('every two weeks, one day: 336 h', ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => 2, 'days' => [5]])) === 336);
check('weekly Mon + Thu: longest gap 4 days = 96 h', ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => 1, 'days' => [1, 4]])) === 96);
check('weekly, days as strings, Sunday as 7 or 0 is one day', ScheduleMapper::intervalHours($d(['type' => 2, 'days' => ['0', '7']])) === 168);
check('weekly, no days, no frequency: 168 h', ScheduleMapper::intervalHours($d(['type' => 2])) === 168);
check('monthly day 1: 31 days', ScheduleMapper::intervalHours($d(['type' => 3, 'days' => [1]])) === 744);
check('monthly 1 + 15: longest gap 17 days', ScheduleMapper::intervalHours($d(['type' => 3, 'days' => [1, 15]])) === 408);
check('full weekly + incremental daily: the shortest wins', ScheduleMapper::intervalHours($d(['mode' => 1, 'type' => 2, 'days' => [5]], ['mode' => 2, 'type' => 1])) === 24);
// Real Vinchin shape (VM sample 2026-11-02): days is a 7-flag weekday mask, frequency "s2"
$real = ['time_strategy_backup_type' => 'strategy', 'time_strategy' => [[
    'mode' => 1, 'type' => 2, 'days' => [0, 0, 0, 0, 1, 0, 0], 'frequency' => 's2',
    'start_time' => '00:05:00', 'roll_flag' => false, 'roll_interval' => '01:00:00', 'end_time' => '23:59:59',
]]];
check('real sample: one weekday every 2 weeks = 336 h (VM-TEST-02)', ScheduleMapper::intervalHours($real) === 336);
check('weekday mask, two days (Mon + Thu), weekly: 96 h', ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => 's1', 'days' => [0, 1, 0, 0, 1, 0, 0]])) === 96);
check('weekday mask, empty: whole cycle', ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => 's2', 'days' => [0, 0, 0, 0, 0, 0, 0]])) === 336);
check('frequency as plain string "3"', ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => '3', 'days' => [1, 0, 0, 0, 0, 0, 0]])) === 504);
check('month-day mask (31 flags), days 1 and 15: 17 days', ScheduleMapper::intervalHours($d(['type' => 3, 'days' => array_replace(array_fill(0, 31, 0), [0 => 1, 14 => 1])])) === 408);
// Only known frequency shapes (orchestrator 2026-11-02): int, "N", "s<N>"; anything else = unknown
check('frequency "d3": unknown, not 3 weeks', ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => 'd3', 'days' => [0, 0, 0, 0, 1, 0, 0]])) === null);
check('frequency "m1" / "" / "s" / "s2x": unknown', ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => 'm1'])) === null
    && ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => ''])) === null
    && ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => 's'])) === null
    && ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => 's2x'])) === null);
check('frequency "S2" (case) and missing frequency are known', ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => 'S2', 'days' => [1, 0, 0, 0, 0, 0, 0]])) === 336
    && ScheduleMapper::intervalHours($d(['type' => 2, 'days' => [1, 0, 0, 0, 0, 0, 0]])) === 168);
check('one unknown strategy makes the whole schedule unknown (it could be the shorter one)',
    ScheduleMapper::intervalHours($d(['type' => 1], ['type' => 2, 'frequency' => 'x9'])) === null
    && ScheduleMapper::intervalHours($d(['type' => 1], ['type' => 9])) === null);
check('unknown type: null', ScheduleMapper::intervalHours($d(['type' => 9])) === null);
check('no time_strategy: null', ScheduleMapper::intervalHours([]) === null && ScheduleMapper::intervalHours(['time_strategy' => 'x']) === null);
check('frequency is capped (bad value cannot make a huge RPO)', ScheduleMapper::intervalHours($d(['type' => 2, 'frequency' => 999, 'days' => [1]])) === 168 * ScheduleMapper::MAX_WEEKS);

// --- ScheduleMapper::forObject: a VM in several jobs takes the shortest known schedule
$map = ['vm backup_a (weekly)' => 336, 'daily_a' => 24];
check('one job', ScheduleMapper::forObject('VM BACKUP_A (WEEKLY)', $map) === 336);
check('several jobs: shortest', ScheduleMapper::forObject('DAILY_A, VM BACKUP_A (WEEKLY)', $map) === 24);
check('unknown job: null', ScheduleMapper::forObject('OTHER', $map) === null && ScheduleMapper::forObject('', $map) === null);

done();
