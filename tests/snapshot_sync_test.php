<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/Sync/SnapshotStore.php';
require BI_ROOT . '/src/Sync/SnapshotSync.php';

use GlpiPlugin\Backupinfo\Sync\SnapshotStore;
use GlpiPlugin\Backupinfo\Sync\SnapshotSync;

$read = static fn (string $rel): string => (string) @file_get_contents(BI_ROOT . '/' . $rel);

final class MemSnapshots implements SnapshotStore
{
    /** @var array<string, array{taken: string, rows: array}> "conn|kind|day" */
    public array $rows = [];
    public function put(int $c, string $kind, string $takenAt, array $rows): void
    {
        $this->rows["$c|$kind|" . substr($takenAt, 0, 10)] = ['taken' => $takenAt, 'rows' => $rows];
    }
    public function purgeOlderThan(int $months, int $nowS): int
    {
        return 0;
    }
}

final class FakeNow
{
    public array $fail = [];
    public array $data = ['targets' => [['kind' => 'tape', 'name' => 'T1', 'used' => '1TB']], 'platformVms' => [['vm_name' => 'VM-A', 'backup_status' => 1]]];
    public function __call(string $m, array $a): array
    {
        return isset($this->fail[$m]) ? ['rows' => [], 'error' => $this->fail[$m]] : ['rows' => $this->data[$m] ?? [], 'error' => ''];
    }
}

$now   = strtotime('2026-11-05 10:00:00');
$src   = new FakeNow();
$store = new MemSnapshots();
$res   = SnapshotSync::run(1, $src, $store, $now);
check('both kinds stored', $res === ['stored' => ['target' => 1, 'platformvm' => 1], 'errors' => []], json_encode($res));
check('stored under the day, with the time taken', $store->rows['1|target|2026-11-05']['taken'] === '2026-11-05 10:00:00');

$src->data['targets'][0]['used'] = '2TB';
SnapshotSync::run(1, $src, $store, $now + 3600);
check('same day: one snapshot, the newest kept', count($store->rows) === 2 && $store->rows['1|target|2026-11-05']['rows'][0]['used'] === '2TB');
SnapshotSync::run(1, $src, $store, $now + 86400);
check('next day: a new snapshot, the old one kept', isset($store->rows['1|target|2026-11-06'], $store->rows['1|target|2026-11-05']));

$src->fail = ['targets' => 'HTTP 403'];
$res = SnapshotSync::run(1, $src, $store, $now + 2 * 86400);
check('a failed kind: reported, the other stored, no empty snapshot written', $res['errors'] === ['target: HTTP 403'] && isset($store->rows['1|platformvm|2026-11-07']) && !isset($store->rows['1|target|2026-11-07']));

// Wiring
$install = $read('install/install.php');
check('install: snapshots table, one per connection, kind and day', str_contains($install, 'CREATE TABLE `glpi_plugin_backupinfo_snapshots`') && str_contains($install, 'UNIQUE KEY `unicity` (`connections_id`, `kind`, `snap_date`)'));
check('uninstall drops it', str_contains($read('install/uninstall.php'), "'glpi_plugin_backupinfo_snapshots'"));
$cron = $read('src/Cron.php');
check('cron stores snapshots after events, with retention', str_contains($cron, 'self::storeSnapshots(') && str_contains($cron, 'SnapshotSync::run(') && substr_count($cron, 'purgeOlderThan(') >= 3);
$data = $read('src/AuditData.php');
check('audit: targets and platform VMs from the snapshots, no live read', str_contains($data, "snapshotRows('platformvm')") && str_contains($data, "snapshots('target', true)") && !str_contains($data, 'function live('));

done();
