<?php

require __DIR__ . '/bootstrap.php';

foreach ([
    'Source/BackupObject', 'Source/BackupSource', 'Source/ProbeableSource', 'Source/PartialFetch', 'Source/Registry',
    'Vinchin/Signer', 'Vinchin/Transport', 'Vinchin/CurlTransport', 'Vinchin/Client',
    'Vinchin/ItemMapper', 'Vinchin/JobHistoryMapper', 'Vinchin/ScheduleMapper', 'Vinchin/VinchinSource',
    'Matching/AssetLookup', 'Matching/Matcher', 'Probe',
] as $f) {
    require BI_ROOT . "/src/$f.php";
}

use GlpiPlugin\Backupinfo\Matching\AssetLookup;
use GlpiPlugin\Backupinfo\Matching\Matcher;
use GlpiPlugin\Backupinfo\Probe;
use GlpiPlugin\Backupinfo\Source\BackupObject;
use GlpiPlugin\Backupinfo\Source\BackupSource;
use GlpiPlugin\Backupinfo\Source\ProbeableSource;
use GlpiPlugin\Backupinfo\Vinchin\Client;
use GlpiPlugin\Backupinfo\Vinchin\Transport;
use GlpiPlugin\Backupinfo\Vinchin\VinchinSource;

// --- redact
$r = Probe::redact([
    'item_name' => 'VM-A',
    'api_key'   => 'K1',
    'nested'    => ['Password' => 'p', 'access_token' => 't', 'keep' => 'x', 'deeper' => ['X-Auth' => 'a', 'n' => 1]],
    'list'      => [['session_id' => 's', 'ok' => true]],
    'tokens'    => ['a', 'b'],
]);
check('redact keeps ordinary keys', $r['item_name'] === 'VM-A' && $r['nested']['keep'] === 'x' && $r['nested']['deeper']['n'] === 1 && $r['list'][0]['ok'] === true);
check('redact masks api_key', $r['api_key'] === '***');
check('redact masks nested Password and access_token', $r['nested']['Password'] === '***' && $r['nested']['access_token'] === '***');
check('redact masks at depth 3', $r['nested']['deeper']['X-Auth'] === '***');
check('redact masks inside lists', $r['list'][0]['session_id'] === '***');
check('redact masks a whole array under a secret key', $r['tokens'] === '***');

// --- fakes
final class FakeLookup implements AssetLookup
{
    public function byName(string $name): array
    {
        return strcasecmp($name, 'VM-A') === 0 ? [['itemtype' => 'Computer', 'items_id' => 7]] : [];
    }
    public function byIp(string $ip): array
    {
        return [];
    }
}
final class FakeProbe implements BackupSource, ProbeableSource
{
    public function fetch(): array
    {
        throw new \LogicException('probe must not call fetch()');
    }
    public function probe(int $limit, string $search = ''): array
    {
        return [
            'raw' => ['/x' => [['item_name' => 'VM-A', 'token' => 'secret']]],
            'objects' => [
                new BackupObject('VM-A', 'VM-A', '', 'vm', 'J', 1761600000, 3, '1 GB', false, false),
                new BackupObject('db1', 'db1 @ i', '', 'database', 'J', null, 0, '', false, false),
            ],
            'errors' => ['/y: down'],
        ];
    }
}
final class FetchOnly implements BackupSource
{
    public function fetch(): array
    {
        return [];
    }
}
$m = new Matcher(new FakeLookup());

$p = Probe::fromSource(new FakeProbe(), $m);
check('fromSource: raw is redacted', $p['raw']['/x'][0]['token'] === '***' && $p['raw']['/x'][0]['item_name'] === 'VM-A');
check('fromSource: objects passed through', count($p['objects']) === 2);
check('fromSource: vm object matched', ($p['matches'][0]['state'] ?? '') === 'matched' && ($p['matches'][0]['items_id'] ?? 0) === 7);
check('fromSource: non-vm object not matched', !isset($p['matches'][1]));
check('fromSource: source errors kept', $p['errors'] === ['/y: down']);

$p = Probe::fromSource(new FetchOnly(), $m);
check('non-probeable source: "no probe yet"', $p['errors'] === ['This source type has no probe yet'] && $p['raw'] === []);

$p = Probe::run(['type' => 'nope'], fn($s) => $s, $m);
check('unknown type: error, no throw', count($p['errors']) === 1 && str_contains($p['errors'][0], 'nope'));

// --- VinchinSource::probe over a fake transport
final class FakeT implements Transport
{
    public array $calls = [];
    public array $queue = [];
    public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls): array
    {
        $this->calls[] = compact('method', 'url', 'body');
        return array_shift($this->queue) ?? ['status' => 500, 'headers' => [], 'body' => ''];
    }
}
function ok($data): array
{
    return ['status' => 200, 'headers' => [], 'body' => json_encode(['success' => true, 'data' => $data])];
}
function ko(): array
{
    return ['status' => 200, 'headers' => [], 'body' => json_encode(['success' => false, 'code' => 5, 'message' => 'nope'])];
}

$t = new FakeT();
$t->queue = [
    ok(['total' => 500, 'rows' => [['item_name' => 'VM-A(10.0.0.1)', 'module_type_des' => 'Virtualization', 'last_time' => 1761600000]]]),
    ok(['total' => 900, 'rows' => [['job_name' => 'DAILY_VM-A', 'job_type' => 'Backup', 'job_status' => 'Successful', 'start_time' => (time() - 60) * 1000]]]),
    ok(['total' => 0, 'rows' => []]),
];
$s = (new VinchinSource(new Client('h', $t, 'K'), false, fn() => ['VM-A']))->probe(5);
check('vinchin probe: exactly one request per endpoint (no paging)', count($t->calls) === 3);
check('vinchin probe: items is POST with offset 0, limit 5',
    $t->calls[0]['method'] === 'POST' && str_contains($t->calls[0]['url'], '/backup_data/items/list')
    && (json_decode((string) $t->calls[0]['body'], true)['offset'] ?? null) === 0
    && (json_decode((string) $t->calls[0]['body'], true)['limit'] ?? null) === 5);
check('vinchin probe: history is GET with offset 0, limit 5',
    $t->calls[1]['method'] === 'GET' && str_contains($t->calls[1]['url'], '/jobs/history')
    && str_contains($t->calls[1]['url'], 'offset=0') && str_contains($t->calls[1]['url'], 'limit=5'));
check('vinchin probe: history sorted newest first (sort=start_time, order=desc)',
    str_contains($t->calls[1]['url'], 'sort=start_time') && str_contains($t->calls[1]['url'], 'order=desc'));
check('vinchin probe: raw keyed by endpoint', isset($s['raw']['/backup_data/items/list'], $s['raw']['/jobs/history']));
check('vinchin probe: items mapped (name/ip split)', ($s['objects'][0]->name ?? '') === 'VM-A' && ($s['objects'][0]->ip ?? '') === '10.0.0.1');
check('vinchin probe: no errors', $s['errors'] === []);

$t = new FakeT();
$t->queue = [ko(), ok(['total' => 1, 'rows' => [['job_name' => 'DAILY_VM-A', 'job_type' => 'Backup', 'job_status' => 'Successful', 'start_time' => (time() - 60) * 1000]]]), ok(['total' => 0, 'rows' => []])];
$s = (new VinchinSource(new Client('h', $t, 'K'), false, fn() => ['VM-A']))->probe(5);
check('vinchin probe: failing items endpoint still runs history', isset($s['raw']['/jobs/history']) && !isset($s['raw']['/backup_data/items/list']));
check('vinchin probe: one error line, no key', count($s['errors']) === 1 && !str_contains($s['errors'][0], 'K'));

$t = new FakeT();
$t->queue = [ko()];
$s = (new VinchinSource(new Client('h', $t, '', 'u', 'p'), true, fn() => []))->probe(5);
check('vinchin probe: failed login returns an error, no throw, no further calls', count($s['errors']) === 1 && str_starts_with($s['errors'][0], 'login') && $s['raw'] === []);

// --- page and menu wiring (source-string checks)
$page = (string) @file_get_contents(BI_ROOT . '/front/probe.php');
check('probe page checks config UPDATE', str_contains($page, 'Session::checkRight(RightDefinition::CONFIG, UPDATE)'));
check('probe page runs Probe::run', str_contains($page, 'Probe::run('));
check('probe page escapes JSON output', str_contains($page, 'htmlescape(json_encode('));
check('probe page writes nothing', !preg_match('/->(add|update|delete)\(|setConfigurationValues|FieldsBridge|ObjectStore|doQuery/', $page));
$menu = (string) file_get_contents(BI_ROOT . '/src/Menu.php');
check('menu has a probe entry gated by config UPDATE',
    (bool) preg_match("/haveRight\(RightDefinition::CONFIG, UPDATE\)\) \{\s*\\\$options\['probe'\]/", $menu));

// history refuses sort: one retry without it, still a sample
$t = new FakeT();
$t->queue = [
    ok(['total' => 0, 'rows' => []]),
    ko(),
    ok(['total' => 1, 'rows' => [['job_name' => 'DAILY_VM-A', 'job_type' => 'Backup', 'job_status' => 'Completed', 'start_time' => '2026-11-02 01:00:00']]]),
    ok(['total' => 0, 'rows' => []]),
];
$s = (new VinchinSource(new Client('h', $t, 'K'), false, fn() => ['VM-A']))->probe(5);
check('vinchin probe: history retried without sort when refused', count($t->calls) === 4
    && !str_contains($t->calls[2]['url'], 'sort=') && isset($s['raw']['/jobs/history']) && $s['errors'] === []);

// search (user has API access only, 2026-11-02): both endpoints get the keyword
$t = new FakeT();
$t->queue = [ok(['total' => 0, 'rows' => [['item_name' => 'VM-TEST-02', 'module_type_des' => 'Virtualization']]]), ok(['total' => 0, 'rows' => []])];
(new VinchinSource(new Client('h', $t, 'K'), false, fn() => []))->probe(5, 'VM-TEST-02');
check('probe search: items body carries the keyword', (json_decode((string) $t->calls[0]['body'], true)['search'] ?? null) === 'VM-TEST-02');
check('probe search: history query carries the keyword', str_contains($t->calls[1]['url'], 'search=VM-TEST-02'));
check('probe search: Probe::run passes it on', (bool) preg_match('/public static function run\(array \$connectionRow, callable \$decrypt, Matcher \$m, int \$limit = self::LIMIT, \?callable \$vmNames = null, string \$search = \'\'\)/', (string) file_get_contents(BI_ROOT . '/src/Probe.php')));
check('probe page shows the RPO per row', str_contains((string) file_get_contents(BI_ROOT . '/front/probe.php'), "\$rpo->label(['schedule_hours' => \$o->scheduleHours])"));
check('probe page has a search field', str_contains((string) file_get_contents(BI_ROOT . '/front/probe.php'), "name=\"search\""));
check('probe search: job list query carries the keyword', str_contains($t->calls[2]['url'], '/jobs?') && str_contains($t->calls[2]['url'], 'search=VM-TEST-02'));

// job schedules (job names do not follow the real schedule, user 2026-11-02): list, then one detail per job
$t = new FakeT();
$t->queue = [
    ok(['total' => 0, 'rows' => []]),
    ok(['total' => 0, 'rows' => []]),
    ok(['total' => 9, 'rows' => [['job_name' => 'J1', 'job_uuid' => 'u-1'], ['job_name' => 'J2', 'job_uuid' => 'u/2'], ['job_name' => 'no uuid']]]),
    ok(['time_strategy' => [['mode' => 1, 'type' => 2, 'frequency' => 2, 'days' => [5]]], 'next_time' => '2026-11-06 00:00:00']),
    ko(),
];
$s = (new VinchinSource(new Client('h', $t, 'K'), false, fn() => []))->probe(5);
check('job schedules: list is GET /jobs with offset 0, limit 5, one page',
    $t->calls[2]['method'] === 'GET' && str_contains($t->calls[2]['url'], '/api/v1/jobs?') && str_contains($t->calls[2]['url'], 'limit=5') && count($t->calls) === 5);
check('job schedules: one detail GET per job with a uuid (encoded)',
    str_contains($t->calls[3]['url'], '/api/v1/jobs/u-1?') && str_contains($t->calls[4]['url'], '/api/v1/jobs/u%2F2?'));
check('job schedules: raw list kept', count($s['raw']['/jobs'] ?? []) === 3);
check('job schedules: detail carries uuid, name and time_strategy',
    ($s['raw']['/jobs/{ID}'][0]['job_name'] ?? '') === 'J1' && ($s['raw']['/jobs/{ID}'][0]['time_strategy'][0]['frequency'] ?? null) === 2);
check('job schedules: a failing detail is one error line, the others stay', count($s['raw']['/jobs/{ID}']) === 1 && count($s['errors']) === 1);

$t = new FakeT();
$t->queue = [ok(['total' => 0, 'rows' => []]), ok(['total' => 0, 'rows' => []]), ko()];
$s = (new VinchinSource(new Client('h', $t, 'K'), false, fn() => []))->probe(5);
check('job schedules: refused job list is one error line, other endpoints kept', count($s['errors']) === 1 && !isset($s['raw']['/jobs']) && isset($s['raw']['/jobs/history']));

check('probe search is trimmed and capped', Probe::cleanSearch("  VM-A  ") === 'VM-A' && mb_strlen(Probe::cleanSearch(str_repeat('x', 300))) === 100);

done();
