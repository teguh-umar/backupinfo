<?php

require __DIR__ . '/bootstrap.php';

foreach ([
    'Source/BackupObject', 'Source/BackupSource', 'Source/ProbeableSource', 'Source/AuditProbeableSource',
    'Source/PartialFetch', 'Source/Registry',
    'Vinchin/Signer', 'Vinchin/Transport', 'Vinchin/CurlTransport', 'Vinchin/Client',
    'Vinchin/ItemMapper', 'Vinchin/JobHistoryMapper', 'Vinchin/ScheduleMapper', 'Vinchin/VinchinSource',
    'Matching/AssetLookup', 'Matching/Matcher', 'Rpo', 'Probe',
] as $f) {
    require BI_ROOT . "/src/$f.php";
}

use GlpiPlugin\Backupinfo\Probe;
use GlpiPlugin\Backupinfo\Source\AuditProbeableSource;
use GlpiPlugin\Backupinfo\Source\BackupSource;
use GlpiPlugin\Backupinfo\Vinchin\Client;
use GlpiPlugin\Backupinfo\Vinchin\Transport;
use GlpiPlugin\Backupinfo\Vinchin\VinchinSource;

/** Answers by request path (method + path, no query), so tests do not depend on call order. */
final class RouteT implements Transport
{
    public array $calls = [];
    /** @var array<string, list<array>> "GET /jobs/history" => queue of responses */
    public array $routes = [];

    public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls): array
    {
        $this->calls[] = compact('method', 'url', 'body');
        $path = (string) parse_url($url, PHP_URL_PATH);
        $key  = $method . ' ' . substr($path, strlen('/api/v1'));
        if (!empty($this->routes[$key])) {
            return array_shift($this->routes[$key]);
        }
        return ['status' => 200, 'headers' => [], 'body' => json_encode(['success' => false, 'code' => 404, 'message' => 'no route'])];
    }

    /** @return list<array{method:string,url:string,body:?string}> calls to $method $path */
    public function to(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => $c['method'] === $method && parse_url($c['url'], PHP_URL_PATH) === '/api/v1' . $path
        ));
    }
}

function okd($data): array
{
    return ['status' => 200, 'headers' => [], 'body' => json_encode(['success' => true, 'data' => $data])];
}
function rows(array $rows): array
{
    return okd(['total' => count($rows), 'rows' => $rows]);
}
function query(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    return $q;
}

$full = static function (): RouteT {
    $t = new RouteT();
    $t->routes = [
        'POST /backup_data/items/list' => [rows([['item_name' => 'VM-A', 'module_type_des' => 'Virtualization', 'timepoint_uuid' => 'tp-1', 'task_name' => 'J-A']])],
        'GET /jobs/history'            => [rows([['job_name' => 'J-A', 'job_status' => 'Failure', 'job_status_value' => 4]])],
        'GET /alarm/job'               => [rows([['alarm_id' => 1, 'alarm_level' => 3, 'content' => 'backup failed']])],
        'GET /alarm/system'            => [rows([['alarm_id' => 2]])],
        'GET /logs/job'                => [rows([['id' => 3]])],
        'GET /logs/system'             => [rows([['id' => 4, 'create_user' => 'admin', 'password' => 'p']])],
        'GET /backupmanager/report/vm' => [rows([['vm_name' => 'VM-A', 'backup_status' => 1]])],
        'GET /industry/report'         => [rows([['report_uuid' => 'r1']])],
        'GET /backup_data/verify_history' => [rows([['timepoint' => '2026-11-01', 'integrity_check_status' => 1]])],
        'GET /backup_data/virus_history'  => [rows([['status' => 1, 'virus_file_num' => 0]])],
        'GET /jobs'                    => [rows([['job_name' => 'J-A', 'job_uuid' => 'ua', 'job_type' => 'Backup'], ['job_name' => 'COPY-1', 'job_uuid' => 'uc', 'job_type' => 'Backup Copy']])],
        'GET /copy/job/history'        => [rows([['status' => 'ok']])],
    ];
    return $t;
};

// --- every audit endpoint is sampled once, read-only, one page
$t = $full();
$s = (new VinchinSource(new Client('h', $t, 'K'), false, fn () => []))->probeAudit(5, 'VM-A');
$expected = [
    'failed runs (/jobs/history, job_status 4)', '/alarm/job', '/alarm/system', '/logs/job', '/logs/system',
    '/backupmanager/report/vm', '/industry/report', '/backup_data/verify_history', '/backup_data/virus_history', '/copy/job/history',
];
check('audit: every endpoint has a raw sample', array_keys($s['raw']) === $expected, json_encode(array_keys($s['raw'])));
check('audit: no errors', $s['errors'] === [], json_encode($s['errors']));
check('audit: read-only (GET only, plus the items list POST)', array_filter($t->calls, static fn ($c) => $c['method'] !== 'GET'
    && !str_contains($c['url'], '/backup_data/items/list')) === []);

$failed = query($t->to('GET', '/jobs/history')[0]['url']);
check('failed runs: job_status 4, newest first, keyword, one page of 5',
    ($failed['job_status'] ?? '') === '4' && ($failed['sort'] ?? '') === 'start_time' && ($failed['order'] ?? '') === 'desc'
    && ($failed['search'] ?? '') === 'VM-A' && ($failed['limit'] ?? '') === '5' && ($failed['offset'] ?? '') === '0');
$alarm = query($t->to('GET', '/alarm/job')[0]['url']);
check('job alarms: sort alarm_time desc (required by the API)', ($alarm['sort'] ?? '') === 'alarm_time' && ($alarm['order'] ?? '') === 'desc');
$jl = query($t->to('GET', '/logs/job')[0]['url']);
check('job logs: sort op_time desc', ($jl['sort'] ?? '') === 'op_time' && ($jl['order'] ?? '') === 'desc');
check('verify history: timepoint of the sampled item', (query($t->to('GET', '/backup_data/verify_history')[0]['url'])['timepoint_uuid'] ?? '') === 'tp-1');
check('virus history: same timepoint', (query($t->to('GET', '/backup_data/virus_history')[0]['url'])['timepoint_uuid'] ?? '') === 'tp-1');
check('copy history: task_uuid of a copy/replica job from the job list', (query($t->to('GET', '/copy/job/history')[0]['url'])['task_uuid'] ?? '') === 'uc');
check('raw rows kept as returned', ($s['raw']['/alarm/job'][0]['content'] ?? '') === 'backup failed');

// Probe::runAudit masks credentials like the normal probe
$p = Probe::fromAuditSource((new VinchinSource(new Client('h', $full(), 'K'), false, fn () => [])), 5, '');
check('Probe audit: credential keys masked', ($p['raw']['/logs/system'][0]['password'] ?? '') === '***' && ($p['raw']['/logs/system'][0]['create_user'] ?? '') === 'admin');

// --- one refused endpoint is one error line; the others still run
$t = $full();
unset($t->routes['GET /alarm/system']);
$s = (new VinchinSource(new Client('h', $t, 'K'), false, fn () => []))->probeAudit(5, '');
check('refused endpoint: one error line naming it, others kept', count($s['errors']) === 1 && str_contains($s['errors'][0], '/alarm/system')
    && !isset($s['raw']['/alarm/system']) && isset($s['raw']['/logs/job']));
check('refused endpoint: error carries no key', !str_contains($s['errors'][0], 'K'));

// --- system log sort field is undocumented: second guess tried before giving up
$t = $full();
$t->routes['GET /logs/system'] = [['status' => 200, 'headers' => [], 'body' => json_encode(['success' => false, 'code' => 1, 'message' => 'bad sort'])], rows([['id' => 9]])];
$s = (new VinchinSource(new Client('h', $t, 'K'), false, fn () => []))->probeAudit(5, '');
$tries = array_map(static fn ($c) => query($c['url'])['sort'] ?? '', $t->to('GET', '/logs/system'));
check('system log: create_time, then op_time', $tries === ['create_time', 'op_time'] && ($s['raw']['/logs/system'][0]['id'] ?? 0) === 9);

// --- no timepoint, no copy job: said so, nothing called
$t = $full();
$t->routes['POST /backup_data/items/list'] = [rows([])];
$t->routes['GET /jobs'] = [rows([['job_name' => 'J-A', 'job_uuid' => 'ua', 'job_type' => 'Backup']])];
$s = (new VinchinSource(new Client('h', $t, 'K'), false, fn () => []))->probeAudit(5, '');
check('no restore point in the sample: verify/virus skipped with a note', $t->to('GET', '/backup_data/verify_history') === []
    && in_array('/backup_data/verify_history: skipped, no restore point (timepoint_uuid) in the sample', $s['errors'], true));
check('no copy job: copy history skipped with a note', $t->to('GET', '/copy/job/history') === []
    && in_array('/copy/job/history: skipped, no copy or replica job in the job list', $s['errors'], true));

// --- login mode: a failed login stops everything, one line
$t = new RouteT();
$s = (new VinchinSource(new Client('h', $t, '', 'u', 'p'), true, fn () => []))->probeAudit(5, '');
check('failed login: one error, no samples', count($s['errors']) === 1 && str_starts_with($s['errors'][0], 'login') && $s['raw'] === []);

// --- page wiring
$page = (string) file_get_contents(BI_ROOT . '/front/probe.php');
check('page: audit button', str_contains($page, "'name' => 'probe_audit'"));
check('page: audit runs Probe::runAudit', str_contains($page, 'Probe::runAudit('));
check('page: still writes nothing', !preg_match('/->(add|update|delete)\(|setConfigurationValues|FieldsBridge|ObjectStore|doQuery/', $page));
check('interface: a source without audit probe says so', !(new \ReflectionClass(BackupSource::class))->implementsInterface(AuditProbeableSource::class));

done();
