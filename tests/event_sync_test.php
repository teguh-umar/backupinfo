<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/Sync/EventStore.php';
require BI_ROOT . '/src/Sync/EventSync.php';

use GlpiPlugin\Backupinfo\Sync\EventStore;
use GlpiPlugin\Backupinfo\Sync\EventSync;

$read = static fn (string $rel): string => (string) @file_get_contents(BI_ROOT . '/' . $rel);

final class FakeEvents implements EventStore
{
    /** @var array<string, array{kind: string, time: string, data: array}> */
    public array $rows = [];

    public function latest(int $c, string $kind): ?string
    {
        $t = null;
        foreach ($this->rows as $k => $r) {
            if (str_starts_with($k, "$c|$kind|") && ($t === null || $r['time'] > $t)) {
                $t = $r['time'];
            }
        }
        return $t;
    }

    public function insert(int $c, string $kind, string $time, string $fp, array $data): bool
    {
        if (isset($this->rows["$c|$kind|$fp"])) {
            return false;
        }
        $this->rows["$c|$kind|$fp"] = ['kind' => $kind, 'time' => $time, 'data' => $data];
        return true;
    }

    public function purgeOlderThan(int $months, int $nowS): int
    {
        return 0;
    }
}

final class FakeAudit
{
    public array $calls = [];
    public array $data  = [];
    public array $fail  = [];

    public function __call(string $m, array $a): array
    {
        $this->calls[] = [$m, $a[0], $a[1]];
        if (isset($this->fail[$m])) {
            return ['rows' => [], 'error' => $this->fail[$m]];
        }
        return ['rows' => $this->data[$m] ?? [], 'error' => ''];
    }
}

$now = strtotime('2026-11-05 10:00:00');
$src = new FakeAudit();
$src->data = [
    'copyRuns'     => [['kind' => 'copy', 'job' => 'COPY-A', 'time' => '2026-11-04 01:00:00', 'end_time' => '2026-11-04 02:00:00', 'vm' => 'VM-A', 'status' => 'Success', 'item_status' => 'Success']],
    'jobLogs'      => [['kind' => 'joblog', 'ref' => '7', 'time' => '2026-11-04 03:00:00', 'job' => 'J', 'message' => 'x'],
                       ['kind' => 'joblog', 'ref' => '8', 'time' => '2026-11-04 04:00:00', 'job' => 'J', 'message' => 'y']],
    'systemLogs'   => [['kind' => 'syslog', 'ref' => '', 'time' => '2026-11-04 05:00:00', 'message' => 'login']],
    'systemAlarms' => [['kind' => 'sysalarm', 'ref' => '3', 'time' => '2026-11-04 06:00:00', 'status' => 'Pending']],
];
$store = new FakeEvents();
$res   = EventSync::run(1, $src, $store, $now);
check('first sync: every kind stored', $res['new'] === ['copy' => 1, 'joblog' => 2, 'syslog' => 1, 'sysalarm' => 1], json_encode($res['new']));
check('first sync: backfill window', $src->calls[0][1] === date('Y-m-d H:i:s', $now - EventSync::FIRST_DAYS * 86400) && $src->calls[0][2] === '2026-11-05 10:00:00');
$syslog = array_values(array_filter($store->rows, static fn(array $r): bool => $r['kind'] === 'syslog'));
check('event time from the common time field', $syslog[0]['time'] === '2026-11-04 05:00:00');

$src->calls = [];
$src->data['systemAlarms'][0]['status'] = 'Solved'; // same alarm, status changed later
$res = EventSync::run(1, $src, $store, $now + 3600);
check('second sync: nothing new (identity, not the whole row, for alarms)', array_sum($res['new']) === 0, json_encode($res['new']));
check('second sync: window from the newest stored event minus one day', $src->calls[1][1] === '2026-11-03 04:00:00', json_encode($src->calls[1]));
check('stored rows never change', str_contains(json_encode($store->rows), 'Pending') && !str_contains(json_encode($store->rows), 'Solved'));

$src->fail = ['jobLogs' => 'HTTP 403'];
$src->data['copyRuns'][] = ['kind' => 'copy', 'job' => 'COPY-A', 'time' => '2026-11-05 01:00:00', 'vm' => 'VM-A', 'status' => 'Failed'];
$res = EventSync::run(1, $src, $store, $now + 7200);
check('a failed kind does not stop the others', $res['new']['copy'] === 1 && $res['errors'] === ['joblog: HTTP 403'], json_encode($res));
$bad = new FakeAudit();
$bad->data = ['jobLogs' => [['kind' => 'joblog', 'time' => 'n/a']]];
check('row without a usable time is skipped', EventSync::run(2, $bad, new FakeEvents(), $now)['new']['joblog'] === 0);

check('fingerprint stable whatever the key order', EventSync::fingerprint('joblog', ['b' => 1, 'a' => 2]) === EventSync::fingerprint('joblog', ['a' => 2, 'b' => 1]));
check('fingerprint is 40 hex chars', (bool) preg_match('/^[0-9a-f]{40}$/', EventSync::fingerprint('copy', ['x' => 1])));
check('copy identity: job, start, VM', EventSync::fingerprint('copy', ['job' => 'A', 'time' => 'T', 'vm' => 'V', 'status' => 'Running'])
    === EventSync::fingerprint('copy', ['job' => 'A', 'time' => 'T', 'vm' => 'V', 'status' => 'Success']));
check('rc5 raw events are cleared once on upgrade (refilled by the next sync)', str_contains((string) @file_get_contents(BI_ROOT . '/install/install.php'), "'events_format'"));

// Wiring
$cron = $read('src/Cron.php');
check('cron stores events after runs, never failing the sync', str_contains($cron, 'self::storeEvents(') && str_contains($cron, 'EventSync::run('));
$data = $read('src/AuditData.php');
check('audit: copies and activity read from GLPI', str_contains($data, "\$this->events('copy'") && str_contains($data, "\$this->events('joblog'") && str_contains($data, '(new DbEventStore())->rows($kind'));
check('audit: system log and alarms still behind config READ', str_contains($data, 'Session::haveRight(RightDefinition::CONFIG, READ)'));
check('audit: no live read for copies or activity', !str_contains($data, "live('copyRuns'") && !str_contains($data, "live('jobLogs'"));
$page = $read('front/audit.php');
check('page: only coverage and targets are live', str_contains($page, "\$live    = ['coverage', 'targets'];"));

done();
