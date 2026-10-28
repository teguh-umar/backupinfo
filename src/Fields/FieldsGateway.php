<?php

namespace GlpiPlugin\Backupinfo\Fields;

/**
 * The thin edge between FieldsBridge and the third-party Fields plugin.
 * GlpiFieldsGateway talks to Fields' own classes; tests use a fake.
 * Nothing here ever creates a container or a field.
 */
interface FieldsGateway
{
    public function pluginActive(): bool;

    /** @return list<array{id:int,name:string,label:string,itemtypes:list<string>,is_active:bool}> */
    public function containers(): array;

    /** @return list<array{id:int,name:string,label:string,type:string,is_active:bool,multiple:bool}> */
    public function fields(int $containerId): array;

    /**
     * Current row of the generated container class for one item, raw DB values (strings).
     *
     * @return array<string,mixed>|null null when the item has no row yet
     */
    public function currentValues(string $itemtype, string $containerName, int $itemsId): ?array;

    /**
     * Ids of the items of $itemtype whose row in the generated container class has $column = $value.
     * Read-only.
     *
     * @return list<int>
     */
    public function itemIdsWithValue(string $itemtype, string $containerName, string $column, int $value): array;

    /** Id of the entry named $value in the dropdown of field $fieldName, 0 when absent. */
    public function findDropdownEntry(string $fieldName, string $value): int;

    /** Adds an entry to an EXISTING dropdown field; returns its id, 0 on failure. */
    public function createDropdownEntry(string $fieldName, string $value): int;

    /** Entity of the item, null when the item does not exist. */
    public function itemEntity(string $itemtype, int $id): ?int;

    /** PluginFieldsContainer::updateFieldsValues($data, $itemtype, true). */
    public function update(array $data, string $itemtype): bool;

    public function log(string $message): void;
}
