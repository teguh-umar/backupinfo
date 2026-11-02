<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/Fields/FieldsGateway.php';
require BI_ROOT . '/src/Fields/FieldsBridge.php';
require BI_ROOT . '/src/Rpo.php';
require BI_ROOT . '/src/Status.php';
require BI_ROOT . '/src/Policy.php';

use GlpiPlugin\Backupinfo\Fields\FieldsBridge;
use GlpiPlugin\Backupinfo\Fields\FieldsGateway;
use GlpiPlugin\Backupinfo\Policy;

final class PolGateway implements FieldsGateway
{
    public bool $active = true;
    public array $mandatory = [7, 8];
    public array $logs = [];
    public array $updates = [];
    public array $idQueries = [];
    public bool $fieldMissing = false;
    public function pluginActive(): bool { return $this->active; }
    public function containers(): array
    {
        return [['id' => 5, 'name' => 'backupinformation', 'label' => 'Backup Information', 'itemtypes' => ['Computer'], 'is_active' => true]];
    }
    public function fields(int $containerId): array
    {
        if ($this->fieldMissing) {
            return [];
        }
        return [['id' => 53, 'name' => 'wajibbackupfield', 'label' => 'Wajib Backup', 'type' => 'yesno', 'is_active' => true, 'multiple' => false]];
    }
    public function currentValues(string $itemtype, string $containerName, int $itemsId): ?array
    {
        if ($itemsId === 7) {
            return ['wajibbackupfield' => '1'];
        }
        if ($itemsId === 9) {
            return ['wajibbackupfield' => '0'];
        }
        return null; // 10: unset
    }
    public function itemIdsWithValue(string $itemtype, string $containerName, string $column, int $value): array
    {
        $this->idQueries[] = [$itemtype, $containerName, $column, $value];
        return $this->mandatory;
    }
    public function findDropdownEntry(string $fieldName, string $value): int { return 0; }
    public function createDropdownEntry(string $fieldName, string $value): int { return 0; }
    public function itemEntity(string $itemtype, int $id): ?int { return 1; }
    public function update(array $data, string $itemtype): bool { $this->updates[] = $data; return true; }
    public function log(string $message): void { $this->logs[] = $message; }
}

$cfg = [
    'fields_container' => 'backupinformation', 'fields_status_field' => 'x', 'fields_media_field' => 'y',
    'fields_mandatory_field' => 'wajibbackupfield',
];

// --- isMandatory: mode switch ---
$g = new PolGateway();
$fieldPolicy = new Policy('field', new FieldsBridge($cfg, $g), static fn (): array => [10]);
check('field mode: Fields yes -> mandatory', $fieldPolicy->isMandatory('Computer', 7));
check('field mode: Fields no -> not mandatory', !$fieldPolicy->isMandatory('Computer', 9));
check('field mode: Fields unset -> not mandatory', !$fieldPolicy->isMandatory('Computer', 10));
check('field mode ignores the manual list', !$fieldPolicy->isMandatory('Computer', 11));

$manual = new Policy('manual', new FieldsBridge($cfg, $g), static fn (): array => [10, 11]);
check('manual mode: listed -> mandatory', $manual->isMandatory('Computer', 10) && $manual->isMandatory('Computer', 11));
check('manual mode: Fields yes but unlisted -> not mandatory', !$manual->isMandatory('Computer', 7));
check('manual mode: non-Computer never mandatory', !$manual->isMandatory('Printer', 10));
check('unknown mode falls back to field', (new Policy('bogus', new FieldsBridge($cfg, $g), static fn (): array => []))->isMandatory('Computer', 7));

// --- mandatoryIds ---
$g = new PolGateway();
$b = new FieldsBridge($cfg, $g);
check('mandatoryIds reads the yes column = 1', $b->mandatoryIds() === [7, 8] && $g->idQueries === [['Computer', 'backupinformation', 'wajibbackupfield', 1]]);
check('mandatoryIds never writes', $g->updates === []);

$g = new PolGateway();
$g->active = false;
$b = new FieldsBridge($cfg, $g);
check('mandatoryIds [] when Fields inactive', $b->mandatoryIds() === [] && $g->idQueries === []);

$g = new PolGateway();
$g->fieldMissing = true;
$b = new FieldsBridge($cfg, $g);
check('mandatoryIds [] + warning when field missing', $b->mandatoryIds() === [] && count($g->logs) === 1 && $g->idQueries === []);

$g = new PolGateway();
check('mandatoryIds [] when mandatory field unset', (new FieldsBridge(['fields_mandatory_field' => ''] + $cfg, $g))->mandatoryIds() === [] && $g->idQueries === []);

$g = new PolGateway();
$g->mandatory = [8, 7, 8, 0];
check('mandatoryIds sorted, unique, positive', (new FieldsBridge($cfg, $g))->mandatoryIds() === [7, 8]);

// --- countCoverage (pure) ---
$now = 1_000_000;
$fresh = $now - 3600;
$old = $now - 100 * 3600;
$objects = [
    1 => [['last_backup' => $fresh]],
    2 => [['last_backup' => $old], ['last_backup' => null]],
    3 => [],
    5 => [['last_backup' => null]],
    99 => [['last_backup' => $fresh]], // not mandatory: ignored
];
$c = Policy::countCoverage([1, 2, 3, 4, 5], $objects, 26, $now);
check('countCoverage buckets', $c === ['mandatory' => 5, 'protected' => 1, 'expired' => 2, 'none' => 2], json_encode($c));
check('countCoverage empty', Policy::countCoverage([], $objects, 26, $now) === ['mandatory' => 0, 'protected' => 0, 'expired' => 0, 'none' => 0]);
check('countCoverage honours rpo', Policy::countCoverage([2], $objects, 200, $now)['protected'] === 1);
$c = Policy::countCoverage([1, 1, 2], $objects, 26, $now);
check('countCoverage dedups ids', $c['mandatory'] === 2);

check('groupByItem groups matched rows', Policy::groupByItem([
    ['items_id' => 1, 'last_backup' => 5], ['items_id' => 1, 'last_backup' => 6], ['items_id' => 2, 'last_backup' => 7],
]) === [1 => [['items_id' => 1, 'last_backup' => 5], ['items_id' => 1, 'last_backup' => 6]], 2 => [['items_id' => 2, 'last_backup' => 7]]]);

done();
