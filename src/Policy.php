<?php

namespace GlpiPlugin\Backupinfo;

use Config;
use GlpiPlugin\Backupinfo\Fields\FieldsBridge;
use GlpiPlugin\Backupinfo\Sync\DbObjectStore;

/**
 * Which Computers must be backed up, and the coverage numbers for the Coverage page.
 *
 * Mode 'field' (default): the human-set "Wajib Backup" Fields value. Mode 'manual': the
 * plugin's own list (glpi_plugin_backupinfo_policies). setManual() needs config UPDATE;
 * the caller checks rights, Policy does not.
 */
final class Policy
{
    public const MODE_FIELD  = 'field';
    public const MODE_MANUAL = 'manual';
    public const ITEMTYPE    = 'Computer';

    private const TABLE = 'glpi_plugin_backupinfo_policies';

    /** @var list<int>|null */
    private ?array $manualCache = null;

    private string $mode;

    /** @var (callable(): list<int>)|null source of the manual list; default reads the table */
    private $manualIds;

    /** @param (callable(): list<int>)|null $manualIds */
    public function __construct(string $mode, private FieldsBridge $bridge, ?callable $manualIds = null)
    {
        $this->mode      = $mode === self::MODE_MANUAL ? self::MODE_MANUAL : self::MODE_FIELD;
        $this->manualIds = $manualIds;
    }

    public static function fromConfig(): self
    {
        $cfg = Config::getConfigurationValues('plugin:backupinfo', ['policy_mode']);
        return new self((string) ($cfg['policy_mode'] ?? self::MODE_FIELD), FieldsBridge::fromConfig());
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function isMandatory(string $itemtype, int $id): bool
    {
        if ($itemtype !== self::ITEMTYPE || $id <= 0) {
            return false;
        }
        if ($this->mode === self::MODE_MANUAL) {
            return in_array($id, $this->manualList(), true);
        }
        return $this->bridge->readMandatory($itemtype, $id) === true;
    }

    /**
     * Computer ids that must be backed up, whatever the mode (not yet entity-filtered).
     *
     * @return list<int>
     */
    public function mandatoryIds(): array
    {
        return $this->mode === self::MODE_MANUAL ? $this->manualList() : $this->bridge->mandatoryIds(self::ITEMTYPE);
    }

    /** Replaces the manual Computer list (ids cast to int, duplicates and non-positive dropped). */
    public static function setManual(array $ids): void
    {
        global $DB;

        $clean = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        $DB->delete(self::TABLE, ['itemtype' => self::ITEMTYPE]);
        foreach ($clean as $id) {
            $DB->insert(self::TABLE, ['itemtype' => self::ITEMTYPE, 'items_id' => $id]);
        }
    }

    /**
     * Mandatory Computers visible in the user's active entities (not deleted, not template),
     * bucketed by backup status of their matched objects (active connections only).
     *
     * @return array{mandatory:int,protected:int,expired:int,none:int}
     */
    public function coverage(): array
    {
        $ids = $this->visible($this->mandatoryIds());
        if ($ids === []) {
            return self::countCoverage([], [], Rpo::DEFAULT_GLOBAL, time());
        }

        return self::countCoverage(
            $ids,
            self::groupByItem((new DbObjectStore())->matched()),
            Rpo::fromConfig(),
            time()
        );
    }

    /**
     * Pure counting.
     *
     * @param list<int>                              $mandatoryIds
     * @param array<int, list<array<string, mixed>>> $objectsByItem matched objects keyed by Computer id
     * @return array{mandatory:int,protected:int,expired:int,none:int}
     */
    public static function countCoverage(array $mandatoryIds, array $objectsByItem, int|Rpo $rpoHours, int $nowS): array
    {
        $out = ['mandatory' => 0, 'protected' => 0, 'expired' => 0, 'none' => 0];
        foreach (array_unique(array_map('intval', $mandatoryIds)) as $id) {
            $out['mandatory']++;
            $out[Status::of($objectsByItem[$id] ?? [], $rpoHours, $nowS)]++;
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, list<array<string, mixed>>>
     */
    public static function groupByItem(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) ($row['items_id'] ?? 0)][] = $row;
        }
        return $out;
    }

    /** @return list<int> */
    private function manualList(): array
    {
        if ($this->manualCache === null) {
            $this->manualCache = $this->manualIds !== null
                ? array_values(array_map('intval', ($this->manualIds)()))
                : self::readManual();
        }
        return $this->manualCache;
    }

    /** @return list<int> */
    private static function readManual(): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request(['SELECT' => ['items_id'], 'FROM' => self::TABLE, 'WHERE' => ['itemtype' => self::ITEMTYPE]]) as $row) {
            $ids[] = (int) $row['items_id'];
        }
        return $ids;
    }

    /**
     * Keeps the ids that are real, non-deleted, non-template Computers in the active entities.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function visible(array $ids): array
    {
        global $DB;

        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => [
                    'id'          => $chunk,
                    'is_deleted'  => 0,
                    'is_template' => 0,
                ] + getEntitiesRestrictCriteria('glpi_computers'),
            ]) as $row) {
                $out[] = (int) $row['id'];
            }
        }
        return $out;
    }
}
