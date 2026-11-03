<?php

namespace GlpiPlugin\Backupinfo\Fields;

use Plugin;
use Toolbox;

/**
 * FieldsGateway over the Fields plugin's own classes (1.23.x). Never writes a
 * glpi_plugin_fields_* table directly (hub §0 rule 5) and never creates a container
 * or a field. Fields classes are global and loaded only when Fields is active, so
 * they are referenced by string name.
 */
final class GlpiFieldsGateway implements FieldsGateway
{
    public function pluginActive(): bool
    {
        return class_exists(Plugin::class)
            && Plugin::isPluginActive('fields')
            && class_exists('PluginFieldsContainer')
            && class_exists('PluginFieldsField');
    }

    public function containers(): array
    {
        $out = [];
        foreach ((new \PluginFieldsContainer())->find([], ['id ASC']) as $row) {
            $itemtypes = \PluginFieldsToolbox::decodeJSONItemtypes((string) ($row['itemtypes'] ?? ''), true);
            $out[] = [
                'id'        => (int) $row['id'],
                'name'      => (string) $row['name'],
                'label'     => (string) $row['label'],
                'itemtypes' => is_array($itemtypes) ? array_values(array_map('strval', $itemtypes)) : [],
                'is_active' => (int) $row['is_active'] === 1,
            ];
        }
        return $out;
    }

    public function fields(int $containerId): array
    {
        $out = [];
        foreach ((new \PluginFieldsField())->find(['plugin_fields_containers_id' => $containerId], ['ranking ASC']) as $row) {
            $out[] = [
                'id'        => (int) $row['id'],
                'name'      => (string) $row['name'],
                'label'     => (string) $row['label'],
                'type'      => (string) $row['type'],
                'is_active' => (int) $row['is_active'] === 1,
                'multiple'  => (int) ($row['multiple'] ?? 0) === 1,
            ];
        }
        return $out;
    }

    public function currentValues(string $itemtype, string $containerName, int $itemsId): ?array
    {
        $class = \PluginFieldsContainer::getClassname($itemtype, $containerName);
        if (!class_exists($class)) {
            throw new \RuntimeException("Fields class {$class} is not loadable");
        }
        $row = new $class();
        return $row->getFromDBByCrit(['items_id' => $itemsId]) ? $row->fields : null;
    }

    public function itemIdsWithValue(string $itemtype, string $containerName, string $column, int $value): array
    {
        $class = \PluginFieldsContainer::getClassname($itemtype, $containerName);
        if (!class_exists($class)) {
            throw new \RuntimeException("Fields class {$class} is not loadable");
        }
        $ids = [];
        foreach ((new $class())->find([$column => $value]) as $row) {
            $ids[] = (int) $row['items_id'];
        }
        return $ids;
    }

    public function findDropdownEntry(string $fieldName, string $value): int
    {
        foreach ($this->dropdown($fieldName)->find(['name' => $value], ['id ASC'], 1) as $row) {
            return (int) $row['id'];
        }
        return 0;
    }

    public function createDropdownEntry(string $fieldName, string $value): int
    {
        $id = $this->dropdown($fieldName)->add(['name' => $value, 'entities_id' => 0, 'is_recursive' => 1]);
        return $id === false ? 0 : (int) $id;
    }

    public function itemEntity(string $itemtype, int $id): ?int
    {
        $item = getItemForItemtype($itemtype);
        if ($item === false || !$item->getFromDB($id)) {
            return null;
        }
        return (int) $item->getEntityID();
    }

    public function update(array $data, string $itemtype): bool
    {
        // massiveaction = true: validateValues() checks only the keys sent (container.class.php:1703).
        return (new \PluginFieldsContainer())->updateFieldsValues($data, $itemtype, true) === true;
    }

    public function log(string $message): void
    {
        Toolbox::logInFile('backupinfo', $message . PHP_EOL);
    }

    private function dropdown(string $fieldName): \CommonDBTM
    {
        $class = \PluginFieldsDropdown::getClassname($fieldName);
        if (!class_exists($class)) {
            throw new \RuntimeException("Fields dropdown class {$class} is not loadable");
        }
        return new $class();
    }
}
