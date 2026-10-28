<?php

namespace GlpiPlugin\Backupinfo\Matching;

use CommonDBTM;
use Computer;

/**
 * AssetLookup against the GLPI database, Computers only (not deleted, not template).
 * Name matching relies on the database collation (GLPI's utf8mb4 *_unicode_ci), so it
 * is case-insensitive. Deliberately NOT entity-scoped: it runs from cron, where core
 * would fall back to the root entity (hub §4).
 */
final class DbAssetLookup implements AssetLookup
{
    private const ITEMTYPE = 'Computer';

    public function byName(string $name): array
    {
        global $DB;

        $table = Computer::getTable();
        $hits  = [];
        foreach ($DB->request([
            'SELECT' => [$table . '.id'],
            'FROM'   => $table,
            'WHERE'  => [
                $table . '.name'        => $name,
                $table . '.is_deleted'  => 0,
                $table . '.is_template' => 0,
            ],
        ]) as $row) {
            $hits[] = ['itemtype' => self::ITEMTYPE, 'items_id' => (int) $row['id']];
        }
        return $hits;
    }

    /**
     * Every live owner of the IP is counted, whatever its type - an IP a Computer
     * shares with a Printer is ambiguous. An owner whose class cannot be loaded here is
     * still counted: its liveness is unknown, and guessing it away could create a false
     * unique match.
     */
    public function byIp(string $ip): array
    {
        global $DB;

        $owners = [];
        foreach ($DB->request([
            'SELECT' => ['mainitemtype', 'mainitems_id'],
            'FROM'   => 'glpi_ipaddresses',
            'WHERE'  => [
                'name'         => $ip,
                'is_deleted'   => 0,
                'mainitemtype' => ['<>', ''],
                'mainitems_id' => ['>', 0],
            ],
        ]) as $row) {
            $itemtype = (string) $row['mainitemtype'];
            $item     = getItemForItemtype($itemtype);
            if ($item instanceof CommonDBTM) {
                if (!$item->getFromDB((int) $row['mainitems_id'])) {
                    continue;
                }
                if (!empty($item->fields['is_deleted']) || !empty($item->fields['is_template'])) {
                    continue;
                }
            }
            $owners[] = ['itemtype' => $itemtype, 'items_id' => (int) $row['mainitems_id']];
        }
        return self::ipHits($owners);
    }

    /**
     * Pure. Several distinct owners: all of them (the Matcher treats >1 as ambiguous).
     * Exactly one owner: it, if it is a Computer, else nothing.
     *
     * @param list<array{itemtype: string, items_id: int}> $owners
     * @return list<array{itemtype: string, items_id: int}>
     */
    public static function ipHits(array $owners): array
    {
        $distinct = [];
        foreach ($owners as $owner) {
            $distinct[$owner['itemtype'] . '#' . $owner['items_id']] = $owner;
        }
        $distinct = array_values($distinct);
        if (count($distinct) !== 1) {
            return $distinct;
        }
        return $distinct[0]['itemtype'] === self::ITEMTYPE ? $distinct : [];
    }
}
