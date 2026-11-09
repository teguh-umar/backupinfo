<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Source/Run.php';
require BI_ROOT . '/src/Source/Event.php';
require BI_ROOT . '/src/Source/BackupObject.php';
require BI_ROOT . '/src/Source/BackupSource.php';
require BI_ROOT . '/src/Source/RunHistorySource.php';
require BI_ROOT . '/src/Source/PartialFetch.php';
require BI_ROOT . '/src/Source/AuditSource.php';
require BI_ROOT . '/src/Source/AuditProbeableSource.php';
require BI_ROOT . '/src/Source/ProbeableSource.php';
require BI_ROOT . '/src/Source/Time.php';
require BI_ROOT . '/src/Http/Transport.php';
require BI_ROOT . '/src/Http/CurlTransport.php';
require BI_ROOT . '/src/Veeam/ApiError.php';
require BI_ROOT . '/src/Veeam/Client.php';
require BI_ROOT . '/src/Veeam/EventMapper.php';
require BI_ROOT . '/src/Veeam/ObjectMapper.php';
require BI_ROOT . '/src/Veeam/RunMapper.php';
require BI_ROOT . '/src/Veeam/TargetMapper.php';
require BI_ROOT . '/src/Veeam/VeeamSource.php';
require BI_ROOT . '/src/Probe.php';

require __DIR__ . '/veeam_fakes.php';

use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Source\Time;
use GlpiPlugin\Backupinfo\Veeam\Client;
use GlpiPlugin\Backupinfo\Veeam\RunMapper;
use GlpiPlugin\Backupinfo\Veeam\VeeamSource;

date_default_timezone_set('Asia/Jakarta');

function stoppedSession(string $id, string $name = 'JOB-1', string $jobId = 'j1'): array
{
    return [
        'id' => $id, 'name' => $name, 'jobId' => $jobId,
        'creationTime' => '2026-11-01T01:00:00Z', 'endTime' => '2026-11-01T02:00:00Z',
        'state' => 'Stopped', 'result' => ['result' => 'Success', 'isCanceled' => false],
    ];
}

function workingSession(string $id): array
{
    return [
        'id' => $id, 'name' => 'JOB-1', 'jobId' => 'j1',
        'creationTime' => '2026-11-01T01:00:00Z', 'endTime' => null,
        'state' => 'Working', 'result' => ['result' => 'None'],
    ];
}

/** Calls to GET /api/v1/sessions, with url and skip/limit from the query. */
function sessionsCalls(VeeamT $t): array
{
    $calls = array_values(array_filter($t->calls, static fn (array $c): bool => $c['method'] === 'GET'
        && parse_url($c['url'], PHP_URL_PATH) === '/api/v1/sessions'));
    foreach ($calls as &$c) {
        parse_str((string) parse_url($c['url'], PHP_URL_QUERY), $q);
        $c['skip'] = $q['skip'] ?? null;
        $c['limit'] = $q['limit'] ?? null;
    }
    return $calls;
}

// --- runs(): skip per page, stops when $pageKnown says known, only mapped ids to $pageKnown
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vpage([stoppedSession('s1'), workingSession('s2')], 6, 0));
$t->queue('GET /api/v1/sessions', vpage([stoppedSession('s3'), workingSession('s4')], 6, 2));
$t->queue('GET /api/v1/sessions', vpage([stoppedSession('s5'), workingSession('s6')], 6, 4));
$pageKnownCalls = 0;
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$runs = $src->runs(function (array $ids) use (&$pageKnownCalls): bool {
    $pageKnownCalls++;
    return $pageKnownCalls === 2;
}, 10, 2);
$sc = sessionsCalls($t);
check('runs: stops after pageKnown true (skip 0 and 2, never page 3)', count($sc) === 2, json_encode(array_column($sc, 'url')));
check('runs: first request skip 0, limit 2', $sc[0]['skip'] === '0' && $sc[0]['limit'] === '2');
check('runs: second request skip 2, limit 2', $sc[1]['skip'] === '2' && $sc[1]['limit'] === '2');
check('runs: 2 runs (Stopped only; Working skipped)', count($runs['runs']) === 2, json_encode(array_column($runs['runs'], 'historyUuid')));
check('runs: no errors', $runs['errors'] === []);

// --- $pageKnown receives only mapped (Stopped) ids, not running sessions
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vpage([stoppedSession('a'), workingSession('b'), stoppedSession('c')], 3, 0));
$seenPageKnown = [];
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$runs = $src->runs(function (array $ids) use (&$seenPageKnown): bool {
    $seenPageKnown[] = $ids;
    return true;
}, 10, 3);
check('runs: 2 runs from [Stopped, Working, Stopped]', count($runs['runs']) === 2, json_encode(array_column($runs['runs'], 'historyUuid')));
check('runs: pageKnown receives exactly the 2 stopped ids', $seenPageKnown === [['a', 'c']], json_encode($seenPageKnown));

// --- page holding only Working sessions gives $pageKnown([])
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vpage([workingSession('w1'), workingSession('w2')], 2, 0));
$seenPageKnown = [];
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$runs = $src->runs(function (array $ids) use (&$seenPageKnown): bool {
    $seenPageKnown[] = $ids;
    return true;
}, 10, 3);
check('runs: only Working sessions gives 0 runs', $runs['runs'] === []);
check('runs: pageKnown receives empty array', $seenPageKnown === [[]], json_encode($seenPageKnown));

// --- failureReasons(): reason comes from the Failed task
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions/s1/taskSessions', vpage([
    ['name' => 'vm-app-01', 'result' => ['result' => 'Failed', 'message' => 'Disk full']],
    ['name' => 'vm-app-01', 'result' => ['result' => 'Success']],
], 2, 0));
$failed = [new Run('s1', 'JOB-1', 'j1', '2026-11-01 01:00:00', '2026-11-01 02:00:00', Run::FAILED, 'Failed', '', 0)];
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$fr = $src->failureReasons($failed);
check('failureReasons: reason comes from the Failed task', $fr['reasons']['s1'] === 'Disk full', json_encode($fr));
check('failureReasons: error empty', $fr['error'] === '');

// --- runItems(): answers from cache with no second HTTP call
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions/s1/taskSessions', vpage([
    ['name' => 'vm-app-01', 'result' => ['result' => 'Failed', 'message' => 'Disk full']],
], 1, 0));
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$ri1 = $src->runItems('s1');
$ri2 = $src->runItems('s1');
$taskCalls = count(array_filter($t->calls, static fn (array $c): bool => str_contains($c['url'], '/taskSessions')));
check('runItems: items from cache', $ri1 === $ri2 && $ri1['items'][0]['error'] === 'Disk full');
check('runItems: no second HTTP call', $taskCalls === 1, json_encode(array_column(array_filter($t->calls, static fn (array $c): bool => str_contains($c['url'], '/taskSessions')), 'url')));

// --- failureReasons(): cap at MAX_TASK_CALLS task calls
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
for ($n = 1; $n <= 101; $n++) {
    $id = 'r' . $n;
    $t->queue("GET /api/v1/sessions/$id/taskSessions", vpage([['name' => 'vm', 'result' => ['result' => 'Failed']]], 1, 0));
}
$failed = [];
for ($n = 1; $n <= 101; $n++) {
    $failed[] = new Run('r' . $n, 'JOB-1', 'j1', '2026-11-01 01:00:00', '2026-11-01 02:00:00', Run::FAILED, 'Failed', '', 0);
}
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$fr = $src->failureReasons($failed);
$taskCalls = count(array_filter($t->calls, static fn (array $c): bool => str_contains($c['url'], '/taskSessions')));
check('failureReasons: at most MAX_TASK_CALLS task calls for 101 runs', $taskCalls <= 100, json_encode(['calls' => $taskCalls]));

// --- failureReasons(): 403 on first task call -> one error, no further task calls
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions/s1/taskSessions', vj([], 403));
$t->queue('GET /api/v1/sessions/s2/taskSessions', vpage([['name' => 'vm', 'result' => ['result' => 'Failed']]], 1, 0));
$failed = [
    new Run('s1', 'JOB-1', 'j1', '2026-11-01 01:00:00', '2026-11-01 02:00:00', Run::FAILED, 'Failed', '', 0),
    new Run('s2', 'JOB-1', 'j1', '2026-11-01 01:00:00', '2026-11-01 02:00:00', Run::FAILED, 'Failed', '', 0),
];
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$fr = $src->failureReasons($failed);
$taskCalls = count(array_filter($t->calls, static fn (array $c): bool => str_contains($c['url'], '/taskSessions')));
check('failureReasons: 403 on first task call gives one error', $fr['error'] !== '');
check('failureReasons: 403 stops further task calls', $taskCalls === 1, json_encode(['calls' => $taskCalls]));

function copySession(string $id, string $name, string $creationTime): array
{
    return [
        'id' => $id, 'name' => $name, 'jobId' => 'jc-' . $id,
        'creationTime' => $creationTime, 'endTime' => null,
        'state' => 'Stopped', 'result' => ['result' => 'Success', 'isCanceled' => false],
    ];
}

// --- copyRuns(): two copy sessions, one inside the window and one outside,
// each with 2 tasks -> 2 copy events, both from the in-window session
$from = '2026-11-01 00:00:00';
$to = '2026-11-30 23:59:59';
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vpage([
    copySession('c-in', 'COPY-IN', '2026-11-15T01:00:00Z'),
    copySession('c-out', 'COPY-OUT', '2026-10-01T01:00:00Z'),
], 2, 0));
$t->queue('GET /api/v1/sessions/c-in/taskSessions', vpage([
    ['name' => 'vm-app-01', 'result' => ['result' => 'Success']],
    ['name' => 'vm-app-01', 'result' => ['result' => 'Success']],
], 2, 0));
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$cr = $src->copyRuns($from, $to);
$taskCalls = count(array_filter($t->calls, static fn (array $c): bool => str_contains($c['url'], '/taskSessions')));
check('copyRuns: 2 copy events from the in-window session', count($cr['rows']) === 2, json_encode($cr['rows']));
check('copyRuns: out-of-window session not read', $taskCalls === 1, json_encode(['calls' => $taskCalls]));
check('copyRuns: no errors', $cr['error'] === '');

// --- copyRuns(): 60 sessions in the window -> at most 50 task calls
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$sixty = [];
for ($n = 1; $n <= 60; $n++) {
    $sixty[] = copySession('cs' . $n, 'COPY-' . $n, '2026-11-10T01:00:00Z');
    $t->queue("GET /api/v1/sessions/cs$n/taskSessions", vpage([
        ['name' => 'vm', 'result' => ['result' => 'Success']],
        ['name' => 'vm', 'result' => ['result' => 'Success']],
    ], 2, 0));
}
$t->queue('GET /api/v1/sessions', vpage($sixty, 60, 0));
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$cr = $src->copyRuns($from, $to);
$taskCalls = count(array_filter($t->calls, static fn (array $c): bool => str_contains($c['url'], '/taskSessions')));
check('copyRuns: 60 sessions in window -> at most 50 task calls', $taskCalls <= 50, json_encode(['calls' => $taskCalls]));
check('copyRuns: no errors', $cr['error'] === '');

function oauthCalls(VeeamT $t, string $route): int
{
    return count(array_filter($t->calls, static fn (array $c): bool => $c['method'] === 'POST'
        && parse_url($c['url'], PHP_URL_PATH) === '/api/' . $route));
}

// --- fetch(): one login, one logout, jobName from /backups id, restorePoints stop early
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/backupObjects', vpage([
    ['id' => 'o1', 'name' => 'vm-app-01', 'type' => 'VM', 'restorePointsCount' => 7],
], 1, 0));
$t->queue('GET /api/v1/restorePoints', vpage([
    ['id' => 'rp1', 'name' => 'vm-app-01', 'creationTime' => '2026-11-01T00:00:00Z', 'backupId' => 'b1'],
], 1, 0));
$t->queue('GET /api/v1/backups', vpage([
    ['id' => 'b1', 'name' => 'JOB-DAILY'],
], 1, 0));
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$objs = $src->fetch();
check('fetch: object from /backupObjects', count($objs) === 1 && $objs[0]->name === 'vm-app-01' && $objs[0]->sourceKey === 'veeam:o1');
check('fetch: lastBackup from newest restore point', $objs[0]->lastBackup === Time::epoch('2026-11-01T00:00:00Z'), json_encode($objs[0]->lastBackup));
check('fetch: jobName from /backups id', $objs[0]->jobName === 'JOB-DAILY');
check('fetch: restorePoints count', $objs[0]->restorePoints === 7);
$loginCalls = oauthCalls($t, 'oauth2/token');
$logoutCalls = oauthCalls($t, 'oauth2/logout');
check('fetch: exactly one login', $loginCalls === 1, json_encode(['login' => $loginCalls]));
check('fetch: exactly one logout', $logoutCalls === 1, json_encode(['logout' => $logoutCalls]));
check('fetch: restorePoints stop fired after one page', count(array_filter($t->calls, static fn (array $c): bool => str_contains($c['url'], '/restorePoints'))) === 1);
check('fetch: no access-xyz in serialized output', !str_contains(serialize($objs), 'access-xyz'));

// --- fetch(): restorePoints stop does not read all pages when names already seen
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/backupObjects', vpage([
    ['id' => 'o1', 'name' => 'vm-app-01', 'type' => 'VM', 'restorePointsCount' => 1],
    ['id' => 'o2', 'name' => 'vm-db-01', 'type' => 'VM', 'restorePointsCount' => 1],
], 2, 0));
$t->queue('GET /api/v1/restorePoints', vpage([
    ['id' => 'rp1', 'name' => 'vm-app-01', 'creationTime' => '2026-11-01T00:00:00Z', 'backupId' => 'b1'],
    ['id' => 'rp2', 'name' => 'vm-db-01', 'creationTime' => '2026-11-01T01:00:00Z', 'backupId' => 'b2'],
], 2, 0));
$t->queue('GET /api/v1/restorePoints', vpage([
    ['id' => 'rp3', 'name' => 'vm-app-01', 'creationTime' => '2026-11-02T00:00:00Z', 'backupId' => 'b3'],
], 1, 0));
$t->queue('GET /api/v1/backups', vpage([
    ['id' => 'b1', 'name' => 'JOB-1'], ['id' => 'b2', 'name' => 'JOB-2'],
], 2, 0));
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$objs = $src->fetch();
$rpCalls = count(array_filter($t->calls, static fn (array $c): bool => str_contains($c['url'], '/restorePoints')));
check('fetch: restorePoints stops once all names seen (1 page)', $rpCalls === 1, json_encode(['calls' => $rpCalls]));
check('fetch: both objects get lastBackup', $objs[0]->lastBackup === Time::epoch('2026-11-01T00:00:00Z')
    && $objs[1]->lastBackup === Time::epoch('2026-11-01T01:00:00Z'));

done();



