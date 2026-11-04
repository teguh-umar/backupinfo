<?php

require __DIR__ . '/bootstrap.php';

foreach (['Text', 'Source/Run', 'Source/RunHistorySource', 'Vinchin/ItemMapper', 'Vinchin/JobHistoryMapper',
    'Vinchin/RunMapper', 'Vinchin/AlarmJoin', 'Sync/RunStore', 'Sync/RunSync'] as $f) {
    require BI_ROOT . "/src/$f.php";
}

use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Source\RunHistorySource;
use GlpiPlugin\Backupinfo\Sync\RunStore;
use GlpiPlugin\Backupinfo\Sync\RunSync;

final class MemRunStore implements RunStore
{
    /** @var array<string, array{run: Run, reason: string, items: list<array>}> "conn|uuid" => row */
    public array $rows = [];
    /** @var list<string> insert order */
    public array $order = [];
    public function known(int $connectionId, array $uuids): array
    {
        $out = [];
        foreach ($uuids as $u) {
            if (isset($this->rows[$connectionId . '|' . $u])) {
                $out[$u] = true;
            }
        }
        return $out;
    }
    public function insert(int $connectionId, Run $run, string $reason, array $items): bool
    {
        $k = $connectionId . '|' . $run->historyUuid;
        if (isset($this->rows[$k])) {
            return false;
        }
        $this->rows[$k] = ['run' => $run, 'reason' => $reason, 'items' => $items];
        $this->order[]  = $run->historyUuid;
        return true;
    }
    public function purgeOlderThan(int $months, int $nowS): int
    {
        return 0;
    }
}

final class FakeRuns implements RunHistorySource
{
    /** @var list<Run> newest first */
    public array $runs = [];
    public array $items = [];
    public array $itemErrors = [];
    public array $alarms = [];
    public array $calls = [];
    public array $pageChecks = [];
    public function runs(callable $pageKnown, int $maxPages, int $pageSize = 100): array
    {
        $this->calls[] = ['runs', $maxPages];
        $this->pageChecks[] = $pageKnown(array_map(static fn (Run $r) => $r->historyUuid, $this->runs));
        return ['runs' => $this->runs, 'errors' => []];
    }
    public function runItems(string $historyUuid): array
    {
        $this->calls[] = ['items', $historyUuid];
        if (isset($this->itemErrors[$historyUuid])) {
            return ['items' => [], 'error' => $this->itemErrors[$historyUuid]];
        }
        return ['items' => $this->items[$historyUuid] ?? [], 'error' => ''];
    }
    public function jobAlarms(string $sinceTime, int $max = 2000): array
    {
        $this->calls[] = ['alarms', $sinceTime];
        return ['rows' => $this->alarms, 'error' => ''];
    }
}

function run(string $uuid, string $status, string $job = 'JOB-1', string $start = '2026-10-02 01:00:00', string $finish = '2026-10-02 01:10:00'): Run
{
    return new Run($uuid, $job, 'j1', $start, $finish, $status, ucfirst($status), '1GB', 600);
}

// Computers known to the connection's objects: VM-A = 11, VM-B = 12 (both in JOB-1), VM-C unmatched
$vmToComputer = static fn (string $name): int => ['vm-a' => 11, 'vm-b' => 12][mb_strtolower($name)] ?? 0;
$jobComputers = static fn (string $job): array => mb_strtolower($job) === 'job-1'
    ? [['computers_id' => 11, 'vm_name' => 'VM-A'], ['computers_id' => 12, 'vm_name' => 'VM-B']] : [];

// --- first run: backfill, oldest inserted first, failed run gets per-VM items and a reason
$src = new FakeRuns();
$src->runs = [run('u3', Run::SUCCESS), run('u2', Run::FAILED, 'JOB-1', '2026-10-01 01:00:00', '2026-10-01 01:05:09'), run('u1', Run::SUCCESS, 'JOB-1', '2026-09-30 01:00:00')];
$src->items['u2'] = [['vm_name' => 'VM-A', 'status' => 'failed', 'error' => 'E1'], ['vm_name' => 'VM-C', 'status' => 'success', 'error' => '']];
$src->alarms = [['job_name' => 'JOB-1', 'alarm_time' => '2026-10-01 01:05:09', 'description' => 'Failed to <b>prepare</b>']];
$store = new MemRunStore();
$out = (new RunSync())->run(1, $src, $store, $vmToComputer, $jobComputers, 20);

check('first run: 3 new, 1 failed, no errors', $out === ['new' => 3, 'failed' => 1, 'errors' => []], json_encode($out));
check('oldest inserted first (a crash leaves the newest unknown, so the next run refetches)', $store->order === ['u1', 'u2', 'u3']);
check('maxPages passed through', $src->calls[0] === ['runs', 20]);
check('runItems only for the failed run', array_values(array_filter($src->calls, static fn ($c) => $c[0] === 'items')) === [['items', 'u2']]);
check('alarms read back to a day before the oldest failed run', in_array(['alarms', '2026-09-30 01:00:00'], $src->calls, true));
check('failed run: reason joined from the alarm', $store->rows['1|u2']['reason'] === 'Failed to prepare');
check('failed run: per-VM items, VM mapped, unmatched VM kept with 0', $store->rows['1|u2']['items'] === [
    ['computers_id' => 11, 'vm_name' => 'VM-A', 'item_status' => 'failed'],
    ['computers_id' => 0, 'vm_name' => 'VM-C', 'item_status' => 'success'],
], json_encode($store->rows['1|u2']['items']));
check('success run: job Computers, status of the run, no reason', $store->rows['1|u3']['items'] === [
    ['computers_id' => 11, 'vm_name' => 'VM-A', 'item_status' => 'success'],
    ['computers_id' => 12, 'vm_name' => 'VM-B', 'item_status' => 'success'],
] && $store->rows['1|u3']['reason'] === '');

// --- second run: everything known -> nothing inserted, no item or alarm calls
$src->calls = [];
$out = (new RunSync())->run(1, $src, $store, $vmToComputer, $jobComputers, 20);
check('second run: 0 new', $out['new'] === 0 && count($store->rows) === 3);
check('second run: page reported known', end($src->pageChecks) === true);
check('second run: no runItems, no alarms', count($src->calls) === 1);

// --- same uuid on another connection is a different run
$out = (new RunSync())->run(2, $src, $store, $vmToComputer, $jobComputers, 20);
check('other connection: stored separately', $out['new'] === 3 && count($store->rows) === 6);

// --- runItems empty -> job fallback with the run status; runItems error -> line, run still stored
$src = new FakeRuns();
$src->runs = [run('f1', Run::FAILED), run('f2', Run::FAILED, 'JOB-9')];
$src->itemErrors['f2'] = '/jobs/history/f2: code 1 refused';
$store = new MemRunStore();
$out = (new RunSync())->run(1, $src, $store, $vmToComputer, $jobComputers, 20);
check('runItems empty: job Computers, status failed', $store->rows['1|f1']['items'] === [
    ['computers_id' => 11, 'vm_name' => 'VM-A', 'item_status' => 'failed'],
    ['computers_id' => 12, 'vm_name' => 'VM-B', 'item_status' => 'failed'],
]);
check('runItems error: one error line, run stored with its job Computers (JOB-9 has none)', isset($store->rows['1|f2']) && $store->rows['1|f2']['items'] === []
    && $out['errors'] === ['/jobs/history/f2: code 1 refused']);
check('no alarm match: empty reason, never guessed', $store->rows['1|f1']['reason'] === '');
$src = new FakeRuns();
$src->runs = [run('f3', Run::FAILED)];
$src->itemErrors['f3'] = 'refused';
$store = new MemRunStore();
(new RunSync())->run(1, $src, $store, $vmToComputer, $jobComputers, 20);
check('runItems error: falls back to the job Computers, status failed', array_column($store->rows['1|f3']['items'], 'computers_id') === [11, 12]
    && array_unique(array_column($store->rows['1|f3']['items'], 'item_status')) === ['failed']);

// --- duplicate VM names in one run are stored once
$src = new FakeRuns();
$src->runs = [run('d1', Run::FAILED)];
$src->items['d1'] = [['vm_name' => 'VM-A', 'status' => 'failed', 'error' => ''], ['vm_name' => 'vm-a', 'status' => 'failed', 'error' => '']];
$store = new MemRunStore();
(new RunSync())->run(1, $src, $store, $vmToComputer, $jobComputers, 20);
check('duplicate VM name in one run: once', count($store->rows['1|d1']['items']) === 1);

// --- detail calls capped, newest failed runs first
$src = new FakeRuns();
for ($n = RunSync::MAX_ITEM_CALLS + 5; $n >= 1; $n--) {
    $src->runs[] = run('c' . $n, Run::FAILED);
}
$store = new MemRunStore();
$out = (new RunSync())->run(1, $src, $store, $vmToComputer, $jobComputers, 20);
$detail = array_column(array_values(array_filter($src->calls, static fn ($c) => $c[0] === 'items')), 1);
check('detail calls capped at MAX_ITEM_CALLS', count($detail) === RunSync::MAX_ITEM_CALLS && $out['new'] === RunSync::MAX_ITEM_CALLS + 5);
check('detail calls go to the newest failed runs', in_array('c' . (RunSync::MAX_ITEM_CALLS + 5), $detail, true) && !in_array('c1', $detail, true));
check('beyond the cap: job Computers', array_column($store->rows['1|c1']['items'], 'computers_id') === [11, 12]);

// --- empty source page is never "known" (no false stop)
$src = new FakeRuns();
(new RunSync())->run(1, $src, new MemRunStore(), $vmToComputer, $jobComputers, 20);
check('empty page is not known', $src->pageChecks === [false]);

// --- Cron wiring
$cron = (string) file_get_contents(BI_ROOT . '/src/Cron.php');
check('cron: runs after a successful object sync of a RunHistorySource', str_contains($cron, 'instanceof RunHistorySource') && str_contains($cron, 'new RunSync()'));
check('cron: run errors caught, connection status untouched', (bool) preg_match('/catch \(\\\\Throwable \$e\) \{\s*\$line = sprintf\(\'Runs %s/', $cron));
check('cron: retention purge', str_contains($cron, 'purgeOlderThan('));
check('cron: page limit from config', str_contains($cron, "'run_pages_max'"));

done();
