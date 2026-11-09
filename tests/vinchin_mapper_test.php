<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Source/BackupObject.php';
require BI_ROOT . '/src/Source/BackupSource.php';
require BI_ROOT . '/src/Source/ProbeableSource.php';
require BI_ROOT . '/src/Source/AuditProbeableSource.php';
require BI_ROOT . '/src/Source/RunHistorySource.php';
require BI_ROOT . '/src/Source/AuditSource.php';
require BI_ROOT . '/src/Source/PartialFetch.php';
require BI_ROOT . '/src/Source/Registry.php';
require BI_ROOT . '/src/Source/VerifyingSource.php';
require BI_ROOT . '/src/Vinchin/Signer.php';
require BI_ROOT . '/src/Http/Transport.php';
require BI_ROOT . '/src/Http/CurlTransport.php';
require BI_ROOT . '/src/Vinchin/Client.php';
require BI_ROOT . '/src/Vinchin/ItemMapper.php';
require BI_ROOT . '/src/Vinchin/JobHistoryMapper.php';
require BI_ROOT . '/src/Vinchin/ScheduleMapper.php';
require BI_ROOT . '/src/Vinchin/VinchinSource.php';

use GlpiPlugin\Backupinfo\Source\Registry;
use GlpiPlugin\Backupinfo\Vinchin\Client;
use GlpiPlugin\Backupinfo\Vinchin\ItemMapper;
use GlpiPlugin\Backupinfo\Vinchin\JobHistoryMapper;
use GlpiPlugin\Backupinfo\Http\Transport;
use GlpiPlugin\Backupinfo\Vinchin\VinchinSource;

// parseTime
check('parseTime seconds', ItemMapper::parseTime(1761600000) === 1761600000);
check('parseTime millis', ItemMapper::parseTime(1761600000000) === 1761600000);
check('parseTime numeric string ms', ItemMapper::parseTime('1761600000000') === 1761600000);
check('parseTime local string is Asia/Jakarta', ItemMapper::parseTime('2025-10-28 04:20:00') === 1761600000);
check('parseTime garbage', ItemMapper::parseTime('x') === null);
check('parseTime null', ItemMapper::parseTime(null) === null);

// VM merge
$rows = [
    ['item_name' => 'VM-A(10.1.2.3)', 'module_type_des' => 'Virtualization', 'vcenter_name' => '10.0.12.61',
     'last_time' => 1761600000, 'point_num' => 3, 'task_name' => 'DAILY', 'data_size' => '10 GB',
     'abnormal_chain_flag' => false, 'src_data_deleted_flag' => false],
    ['item_name' => 'VM-A(10.1.2.3)', 'module_type_des' => 'Virtualization', 'vcenter_name' => '10.0.12.61',
     'last_time' => 1761700000000, 'point_num' => 4, 'task_name' => 'WEEKLY', 'data_size' => '12 GB',
     'abnormal_chain_flag' => true, 'src_data_deleted_flag' => false],
    ['item_name' => 'VM-B', 'module_type_des' => 'Virtualization', 'vcenter_name' => '10.0.12.61', 'ip' => '10.9.9.9',
     'last_time' => 'never', 'point_num' => 1, 'task_name' => 'DAILY', 'src_data_deleted_flag' => true],
    ['title' => 'VM-C', 'module_type_des' => 'Virtualization', 'vcenter_name' => '10.0.12.61'],
];
$out = ItemMapper::map($rows);
check('three VMs after merge', count($out) === 3);
$a = $out[0];
check('VM key is name', $a->sourceKey === 'VM-A');
check('VM name stripped of ip', $a->name === 'VM-A');
check('ip from item_name', $a->ip === '10.1.2.3');
check('kind vm', $a->kind === 'vm');
check('latest last_time', $a->lastBackup === 1761700000);
check('restore points summed', $a->restorePoints === 7);
check('job names joined', $a->jobName === 'DAILY, WEEKLY');
check('abnormal flag OR-ed', $a->abnormalChain === true && $a->sourceDeleted === false);
check('data size from latest row', $a->dataSize === '12 GB');
check('ip field used, bad time null, deleted flag', $out[1]->ip === '10.9.9.9' && $out[1]->lastBackup === null && $out[1]->sourceDeleted === true);
check('vcenter_name never becomes ip', $out[2]->ip === '' && $out[2]->name === 'VM-C');

// non-VM
$o = ItemMapper::map([
    ['item_name' => 'ERPDB', 'item_uuid' => 'u-1', 'module_type_des' => 'Database', 'sub_type_des' => 'SQL Server',
     'instance_name' => 'HQMSSQL14', 'ip' => '10.0.0.8', 'last_time' => '2025-10-28 04:20:00', 'point_num' => 5, 'task_name' => 'T'],
    ['item_name' => 'ERPDB', 'item_uuid' => 'u-2', 'module_type_des' => 'Database', 'instance_name' => 'OTHER'],
    ['item_name' => 'files1', 'item_uuid' => 'u-3', 'module_type_des' => 'File Backup', 'instance_name' => 'srv'],
    ['item_name' => 'host1', 'item_uuid' => 'u-4', 'module_type_des' => 'Physical Server Agent'],
    ['item_name' => 'weird', 'item_uuid' => 'u-5', 'module_type_des' => 'Something'],
]);
check('non-VM rows not merged', count($o) === 5);
check('non-VM key is uuid', $o[0]->sourceKey === 'u-1' && $o[1]->sourceKey === 'u-2');
check('non-VM name', $o[0]->name === 'ERPDB @ HQMSSQL14');
check('kind database', $o[0]->kind === 'database');
check('kind file', $o[2]->kind === 'file');
check('kind agent', $o[3]->kind === 'agent');
check('unknown kind other', $o[4]->kind === 'other');
check('non-VM time and ip', $o[0]->lastBackup === 1761600000 && $o[0]->ip === '10.0.0.8');

// job history
$now = 1761638400;
$hist = [
    ['job_name' => 'WEEKLY COPY_VM-APP-HUB', 'job_type' => 'Backup', 'job_status' => 'Successful', 'start_time' => ($now - 7200) * 1000, 'finish_time' => ($now - 3600) * 1000],
    ['job_name' => 'WEEKLY COPY_VM-APP-HUB', 'job_type' => 'Backup', 'job_status' => 'Successful', 'start_time' => ($now - 90000) * 1000, 'finish_time' => ($now - 86400) * 1000],
    ['job_name' => 'WEEKLY COPY_VM-APP-HUB', 'job_type' => 'Backup', 'job_status' => 'Failed', 'start_time' => ($now - 100) * 1000],
    ['job_name' => 'restore VM-APP-HUB', 'job_type' => 'Restore', 'job_status' => 'Successful', 'start_time' => ($now - 50) * 1000],
    ['job_name' => 'VM-OLD', 'job_type' => 'Backup', 'job_status' => 'Successful', 'start_time' => ($now - 30 * 86400) * 1000],
    ['job_name' => 'unrelated', 'job_type' => 'Backup', 'job_status' => 'Successful', 'start_time' => ($now - 10) * 1000],
    ['job_name' => 'VM-X', 'job_type' => 'Backup', 'job_status' => '成功', 'start_time' => ($now - 20) * 1000],
];
$h = JobHistoryMapper::map($hist, ['VM-APP-HUB', 'VM-OLD', 'VM-X', 'VM-NEVER'], $now);
check('job history: two VMs', count($h) === 2, (string) count($h));
$byName = [];
foreach ($h as $x) {
    $byName[$x->name] = $x;
}
$s = $byName['VM-APP-HUB'] ?? null;
check('job history: VM matched from job_name', $s !== null && $s->kind === 'vm' && $s->sourceKey === 'VM-APP-HUB');
check('job history: last success finish time', $s !== null && $s->lastBackup === $now - 3600);
check('job history: success count', $s !== null && $s->restorePoints === 2);
check('job history: job name', $s !== null && $s->jobName === 'WEEKLY COPY_VM-APP-HUB');
check('job history: CJK success', isset($byName['VM-X']));
check('job history: lookback drops old', !isset($byName['VM-OLD']));

// source with fake transport
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
function okr($data): array
{
    return ['status' => 200, 'headers' => [], 'body' => json_encode(['success' => true, 'data' => $data])];
}
function bad(): array
{
    return ['status' => 200, 'headers' => [], 'body' => json_encode(['success' => false, 'code' => 5, 'message' => 'nope'])];
}

$t = new FakeT();
$t->queue = [okr(['total' => 1, 'rows' => [['item_name' => 'VM-A', 'module_type_des' => 'Virtualization', 'last_time' => 1761600000]]])];
$src = new VinchinSource(new Client('h', $t, 'K'), false, fn() => ['ignored']);
$r = $src->fetch();
check('source: items path used', count($r) === 1 && $r[0]->name === 'VM-A' && str_contains($t->calls[0]['url'], '/backup_data/items/list') && $t->calls[0]['method'] === 'POST');
check('source: empty filter body', (json_decode((string) $t->calls[0]['body'], true)['sort'] ?? '') === 'item_name' && (json_decode((string) $t->calls[0]['body'], true)['module_type'] ?? null) === []);

$t = new FakeT();
$t->queue = [bad(), okr(['total' => 1, 'rows' => [['job_name' => 'nightly VM-Q', 'job_type' => 'Backup', 'job_status' => 'Successful', 'start_time' => (time() - 60) * 1000]]])];
$src = new VinchinSource(new Client('h', $t, 'K'), false, fn() => ['VM-Q']);
$r = $src->fetch();
check('source: falls back to job history', count($r) === 1 && $r[0]->name === 'VM-Q' && str_contains($t->calls[1]['url'], '/jobs/history'));
check('source: history sorted first', str_contains($t->calls[1]['url'], 'sort=start_time'));

$t = new FakeT();
$t->queue = [bad(), bad(), okr(['total' => 0, 'rows' => []])];
$src = new VinchinSource(new Client('h', $t, 'K'), false, fn() => ['VM-Q']);
$src->fetch();
check('source: retries history without sort', str_contains($t->calls[2]['url'], '/jobs/history') && !str_contains($t->calls[2]['url'], 'sort='));

$t = new FakeT();
$t->queue = [okr(['access_token' => 'TK']), okr(['total' => 0, 'rows' => []])];
$src = new VinchinSource(new Client('h', $t, '', 'u', 'p'), true, fn() => []);
$src->fetch();
check('source: login mode logs in first', str_contains($t->calls[0]['url'], '/login'));

// review round 1
$h = JobHistoryMapper::map([
    ['job_name' => 'DAILY_VM-AB', 'job_type' => 'Backup', 'job_status' => 'Successful', 'start_time' => ($now - 10) * 1000],
    ['job_name' => 'mount-server', 'job_type' => 'Backup', 'job_status' => 'Successful', 'start_time' => ($now - 10) * 1000],
    ['job_name' => 'VM-N1', 'job_type' => 'Backup', 'job_status' => 'Unsuccessful', 'start_time' => ($now - 10) * 1000],
    ['job_name' => 'VM-N2', 'job_type' => 'Backup', 'job_status' => 'Incomplete', 'start_time' => ($now - 10) * 1000],
], ['VM-A', 'VM-AB', 'mount-server', 'VM-N1', 'VM-N2'], $now);
$names = array_map(fn($x) => $x->name, $h);
sort($names);
check('longest name wins', in_array('VM-AB', $names, true) && !in_array('VM-A', $names, true));
check('job named mount-server not skipped', in_array('mount-server', $names, true));
check('Unsuccessful/Incomplete rejected', !in_array('VM-N1', $names, true) && !in_array('VM-N2', $names, true));
$m = ItemMapper::map([
    ['item_name' => 'Vm-Case', 'module_type_des' => 'Virtualization', 'point_num' => 1],
    ['item_name' => 'VM-CASE', 'module_type_des' => 'Virtualization', 'point_num' => 2],
]);
check('VM names differing in case merge', count($m) === 1 && $m[0]->restorePoints === 3);

$t = new FakeT();
$t->queue = [bad(), okr(['total' => 1, 'rows' => [['job_name' => 'VM-Q', 'job_type' => 'Backup', 'job_status' => 'Successful', 'start_time' => (time() - 60) * 1000]]])];
$r = (new VinchinSource(new Client('h', $t, 'K'), false, fn() => ['VM-Q']))->fetch();
check('items auth error falls back to history', count($r) === 1);

$t = new FakeT();
$t->queue = [bad(), bad(), bad()];
$e = null;
try {
    (new VinchinSource(new Client('h', $t, 'K'), false, fn() => ['VM-Q']))->fetch();
} catch (\RuntimeException $x) {
    $e = $x;
}
check('both fail throws with previous', $e !== null && $e->getPrevious() instanceof \RuntimeException && str_contains($e->getMessage(), 'job history also failed'));

$t = new FakeT();
$t->queue = [bad()];
$e = null;
try {
    (new VinchinSource(new Client('h', $t, 'K'), false, fn() => []))->fetch();
} catch (\RuntimeException $x) {
    $e = $x;
}
check('empty vmNames after items failure throws', $e !== null && str_contains($e->getMessage(), 'Computer names') && count($t->calls) === 1);

// registry
$conn = ['type' => 'vinchin', 'host' => 'h', 'auth_mode' => 'api_key', 'api_key' => 'ENC', 'username' => '', 'password' => '', 'verify_tls' => 1];
check('registry builds VinchinSource', Registry::create($conn, fn(string $s): string => $s) instanceof VinchinSource);
check('registry accepts vm-name provider', Registry::create($conn, fn(string $s): string => $s, fn() => []) instanceof VinchinSource);

// final review I3/M1: fallback marks the fetch partial; joined job names are sorted
$t = new FakeT();
$t->queue = [okr(['total' => 1, 'rows' => [['item_name' => 'VM-A', 'module_type_des' => 'Virtualization', 'last_time' => 1, 'task_name' => 'Zeta'],
    ['item_name' => 'VM-A', 'module_type_des' => 'Virtualization', 'last_time' => 2, 'task_name' => 'Alpha']]])];
$src = new VinchinSource(new Client('h', $t, 'K'), false, fn() => ['VM-A']);
$r = $src->fetch();
check('items fetch is not partial', $src->lastFetchWasPartial() === false);
check('merged job names sorted', $r[0]->jobName === 'Alpha, Zeta');
$t = new FakeT();
$t->queue = [bad(), okr(['total' => 1, 'rows' => [['job_name' => 'nightly VM-Q', 'job_type' => 'Backup', 'job_status' => 'Successful', 'start_time' => (time() - 60) * 1000]]])];
$src = new VinchinSource(new Client('h', $t, 'K'), false, fn() => ['VM-Q']);
$src->fetch();
check('history fallback marks the fetch partial', $src->lastFetchWasPartial() === true);

// VM sample 2026-11-02: Vinchin sends "ip": "--" when it has no address
$r = ItemMapper::map([['item_name' => 'VM-TEST-02', 'module_type_des' => 'Virtualization', 'ip' => '--', 'last_time' => '2026-10-23 00:06:06']]);
check('ip "--" is treated as empty', $r[0]->ip === '');
$r = ItemMapper::map([['item_name' => 'x', 'item_uuid' => 'u1', 'module_type_des' => 'Database', 'ip' => ' - ']]);
check('ip "-" is treated as empty (non-VM too)', $r[0]->ip === '');
$r = ItemMapper::map([['item_name' => 'VM-B', 'module_type_des' => 'Virtualization', 'ip' => '10.0.0.9']]);
check('a real ip is kept', $r[0]->ip === '10.0.0.9');

// schedules (rc6): fetch reads each job's time_strategy; job names are not trusted
$t = new FakeT();
$t->queue = [
    okr(['total' => 2, 'rows' => [
        ['item_name' => 'VM-A', 'module_type_des' => 'Virtualization', 'last_time' => 1761600000, 'task_name' => 'VM BACKUP_A (WEEKLY)'],
        ['item_name' => 'VM-B', 'module_type_des' => 'Virtualization', 'last_time' => 1761600000, 'task_name' => 'JOB-B'],
    ]]),
    okr(['total' => 3, 'rows' => [
        ['job_name' => 'VM BACKUP_A (WEEKLY)', 'job_uuid' => 'ua'],
        ['job_name' => 'UNRELATED RESTORE', 'job_uuid' => 'ux'],
        ['job_name' => 'JOB-B', 'job_uuid' => 'ub'],
    ]]),
    okr(['time_strategy' => [['mode' => 1, 'type' => 2, 'frequency' => 2, 'days' => [5]]]]),
    okr(['time_strategy' => [['type' => 9]]]),
];
$src = new VinchinSource(new Client('h', $t, 'K'), false, fn() => []);
$r = $src->fetch();
$byName = [];
foreach ($r as $o) {
    $byName[$o->name] = $o;
}
check('schedules: two-week job gives 336 h', ($byName['VM-A']->scheduleHours ?? null) === 336);
check('schedules: unreadable strategy stays unknown', array_key_exists('VM-B', $byName) && $byName['VM-B']->scheduleHours === null);
check('schedules: job list is GET /jobs', $t->calls[1]['method'] === 'GET' && str_contains($t->calls[1]['url'], '/api/v1/jobs?'));
check('schedules: details only for jobs the objects name', count($t->calls) === 4
    && str_contains($t->calls[2]['url'], '/jobs/ua?') && str_contains($t->calls[3]['url'], '/jobs/ub?'));
check('schedules: the unreadable one is reported, naming the job', count($src->scheduleErrors()) === 1 && str_contains($src->scheduleErrors()[0], 'JOB-B'));

$t = new FakeT();
$t->queue = [okr(['total' => 1, 'rows' => [['item_name' => 'VM-A', 'module_type_des' => 'Virtualization', 'last_time' => 1761600000, 'task_name' => 'J']]]), bad()];
$src = new VinchinSource(new Client('h', $t, 'K'), false, fn() => []);
$r = $src->fetch();
check('schedules: refused job list keeps the objects, schedule unknown', count($r) === 1 && $r[0]->scheduleHours === null);
check('schedules: refused job list is one error line, no key', count($src->scheduleErrors()) === 1 && !str_contains($src->scheduleErrors()[0], 'K'));

done();
