<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Source/Run.php';
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
require BI_ROOT . '/src/Veeam/RunMapper.php';
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

done();
