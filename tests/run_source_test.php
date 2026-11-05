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

use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Source\RunHistorySource;
use GlpiPlugin\Backupinfo\Vinchin\Client;
use GlpiPlugin\Backupinfo\Vinchin\VinchinSource;

require __DIR__ . '/fakes.php';

function src(RouteT $t, bool $login = false): VinchinSource
{
    return new VinchinSource($login ? new Client('h', $t, '', 'u', 'p') : new Client('h', $t, 'K'), $login, fn () => []);
}
function page(array $uuids, int $total): array
{
    $rows = array_map(static fn ($u) => ['history_uuid' => $u, 'job_name' => 'JOB-1', 'job_type' => 'Backup', 'job_status' => 'Success',
        'start_time' => '2026-10-01 01:00:00'], $uuids);
    return okd(['total' => $total, 'rows' => $rows]);
}

check('VinchinSource is a RunHistorySource', (new \ReflectionClass(VinchinSource::class))->implementsInterface(RunHistorySource::class));

// --- runs(): newest first, stops at a page whose runs are all known
$t = new RouteT();
$t->routes['GET /jobs/history'] = [page(['u5', 'u4'], 6), page(['u3', 'u2'], 6), page(['u1', 'u0'], 6)];
$known = ['u3' => true, 'u2' => true];
$r = src($t)->runs(static fn (array $uuids): bool => array_diff($uuids, array_keys($known)) === [], 10, 2);
$calls = $t->to('GET', '/jobs/history');
check('runs: stopped after the known page', count($calls) === 2, (string) count($calls));
check('runs: Run objects returned for both pages', array_map(static fn (Run $x) => $x->historyUuid, $r['runs']) === ['u5', 'u4', 'u3', 'u2']);
check('runs: newest first by start_time', (query($calls[0]['url'])['sort'] ?? '') === 'start_time' && (query($calls[0]['url'])['order'] ?? '') === 'desc');
check('runs: no errors', $r['errors'] === []);

// --- maxPages respected
$t = new RouteT();
$t->routes['GET /jobs/history'] = [page(['a', 'b'], 100), page(['c', 'd'], 100), page(['e', 'f'], 100)];
$r = src($t)->runs(static fn (): bool => false, 2, 2);
check('runs: maxPages respected', count($t->to('GET', '/jobs/history')) === 2 && count($r['runs']) === 4);

// --- sort refused: retried without sort
$t = new RouteT();
$t->routes['GET /jobs/history'] = [['status' => 200, 'headers' => [], 'body' => json_encode(['success' => false, 'code' => 1, 'message' => 'bad sort'])], page(['x'], 1)];
$r = src($t)->runs(static fn (): bool => false, 5, 100);
check('runs: sort refused -> retried without sort', count($r['runs']) === 1 && !isset(query($t->to('GET', '/jobs/history')[1]['url'])['sort']));

// --- history refused: error line, no throw
$t = new RouteT();
$r = src($t)->runs(static fn (): bool => false, 5, 100);
check('runs: refused -> one error, no runs', $r['runs'] === [] && count($r['errors']) === 1 && str_contains($r['errors'][0], '/jobs/history'));

// --- restore/verify rows and rows without uuid are dropped; duplicates once
$t = new RouteT();
$t->routes['GET /jobs/history'] = [okd(['total' => 4, 'rows' => [
    ['history_uuid' => 'a', 'job_type' => 'Backup', 'job_status' => 'Failed'],
    ['history_uuid' => 'b', 'job_type' => 'Restore', 'job_status' => 'Success'],
    ['history_uuid' => '', 'job_type' => 'Backup', 'job_status' => 'Success'],
    ['history_uuid' => 'a', 'job_type' => 'Backup', 'job_status' => 'Failed'],
]])];
$r = src($t)->runs(static fn (): bool => false, 5, 100);
check('runs: skip restore, empty uuid, duplicate', array_map(static fn (Run $x) => $x->historyUuid, $r['runs']) === ['a']);

// --- login mode logs in once
$t = new RouteT();
$t->routes['POST /login'] = [okd(['access_token' => 'T'])];
$t->routes['GET /jobs/history'] = [page(['a'], 1)];
$s = src($t, true);
$s->runs(static fn (): bool => false, 1, 100);
$s->jobAlarms('2026-01-01 00:00:00');
check('login mode: login once before reading', count($t->to('POST', '/login')) === 1);

// --- runItems(): list rows, info merged, name and status fallbacks
$t = new RouteT();
$t->routes['GET /jobs/history/uuid-1'] = [okd(['list' => [
    ['vm_name' => 'VM-A', 'task_status' => 'Failed', 'error_code' => 'E1'],
    ['info' => ['display_name' => 'VM-B', 'task_status' => 'Success'], 'module_type' => 2],
    ['db_name' => 'DB-1', 'vm_status' => 'Success'],
    ['nothing' => 1],
]])];
$i = src($t)->runItems('uuid-1');
check('runItems: one row per named item', array_column($i['items'], 'vm_name') === ['VM-A', 'VM-B', 'DB-1'], json_encode($i));
check('runItems: status mapped', array_column($i['items'], 'status') === ['failed', 'success', 'success']);
check('runItems: error kept', $i['items'][0]['error'] === 'E1' && $i['error'] === '');
check('runItems: uuid in the path, url-encoded', str_ends_with((string) parse_url($t->calls[0]['url'], PHP_URL_PATH), '/jobs/history/uuid-1'));

$t = new RouteT();
$t->routes['GET /jobs/history/u'] = [okd('a string')];
$i = src($t)->runItems('u');
check('runItems: unknown shape -> [] and an error', $i['items'] === [] && $i['error'] !== '');
$t = new RouteT();
$i = src($t)->runItems('u');
check('runItems: refused -> [] and an error, no throw', $i['items'] === [] && str_contains($i['error'], '/jobs/history/u'));
$t = new RouteT();
$t->routes['GET /jobs/history/u'] = [okd(['list' => []])];
check('runItems: empty list is fine', src($t)->runItems('u') === ['items' => [], 'error' => '']);

// --- jobAlarms(): newest first, stops at $since
$t = new RouteT();
$t->routes['GET /alarm/job'] = [
    okd(['total' => 6, 'rows' => [['alarm_time' => '2026-10-05 00:00:00'], ['alarm_time' => '2026-10-03 00:00:00']]]),
    okd(['total' => 6, 'rows' => [['alarm_time' => '2026-10-02 00:00:00'], ['alarm_time' => '2026-09-01 00:00:00']]]),
    okd(['total' => 6, 'rows' => [['alarm_time' => '2026-08-01 00:00:00']]]),
];
$a = src($t)->jobAlarms('2026-10-01 00:00:00');
check('jobAlarms: stopped at the first page older than since', count($t->to('GET', '/alarm/job')) === 2);
check('jobAlarms: only rows since', array_column($a['rows'], 'alarm_time') === ['2026-10-05 00:00:00', '2026-10-03 00:00:00', '2026-10-02 00:00:00']);
$q = query($t->to('GET', '/alarm/job')[0]['url']);
check('jobAlarms: sort alarm_time desc', ($q['sort'] ?? '') === 'alarm_time' && ($q['order'] ?? '') === 'desc');
$t = new RouteT();
$a = src($t)->jobAlarms('2026-10-01 00:00:00');
check('jobAlarms: refused -> error, no throw', $a['rows'] === [] && $a['error'] !== '');

done();
