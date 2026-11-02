<?php

namespace GlpiPlugin\Backupinfo;

use CommonDBTM;
use Computer;
use Config;
use GlpiPlugin\Backupinfo\Sync\DbObjectStore;
use Html;
use Session;

/**
 * "Backup" section of the shared PGN Suite asset tab (lib/pgnsuite/AssetTab.php) on
 * Computer. A summary, no pager (hub §5): newest MAX_ROWS objects and a
 * "showing N of M" line.
 */
final class AssetTab
{
    public const KEY      = 'backup';
    public const ORDER    = 100;
    public const MAX_ROWS = 20;

    /** @return list<array{key: string, title: string, icon: string, order: int, render: string}> */
    public static function sections(CommonDBTM $item): array
    {
        if (!Session::haveRight(RightDefinition::VIEW, READ)) {
            return [];
        }
        if (!$item instanceof Computer || $item->isNewItem()) {
            return [];
        }
        // Hub entity rule: only for Computers in the user's active entities.
        if (!Session::haveAccessToEntity((int) $item->getEntityID(), (bool) $item->isRecursive())) {
            return [];
        }
        return [[
            'key'    => self::KEY,
            'title'  => __('Backup', 'backupinfo'),
            'icon'   => 'ti ti-database-export',
            'order'  => self::ORDER,
            'render' => self::class . '::render',
        ]];
    }

    public static function render(CommonDBTM $item): void
    {
        global $DB;

        $id      = (int) $item->getID();
        $objects = (new DbObjectStore())->forItem($id);
        $cfg     = Config::getConfigurationValues('plugin:backupinfo', ['rpo_hours']);
        $rpo     = (int) ($cfg['rpo_hours'] ?? 26);
        $rpo     = $rpo > 0 ? $rpo : 26;
        $now     = time();
        $status  = Status::of($objects, $rpo, $now);

        $mandatory = false;
        try {
            $mandatory = Policy::fromConfig()->isMandatory('Computer', $id);
        } catch (\Throwable $e) {
            // Policy/Fields faults must not break the asset form.
        }

        $lastSync = '';
        foreach ($DB->request([
            'SELECT' => ['last_sync'],
            'FROM'   => 'glpi_plugin_backupinfo_connections',
            'WHERE'  => ['is_active' => 1],
        ]) as $c) {
            $lastSync = max($lastSync, (string) ($c['last_sync'] ?? ''));
        }

        echo '<div class="p-3 backupinfo-asset">';
        echo '<div class="d-flex flex-wrap gap-3 align-items-center mb-3">';
        echo self::badge($status);
        $newest = $objects === [] ? 0 : max(array_map(static fn (array $o): int => (int) ($o['last_backup'] ?? 0), $objects));
        if ($newest > 0) {
            echo '<span>' . __s('Last backup', 'backupinfo') . ': <strong>'
                . htmlescape(Html::convDateTime(date('Y-m-d H:i:s', $newest)))
                . '</strong> (' . htmlescape(sprintf(__('%s ago', 'backupinfo'), self::age($now - $newest))) . ')</span>';
        }
        echo '<span>' . __s('Must be backed up', 'backupinfo') . ': <strong>' . ($mandatory ? __s('Yes') : __s('No')) . '</strong></span>';
        echo '<span>' . __s('RPO', 'backupinfo') . ': ' . (int) $rpo . ' h</span>';
        echo '<span class="text-muted">' . __s('Last sync', 'backupinfo') . ': '
            . htmlescape($lastSync !== '' ? Html::convDateTime($lastSync) : __('never', 'backupinfo')) . '</span>';
        echo '</div>';

        if ($objects === []) {
            echo '<div class="text-muted">' . __s('No backup object is matched to this computer.', 'backupinfo') . '</div>';
            echo '</div>';
            return;
        }

        echo '<table class="tab_cadre_fixehov"><tr class="noHover">'
            . '<th>' . __s('Source', 'backupinfo') . '</th>'
            . '<th>' . __s('Kind', 'backupinfo') . '</th>'
            . '<th>' . __s('Job', 'backupinfo') . '</th>'
            . '<th>' . __s('Last backup', 'backupinfo') . '</th>'
            . '<th>' . __s('Restore points', 'backupinfo') . '</th>'
            . '<th>' . __s('Size', 'backupinfo') . '</th>'
            . '<th>' . __s('Flags', 'backupinfo') . '</th>'
            . '</tr>';
        foreach (array_slice($objects, 0, self::MAX_ROWS) as $o) {
            $last  = $o['last_backup'];
            $flags = [];
            if (!empty($o['abnormal_chain'])) {
                $flags[] = __('Abnormal chain', 'backupinfo');
            }
            if (!empty($o['source_deleted'])) {
                $flags[] = __('Deleted at source', 'backupinfo');
            }
            if (($o['match_state'] ?? '') === 'manual') {
                $flags[] = __('Pinned by hand', 'backupinfo');
            }
            echo '<tr class="tab_bg_1">'
                . '<td>' . htmlescape($o['source_label'] . ' · ' . $o['connection_name']) . '</td>'
                . '<td>' . htmlescape((string) ($o['kind'] ?? '')) . '</td>'
                . '<td>' . htmlescape((string) ($o['job_name'] ?? '')) . '</td>'
                . '<td>' . ($last === null ? '-' : htmlescape(Html::convDateTime(date('Y-m-d H:i:s', (int) $last)) . ' (' . self::age($now - (int) $last) . ')')) . '</td>'
                . '<td>' . (int) ($o['restore_points'] ?? 0) . '</td>'
                . '<td>' . htmlescape(self::sizeText((string) ($o['data_size'] ?? ''))) . '</td>'
                . '<td>' . htmlescape(implode(', ', $flags)) . '</td>'
                . '</tr>';
        }
        echo '</table>';
        if (count($objects) > self::MAX_ROWS) {
            echo '<div class="text-muted mt-2">' . htmlescape(sprintf(__('Showing %1$d of %2$d backup objects.', 'backupinfo'), self::MAX_ROWS, count($objects))) . '</div>';
        }
        echo '</div>';
    }

    public static function badge(string $status): string
    {
        [$color, $label] = match ($status) {
            Status::PROTECTED => ['success', __('Protected', 'backupinfo')],
            Status::EXPIRED   => ['warning', __('Expired', 'backupinfo')],
            default           => ['danger', __('No backup', 'backupinfo')],
        };
        return '<span class="badge bg-' . $color . '-lt fs-5">' . htmlescape($label) . '</span>';
    }

    /** Pure: "5 min", "3 h", "3 d". */
    public static function age(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' h';
        }
        return intdiv($seconds, 86400) . ' d';
    }

    /** Pure: bytes in binary units; '' for 0 (unknown). */
    /** Vinchin sends sizes as text ("12.5 GB"); show it as is, and format bare byte counts. */
    public static function sizeText(string $value): string
    {
        $value = trim($value);
        return ctype_digit($value) ? self::size((int) $value) : $value;
    }

    public static function size(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $value = $bytes / 1024;
        $i     = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $value, $units[$i]);
    }
}
