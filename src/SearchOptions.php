<?php

namespace GlpiPlugin\Backupinfo;

/**
 * Search options added to Computer (IDs 8370-8379, claimed in hub §2, 2026-10-28).
 * Pure: hook.php's plugin_backupinfo_getAddSearchOptionsNew() checks the right and
 * returns these.
 *
 * The backup status itself depends on the RPO at query time, so search offers its two
 * SQL-searchable halves instead: "Backup objects" (count; > 0 = Backup Status Yes)
 * and "Last backup" (datetime; e.g. "after -26 hours" = protected).
 */
final class SearchOptions
{
    public const TABLE = 'glpi_plugin_backupinfo_objects';

    /** @return list<array<string, mixed>> */
    public static function forItemtype(string $itemtype): array
    {
        if ($itemtype !== 'Computer') {
            return [];
        }

        $base = [
            'table'         => self::TABLE,
            'forcegroupby'  => true,
            'massiveaction' => false,
            'joinparams'    => ['jointype' => 'itemtype_item'],
        ];

        return [
            ['id' => 8370, 'field' => 'last_backup', 'name' => __('Backup - Last backup', 'backupinfo'), 'datatype' => 'datetime'] + $base,
            ['id' => 8371, 'field' => 'id', 'name' => __('Backup - Backup objects (status: 0 = no backup)', 'backupinfo'), 'datatype' => 'count', 'usehaving' => true] + $base,
            ['id' => 8372, 'field' => 'job_name', 'name' => __('Backup - Job', 'backupinfo'), 'datatype' => 'string'] + $base,
        ];
    }
}
