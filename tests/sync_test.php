<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/Source/BackupObject.php';
require BI_ROOT . '/src/Source/BackupSource.php';
require BI_ROOT . '/src/Source/PartialFetch.php';
require BI_ROOT . '/src/Matching/AssetLookup.php';
require BI_ROOT . '/src/Matching/Matcher.php';
require BI_ROOT . '/src/Rpo.php';
require BI_ROOT . '/src/Status.php';
require BI_ROOT . '/src/Fields/FieldsGateway.php';
require BI_ROOT . '/src/Fields/FieldsBridge.php';
require BI_ROOT . '/src/Sync/ObjectStore.php';
require BI_ROOT . '/src/Sync/SyncService.php';

use GlpiPlugin\Backupinfo\Fields\FieldsBridge;
use GlpiPlugin\Backupinfo\Matching\AssetLookup;
use GlpiPlugin\Backupinfo\Matching\Matcher;
use GlpiPlugin\Backupinfo\Source\BackupObject;
use GlpiPlugin\Backupinfo\Source\BackupSource;
use GlpiPlugin\Backupinfo\Sync\ObjectStore;
use GlpiPlugin\Backupinfo\Sync\SyncService;

final class FakeSource implements BackupSource
{
    public function __construct(public array $objects) {}
    public function fetch(): array { return $this->objects; }
}

final class ThrowingSource implements BackupSource
{
    public function fetch(): array { throw new RuntimeException('down'); }
}

final class FakeLookup implements AssetLookup
{
    public function __construct(private array $names = [], private array $ips = []) {}
    public function byName(string $name): array
    {
        return array_map(fn ($id) => ['itemtype' => 'Computer', 'items_id' => $id], $this->names[strtolower($name)] ?? []);
    }
    public function byIp(string $ip): array
    {
        return array_map(fn ($id) => ['itemtype' => 'Computer', 'items_id' => $id], $this->ips[$ip] ?? []);
    }
}

final class MemStore implements ObjectStore
{
    /** @var array<string, array> "conn|key" => row */
    public array $rows = [];
    public function existing(int $connectionId): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            if ($r['connections_id'] === $connectionId) {
                $out[$r['source_key']] = $r;
            }
        }
        return $out;
    }
    public function upsert(int $connectionId, array $row): void
    {
        $this->rows[$connectionId . '|' . $row['source_key']] = ['connections_id' => $connectionId] + $row;
    }
    public function delete(int $connectionId, array $sourceKeys): void
    {
        foreach ($sourceKeys as $k) {
            unset($this->rows[$connectionId . '|' . $k]);
        }
    }
    /** @var list<int> ids of active connections */
    public array $active = [1, 2, 3];
    public function purgeInactive(): array
    {
        $items = [];
        foreach ($this->rows as $k => $r) {
            if (!in_array($r['connections_id'], $this->active, true)) {
                if ($r['items_id'] > 0) {
                    $items[$r['items_id']] = true;
                }
                unset($this->rows[$k]);
            }
        }
        return array_keys($items);
    }
    public function matched(): array
    {
        return array_values(array_filter(
            $this->rows,
            fn ($r) => $r['itemtype'] === 'Computer' && $r['items_id'] > 0 && in_array($r['connections_id'], $this->active, true)
        ));
    }
}

final class NullGateway implements GlpiPlugin\Backupinfo\Fields\FieldsGateway
{
    public function pluginActive(): bool { return false; }
    public function containers(): array { return []; }
    public function fields(int $containerId): array { return []; }
    public function currentValues(string $itemtype, string $containerName, int $itemsId): ?array { return null; }
    public function findDropdownEntry(string $fieldName, string $value): int { return 0; }
    public function createDropdownEntry(string $fieldName, string $value): int { return 0; }
    public function itemIdsWithValue(string $itemtype, string $containerName, string $column, int $value): array { return []; }
    public function itemEntity(string $itemtype, int $id): ?int { return null; }
    public function update(array $data, string $itemtype): bool { return false; }
    public function log(string $message): void {}
}

final class FakeBridge extends FieldsBridge
{
    public array $writes = [];
    public bool $ok = true;
    public function __construct() { parent::__construct([], new NullGateway()); }
    public function canWrite(): bool { return $this->ok; }
    public function write(string $itemtype, int $id, bool $hasBackup, string $media): bool
    {
        $this->writes[] = [$itemtype, $id, $hasBackup, $media];
        return true;
    }
}

function obj(string $key, string $name, string $ip = '', string $kind = 'vm', ?int $last = 1000, bool $deleted = false): BackupObject
{
    return new BackupObject($key, $name, $ip, $kind, 'JOB', $last, 3, '10', false, $deleted);
}

$svc  = new SyncService();
$conn = ['id' => 1, 'type' => 'vinchin'];
$m    = new Matcher(new FakeLookup(['web1' => [7], 'dup' => [8, 9]], ['10.0.0.5' => [11]]));

// counts, kinds, storing
$store = new MemStore();
$r = $svc->run($conn, new FakeSource([
    obj('a', 'web1'), obj('b', 'dup'), obj('c', 'ghost'), obj('d', 'db1', '10.0.0.5', 'database'), obj('e', 'x', '10.0.0.5'),
]), $store, $m, 5000);
check('fetched count', $r['fetched'] === 5);
check('matched count (web1 by name, e by ip)', $r['matched'] === 2, json_encode($r));
check('review count', $r['review'] === 1);
check('unmatched count (vm only)', $r['unmatched'] === 1);
check('vm matched stored', $store->rows['1|a']['items_id'] === 7 && $store->rows['1|a']['match_state'] === 'matched');
check('schedule unknown stored as null', array_key_exists('schedule_hours', $store->rows['1|a']) && $store->rows['1|a']['schedule_hours'] === null);
$store2 = new MemStore();
$svc->run($conn, new FakeSource([obj('a', 'web1')->withSchedule(336)]), $store2, $m, 5000);
check('schedule interval stored', ($store2->rows['1|a']['schedule_hours'] ?? null) === 336);
$store3 = new MemStore();
$typed = new BackupObject('t', 'web1', '', 'database', 'JOB', 1, 1, '', false, false, null, null, 'Copy', 'TAPE-ONE (Tape)');
$svc->run($conn, new FakeSource([$typed]), $store3, $m, 5000);
check('job type and storage stored (0.5.1-rc2)', ($store3->rows['1|t']['job_type'] ?? null) === 'Copy' && ($store3->rows['1|t']['storage'] ?? null) === 'TAPE-ONE (Tape)');
$store4 = new MemStore();
$svc->run($conn, new FakeSource([new BackupObject('w', 'web1', '', 'vm', 'JOB', 1, 1, '', false, false, null, null, str_repeat('t', 80), str_repeat('s', 300))]), $store4, $m, 5000);
check('job type capped at 50, storage at 255', mb_strlen($store4->rows['1|w']['job_type']) === 50 && mb_strlen($store4->rows['1|w']['storage']) === 255);
check('non-vm never matched',$store->rows['1|d']['items_id'] === 0 && $store->rows['1|d']['itemtype'] === '');
check('first run affected lists newly matched', in_array(7, $r['affected'], true) && in_array(11, $r['affected'], true));

// stale rows deleted, affected reports them
$r = $svc->run($conn, new FakeSource([obj('b', 'dup'), obj('c', 'ghost'), obj('d', 'db1', '', 'database'), obj('e', 'x', '10.0.0.5')]), $store, $m, 5100);
check('stale row deleted', !isset($store->rows['1|a']));
check('deleted item reported affected', in_array(7, $r['affected'], true));
check('unchanged match not affected', !in_array(11, $r['affected'], true));
// other connection untouched
$store->upsert(2, ['source_key' => 'a', 'name' => 'n', 'ip' => '', 'kind' => 'vm', 'job_name' => '', 'last_backup' => 1, 'restore_points' => 0,
    'data_size' => '0', 'abnormal_chain' => 0, 'source_deleted' => 0, 'itemtype' => 'Computer', 'items_id' => 3, 'match_state' => 'matched']);
// final review I2: an empty answer while rows exist is an error, never "everything is gone"
$threw = false;
try {
    $svc->run($conn, new FakeSource([]), $store, $m, 5200);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'no objects');
}
check('empty fetch with stored rows throws', $threw);
check('empty fetch keeps own rows', isset($store->rows['1|b']));
check('other connection rows kept', isset($store->rows['2|a']));
$fresh = new MemStore();
$r = $svc->run(['id' => 9], new FakeSource([]), $fresh, $m, 5200);
check('empty fetch with no stored rows is fine', $r['fetched'] === 0);

// final review I3: a partial fetch (fallback) upserts but never deletes
final class PartialSource implements BackupSource, GlpiPlugin\Backupinfo\Source\PartialFetch
{
    public function __construct(public array $objects) {}
    public function fetch(): array { return $this->objects; }
    public function lastFetchWasPartial(): bool { return true; }
}
$store = new MemStore();
$svc->run($conn, new FakeSource([obj('a', 'web1'), obj('d', 'db1', '', 'database')]), $store, $m, 6000);
$r = $svc->run($conn, new PartialSource([obj('a', 'web1', '', 'vm', 7000)]), $store, $m, 7000);
check('partial fetch keeps unseen rows', isset($store->rows['1|d']));
check('partial fetch still upserts', $store->rows['1|a']['last_backup'] === 7000);

// final review I3: source keys compare case-insensitively (the DB unique key does)
$store = new MemStore();
$svc->run($conn, new FakeSource([obj('VM-Case', 'web1')]), $store, $m, 8000);
$svc->run($conn, new FakeSource([obj('vm-case', 'web1')]), $store, $m, 8100);
check('case-only key change is not stale', count($store->rows) === 1);

// final review I5: values are capped to their column widths
$store = new MemStore();
$long = new BackupObject('k', str_repeat('n', 300), str_repeat('1', 300), 'vm', str_repeat('j', 400), 1, 1, str_repeat('9', 100), false, false);
$svc->run($conn, new FakeSource([$long]), $store, $m, 9000);
$row = $store->rows['1|k'];
check('job_name capped at 255', mb_strlen($row['job_name']) === 255);
check('name capped at 255', mb_strlen($row['name']) === 255);
check('ip capped at 255', mb_strlen($row['ip']) === 255);
check('data_size capped at 64', mb_strlen($row['data_size']) === 64);

// manual pin kept, not re-matched
$store = new MemStore();
$store->upsert(1, ['source_key' => 'a', 'name' => 'web1', 'ip' => '', 'kind' => 'vm', 'job_name' => '', 'last_backup' => 1, 'restore_points' => 0,
    'data_size' => '0', 'abnormal_chain' => 0, 'source_deleted' => 0, 'itemtype' => 'Computer', 'items_id' => 99, 'match_state' => 'manual']);
$r = $svc->run($conn, new FakeSource([obj('a', 'web1')]), $store, $m, 5000);
check('manual match kept', $store->rows['1|a']['items_id'] === 99 && $store->rows['1|a']['match_state'] === 'manual');
check('manual counted matched, not affected', $r['matched'] === 1 && $r['affected'] === []);

// changed match is affected (old and new)
$store = new MemStore();
$svc->run($conn, new FakeSource([obj('a', 'web1')]), $store, new Matcher(new FakeLookup(['web1' => [7]])), 1);
$r = $svc->run($conn, new FakeSource([obj('a', 'web1')]), $store, new Matcher(new FakeLookup(['web1' => [8]])), 2);
check('changed match reports old and new', in_array(7, $r['affected'], true) && in_array(8, $r['affected'], true));

// upsert refreshes values
$svc->run($conn, new FakeSource([obj('a', 'web1', '', 'vm', 4242)]), $store, new Matcher(new FakeLookup(['web1' => [8]])), 3);
check('values refreshed', $store->rows['1|a']['last_backup'] === 4242);

// applyToAssets
$store = new MemStore();
$svc->run($conn, new FakeSource([obj('a', 'web1', '', 'vm', 1000), obj('b', 'web1', '', 'vm', 90000)]), $store, $m, 1);
$store->rows['1|a']['source_label'] = 'Vinchin';
$store->rows['1|b']['source_label'] = 'Vinchin';
$f = new FakeBridge();
$n = $svc->applyToAssets($store, $f, 26, 91000, [5]);
check('writes matched computer and extra only', count($f->writes) === 2 && $n === 2, json_encode($f->writes));
$byId = [];
foreach ($f->writes as $w) { $byId[$w[1]] = $w; }
check('matched computer protected with media', $byId[7][2] === true && str_starts_with($byId[7][3], 'Vinchin'), json_encode($byId[7] ?? null));
check('extra item with no objects: false and blank media', $byId[5][2] === false && $byId[5][3] === '');
$f = new FakeBridge();
$svc->applyToAssets($store, $f, 26, 91000);
check('never-matched computers untouched', count($f->writes) === 1 && $f->writes[0][1] === 7);
$f = new FakeBridge();
$f->ok = false;
check('fields unavailable writes nothing', $svc->applyToAssets($store, $f, 26, 91000, [5]) === 0 && $f->writes === []);

// error line
$line = SyncService::errorLine(new RuntimeException("a\nb\r\n" . str_repeat('x', 400)));
check('errorLine one line, capped', strlen($line) <= 255 && !str_contains($line, "\n") && !str_contains($line, "\r"));

// runAll: first source throws, second still syncs, first rows intact
$store = new MemStore();
$svc->run(['id' => 1], new FakeSource([obj('a', 'web1')]), $store, $m, 1);
$sources = [1 => new ThrowingSource(), 2 => new FakeSource([obj('z', 'web1')])];
$out = $svc->runAll([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']], fn ($c) => $sources[$c['id']], $store, $m, 2);
check('failing connection reported as error', $out['results'][0]['status'] === 'error' && $out['results'][0]['error'] === 'down');
check('error is one line', !str_contains($out['results'][0]['error'], "
"));
check('second connection still synced', $out['results'][1]['status'] === 'ok' && isset($store->rows['2|z']) && $out['results'][1]['stats']['fetched'] === 1);
check('failed connection rows kept (no stale delete)', isset($store->rows['1|a']));
check('affected includes second connection match', in_array(7, $out['affected'], true));
$out = $svc->runAll([['id' => 1]], function ($c) { throw new RuntimeException('bad key abc'); }, $store, $m, 3);
check('factory throw is caught too', $out['results'][0]['status'] === 'error' && isset($store->rows['1|a']));

// inactive connection: purged, item written (false, '')
$store = new MemStore();
$svc->run(['id' => 1], new FakeSource([obj('a', 'web1')]), $store, $m, 1);
$svc->run(['id' => 2], new FakeSource([obj('b', 'dup'), obj('c', 'x', '10.0.0.5')]), $store, $m, 1);
$store->rows['1|a']['source_label'] = 'Vinchin';
$store->active = [2];
$out = $svc->runAll([], fn ($c) => new FakeSource([]), $store, $m, 2);
check('inactive connection rows purged', !isset($store->rows['1|a']) && isset($store->rows['2|c']));
check('purged item affected', $out['affected'] === [7]);
$f = new FakeBridge();
$svc->applyToAssets($store, $f, 26, 3, $out['affected']);
$w = array_values(array_filter($f->writes, fn ($x) => $x[1] === 7));
check('item left without objects gets write(false, "")', count($w) === 1 && $w[0][2] === false && $w[0][3] === '');
$store->active = [];
check('matched() ignores inactive connections', $store->matched() === []);

// cron result mapping
$ok = ['status' => 'ok']; $bad = ['status' => 'error'];
check('cronResult all failed -1', SyncService::cronResult([$bad, $bad], 0) === -1);
check('cronResult one ok 1', SyncService::cronResult([$bad, $ok], 0) === 1);
check('cronResult applied 1', SyncService::cronResult([$bad], 2) === 1);
check('cronResult nothing 0', SyncService::cronResult([], 0) === 0);

done();
