<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/Fields/FieldsGateway.php';
require BI_ROOT . '/src/Fields/FieldsBridge.php';

use GlpiPlugin\Backupinfo\Fields\FieldsBridge;
use GlpiPlugin\Backupinfo\Fields\FieldsGateway;

// Fake Fields plugin: records every write, create and log line.
final class FakeFieldsGateway implements FieldsGateway
{
    public bool $active = true;
    public array $containerRows = [];
    public array $fieldRows = [];          // containerId => list<field row>
    public array $rows = [];               // "itemtype|container|id" => row
    public array $dropdown = [];           // fieldName => [id => name]
    public array $entities = ['Computer|7' => 3];
    public array $updates = [];
    public array $creates = [];
    public array $logs = [];
    public bool $throwOnUpdate = false;

    public function pluginActive(): bool { return $this->active; }
    public function containers(): array { return $this->containerRows; }
    public function fields(int $containerId): array { return $this->fieldRows[$containerId] ?? []; }
    public function currentValues(string $itemtype, string $containerName, int $itemsId): ?array
    {
        return $this->rows["$itemtype|$containerName|$itemsId"] ?? null;
    }
    public function findDropdownEntry(string $fieldName, string $value): int
    {
        foreach ($this->dropdown[$fieldName] ?? [] as $id => $name) {
            if ($name === $value) {
                return $id;
            }
        }
        return 0;
    }
    public function createDropdownEntry(string $fieldName, string $value): int
    {
        $this->creates[] = [$fieldName, $value];
        $id = 100 + count($this->creates);
        $this->dropdown[$fieldName][$id] = $value;
        return $id;
    }
    public function itemEntity(string $itemtype, int $id): ?int { return $this->entities["$itemtype|$id"] ?? null; }
    public function update(array $data, string $itemtype): bool
    {
        if ($this->throwOnUpdate) {
            throw new RuntimeException('boom');
        }
        $this->updates[] = [$data, $itemtype];
        return true;
    }
    public function log(string $message): void { $this->logs[] = $message; }
}

function gw(): FakeFieldsGateway
{
    $g = new FakeFieldsGateway();
    $g->containerRows = [
        ['id' => 5, 'name' => 'backupinformation', 'label' => 'Backup Information', 'itemtypes' => ['Computer'], 'is_active' => true],
        ['id' => 6, 'name' => 'netinfo', 'label' => 'Network', 'itemtypes' => ['NetworkEquipment'], 'is_active' => true],
    ];
    $g->fieldRows[5] = [
        ['id' => 51, 'name' => 'backupstatusfield', 'label' => 'Backup Status', 'type' => 'yesno', 'is_active' => true, 'multiple' => false],
        ['id' => 52, 'name' => 'backupmediacontentfield', 'label' => 'Backup Media - Content', 'type' => 'dropdown', 'is_active' => true, 'multiple' => false],
        ['id' => 53, 'name' => 'wajibbackupfield', 'label' => 'Wajib Backup', 'type' => 'yesno', 'is_active' => true, 'multiple' => false],
    ];
    $g->dropdown['backupmediacontentfield'] = [11 => 'Vinchin · VM · DAILY'];
    return $g;
}

function cfg(array $over = []): array
{
    return $over + [
        'fields_container'       => 'backupinformation',
        'fields_status_field'    => 'backupstatusfield',
        'fields_media_field'     => 'backupmediacontentfield',
        'fields_mandatory_field' => 'wajibbackupfield',
    ];
}

const ROW = 'Computer|backupinformation|7';

// --- available() (Review Focus 5) ---
$g = gw();
check('available with plugin active and full config', (new FieldsBridge(cfg(), $g))->available());

$g = gw();
$g->active = false;
$b = new FieldsBridge(cfg(), $g);
check('available() false when Fields inactive', !$b->available());
check('inactive plugin is named in problems()', count($b->problems()) === 1 && str_contains($b->problems()[0], 'not active'));
check('inactive plugin: write does nothing', $b->write('Computer', 7, true, 'X') === false && $g->updates === [] && $g->creates === []);
check('inactive plugin: readMandatory is null', $b->readMandatory('Computer', 7) === null);

foreach (['fields_container', 'fields_status_field', 'fields_media_field'] as $key) {
    $g = gw();
    $b = new FieldsBridge(cfg([$key => '']), $g);
    check("available() false when $key is empty", !$b->available());
    check("$key empty is named in problems()", implode(' ', $b->problems()) !== '');
}

$g = gw();
$b = new FieldsBridge(cfg(['fields_container' => 'nosuch']), $g);
check('unknown container: unavailable', !$b->available());
check('unknown container: write skipped, warned, nothing created',
    $b->write('Computer', 7, true, 'Vinchin · VM · NEW') === false
    && $g->updates === [] && $g->creates === [] && count($g->logs) === 1);

$g = gw();
$b = new FieldsBridge(cfg(['fields_container' => 'netinfo']), $g);
check('container not attached to Computer is a problem', !$b->available());

$g = gw();
$g->containerRows[0]['is_active'] = false;
$b = new FieldsBridge(cfg(), $g);
check('inactive container: unavailable, write skipped', !$b->available() && $b->write('Computer', 7, true, 'x') === false && $g->updates === []);

check('container resolves by label too', (new FieldsBridge(cfg(['fields_container' => 'Backup Information']), gw()))->available());
check('field resolves by label too', (new FieldsBridge(cfg(['fields_status_field' => 'Backup Status']), gw()))->available());

// --- missing / inactive field: skipped with one warning per run, no write for it, no create ---
$g = gw();
$g->fieldRows[5][1]['is_active'] = false;   // media inactive
$b = new FieldsBridge(cfg(), $g);
check('inactive media field: unavailable', !$b->available());
$ok = $b->write('Computer', 7, true, 'Vinchin · VM · NEW');
check('inactive media field: status still written, media key absent',
    $ok && count($g->updates) === 1 && !array_key_exists('plugin_fields_backupmediacontentfielddropdowns_id', $g->updates[0][0])
    && $g->updates[0][0]['backupstatusfield'] === 1);
check('inactive media field: no dropdown entry created', $g->creates === []);
check('inactive media field: one warning logged', count($g->logs) === 1 && str_contains($g->logs[0], 'backupmediacontentfield'));
$b->write('Computer', 7, true, 'Vinchin · VM · NEW');
check('warning not repeated in the same run', count($g->logs) === 1);

$g = gw();
$b = new FieldsBridge(cfg(['fields_status_field' => 'gone']), $g);
$g->rows[ROW] = ['plugin_fields_backupmediacontentfielddropdowns_id' => '11'];
check('missing status field and unchanged media: no write at all',
    $b->write('Computer', 7, true, 'Vinchin · VM · DAILY') === false && $g->updates === [] && count($g->logs) === 1);

$g = gw();
$g->fieldRows[5][0]['type'] = 'text';
$b = new FieldsBridge(cfg(), $g);
check('status field that is not yes/no is a problem', !$b->available() && str_contains(implode(' ', $b->problems()), 'Yes/No'));

$g = gw();
$g->fieldRows[5][1]['multiple'] = true;
check('multiple dropdown media field is a problem', !(new FieldsBridge(cfg(), $g))->available());

$g = gw();
$g->fieldRows[5][1]['type'] = 'number';
check('media field of an unsupported type is a problem', !(new FieldsBridge(cfg(), $g))->available());

// --- write only on change ---
$g = gw();
$g->rows[ROW] = ['backupstatusfield' => '1', 'plugin_fields_backupmediacontentfielddropdowns_id' => '11'];
$b = new FieldsBridge(cfg(), $g);
check('unchanged values: no write', $b->write('Computer', 7, true, 'Vinchin · VM · DAILY') === false && $g->updates === [] && $g->creates === []);
check('unchanged values: nothing logged', $g->logs === []);

$g = gw();
$g->rows[ROW] = ['backupstatusfield' => '1', 'plugin_fields_backupmediacontentfielddropdowns_id' => '11'];
$b = new FieldsBridge(cfg(), $g);
check('status change only: write', $b->write('Computer', 7, false, 'Vinchin · VM · DAILY') === true);
$d = $g->updates[0][0];
check('write sends only the changed key plus identity', $d === [
    'plugin_fields_containers_id' => 5,
    'items_id'                    => 7,
    'itemtype'                    => 'Computer',
    'entities_id'                 => 3,
    'backupstatusfield'           => 0,
], json_encode($d));
check('write passes the itemtype', $g->updates[0][1] === 'Computer');

$g = gw();
$g->rows[ROW] = ['backupstatusfield' => '1', 'plugin_fields_backupmediacontentfielddropdowns_id' => '11'];
$b = new FieldsBridge(cfg(), $g);
$b->write('Computer', 7, true, 'Vinchin · VM · WEEKLY');
check('new media text: dropdown entry created once', $g->creates === [['backupmediacontentfield', 'Vinchin · VM · WEEKLY']]);
check('new media text: written as dropdown id, status untouched',
    ($g->updates[0][0]['plugin_fields_backupmediacontentfielddropdowns_id'] ?? null) === 101
    && !array_key_exists('backupstatusfield', $g->updates[0][0]));

$g = gw();
$g->rows[ROW] = ['backupstatusfield' => '0', 'plugin_fields_backupmediacontentfielddropdowns_id' => '0'];
$b = new FieldsBridge(cfg(), $g);
$b->write('Computer', 7, true, 'Vinchin · VM · DAILY');
check('existing dropdown entry reused, not created', $g->creates === []
    && $g->updates[0][0]['plugin_fields_backupmediacontentfielddropdowns_id'] === 11);

$g = gw();
$g->rows[ROW] = ['backupstatusfield' => '1', 'plugin_fields_backupmediacontentfielddropdowns_id' => '11'];
$b = new FieldsBridge(cfg(), $g);
check('empty media leaves the field as is', $b->write('Computer', 7, true, '  ') === false && $g->updates === [] && $g->creates === []);

$g = gw();
$b = new FieldsBridge(cfg(), $g);
check('no Fields row yet: write both values', $b->write('Computer', 7, false, 'Vinchin · VM · DAILY') === true
    && $g->updates[0][0]['backupstatusfield'] === 0
    && $g->updates[0][0]['plugin_fields_backupmediacontentfielddropdowns_id'] === 11);

// text media field
$g = gw();
$g->fieldRows[5][1]['type'] = 'text';
$g->rows[ROW] = ['backupstatusfield' => '1', 'backupmediacontentfield' => 'Vinchin · VM · DAILY'];
$b = new FieldsBridge(cfg(), $g);
check('text media field is supported', $b->available());
check('text media unchanged: no write', $b->write('Computer', 7, true, 'Vinchin · VM · DAILY') === false && $g->updates === []);
$b->write('Computer', 7, true, 'Vinchin · VM · WEEKLY');
check('text media changed: written as text, no dropdown create',
    ($g->updates[0][0]['backupmediacontentfield'] ?? null) === 'Vinchin · VM · WEEKLY' && $g->creates === []);

// item not found / gateway fault
$g = gw();
$b = new FieldsBridge(cfg(), $g);
check('unknown item: no write', $b->write('Computer', 999, true, 'x') === false && $g->updates === []);
$g = gw();
$g->throwOnUpdate = true;
$b = new FieldsBridge(cfg(), $g);
check('fault during write is caught and logged', $b->write('Computer', 7, true, 'x') === false && count($g->logs) === 1);

// --- readMandatory ---
$g = gw();
$b = new FieldsBridge(cfg(), $g);
check('mandatory: no row is null', $b->readMandatory('Computer', 7) === null);
$g->rows[ROW] = ['wajibbackupfield' => '1'];
check('mandatory: 1 is true', $b->readMandatory('Computer', 7) === true);
$g->rows[ROW] = ['wajibbackupfield' => '0'];
check('mandatory: 0 is false', $b->readMandatory('Computer', 7) === false);
$g->rows[ROW] = ['wajibbackupfield' => null];
check('mandatory: empty is null', $b->readMandatory('Computer', 7) === null);
$g = gw();
$g->rows[ROW] = ['wajibbackupfield' => '1'];
check('mandatory: unset config is null', (new FieldsBridge(cfg(['fields_mandatory_field' => '']), $g))->readMandatory('Computer', 7) === null);
check('mandatory: missing field is null', (new FieldsBridge(cfg(['fields_mandatory_field' => 'gone']), $g))->readMandatory('Computer', 7) === null);
check('mandatory field never written', $g->updates === []);
check('mandatory config not required for available()', (new FieldsBridge(cfg(['fields_mandatory_field' => '']), gw()))->available());

// --- Setup helpers ---
$b = new FieldsBridge(cfg(), gw());
check('containers() lists only Computer containers', $b->containers() === [5 => 'Backup Information']);
check('fields() lists name => label', $b->fields(5) === [
    'backupstatusfield'       => 'Backup Status',
    'backupmediacontentfield' => 'Backup Media - Content',
    'wajibbackupfield'        => 'Wajib Backup',
]);
$g = gw();
$g->active = false;
check('helpers empty when Fields inactive', (new FieldsBridge(cfg(), $g))->containers() === [] && (new FieldsBridge(cfg(), $g))->fields(5) === []);

done();
