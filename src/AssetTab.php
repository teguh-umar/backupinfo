<?php

namespace GlpiPlugin\Backupinfo;

use CommonDBTM;
use Computer;
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
        $rpo     = Rpo::fromConfig();
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
        echo '<span class="text-muted">' . __s('Last sync', 'backupinfo') . ': '
            . htmlescape($lastSync !== '' ? Html::convDateTime($lastSync) : __('never', 'backupinfo')) . '</span>';
        echo '</div>';

        if ($objects === []) {
            echo '<div class="text-muted">' . __s('No backup object is matched to this computer.', 'backupinfo') . '</div>';
            self::renderRestore($item);
            echo '</div>';
            return;
        }

        echo '<table class="tab_cadre_fixehov"><tr class="noHover">'
            . '<th>' . __s('Source', 'backupinfo') . '</th>'
            . '<th>' . __s('Kind', 'backupinfo') . '</th>'
            . '<th>' . __s('Job', 'backupinfo') . '</th>'
            . '<th>' . __s('Last backup', 'backupinfo') . '</th>'
            . '<th>' . __s('RPO', 'backupinfo') . '</th>'
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
                . '<td>' . htmlescape($rpo->label($o)) . '</td>'
                . '<td>' . (int) ($o['restore_points'] ?? 0) . '</td>'
                . '<td>' . htmlescape(self::sizeText((string) ($o['data_size'] ?? ''))) . '</td>'
                . '<td>' . htmlescape(implode(', ', $flags)) . '</td>'
                . '</tr>';
        }
        echo '</table>';
        if (count($objects) > self::MAX_ROWS) {
            echo '<div class="text-muted mt-2">' . htmlescape(sprintf(__('Showing %1$d of %2$d backup objects.', 'backupinfo'), self::MAX_ROWS, count($objects))) . '</div>';
        }
        self::renderRestore($item);
        echo '</div>';
    }

    /**
     * Restore tests of this Computer (audit evidence, 0.3.0): status against its interval, next due
     * date, the last tests, an add link, and the per-Computer interval for config editors.
     */
    private static function renderRestore(CommonDBTM $item): void
    {
        if (!Session::haveRight(RightDefinition::RESTORETEST, READ)) {
            return;
        }
        $id = (int) $item->getID();
        try {
            $override = RestoreInterval::get($id);
            $months   = RestoreInterval::effective(RestoreInterval::globalMonths(), $override);
            $last     = RestoreTest::lastPassDates([$id])[$id] ?? null;
            $status   = RestoreInterval::status($last, $months, time());
            $nextDue  = RestoreInterval::nextDue($last, $months);
            $tests    = RestoreTest::forComputer($id, 5);
        } catch (\Throwable $e) {
            return; // never break the asset form
        }

        echo '<h4 class="mt-4">' . __s('Restore tests', 'backupinfo') . '</h4>';
        echo '<div class="d-flex flex-wrap gap-3 align-items-center mb-2">';
        echo self::restoreBadge($status);
        echo '<span>' . htmlescape($months === 0
            ? __('Not required for this computer', 'backupinfo')
            : sprintf(__('Required every %d months', 'backupinfo'), $months)) . ' <span class="text-muted">('
            . ($override === null ? __s('Setup default', 'backupinfo') : __s('set for this computer', 'backupinfo')) . ')</span></span>';
        if ($nextDue !== null) {
            echo '<span>' . __s('Next due', 'backupinfo') . ': <strong>' . htmlescape(Html::convDate($nextDue)) . '</strong></span>';
        }
        echo '</div>';

        if ($tests === []) {
            echo '<div class="text-muted mb-2">' . __s('No restore test recorded.', 'backupinfo') . '</div>';
        } else {
            $methods = RestoreTest::methods();
            $results = RestoreTest::results();
            echo '<table class="tab_cadre_fixehov"><tr class="noHover">'
                . '<th>' . __s('Test date', 'backupinfo') . '</th>'
                . '<th>' . __s('Method', 'backupinfo') . '</th>'
                . '<th>' . __s('Result', 'backupinfo') . '</th>'
                . '<th>' . __s('Source', 'backupinfo') . '</th>'
                . '</tr>';
            foreach ($tests as $t) {
                $url = RestoreTest::getFormURLWithID((int) $t['id']);
                echo '<tr class="tab_bg_1">'
                    . '<td><a href="' . htmlescape($url) . '">' . htmlescape(Html::convDate((string) $t['date'])) . '</a></td>'
                    . '<td>' . htmlescape($methods[$t['method']] ?? (string) $t['method']) . '</td>'
                    . '<td>' . htmlescape($results[$t['result']] ?? (string) $t['result']) . '</td>'
                    . '<td>' . htmlescape($t['source'] === RestoreTest::SOURCE_VINCHIN ? __('Vinchin', 'backupinfo') : __('Entered by hand', 'backupinfo')) . '</td>'
                    . '</tr>';
            }
            echo '</table>';
        }

        if (Session::haveRight(RightDefinition::RESTORETEST, CREATE)) {
            echo '<a class="btn btn-sm btn-outline-primary mt-2" href="' . htmlescape(RestoreTest::getFormURL() . '?computers_id=' . $id) . '">'
                . '<i class="ti ti-plus"></i> ' . __s('Add restore test', 'backupinfo') . '</a>';
        }

        if (Session::haveRight(RightDefinition::CONFIG, UPDATE)) {
            echo '<form method="post" class="d-flex flex-wrap gap-2 align-items-center mt-3" action="' . htmlescape(RestoreTest::getFormURL()) . '">';
            echo '<label for="backupinfo-restore-months-' . $id . '">' . __s('Interval for this computer (months; blank = Setup default, 0 = not required)', 'backupinfo') . '</label>';
            echo '<input type="number" min="0" max="' . RestoreInterval::MAX_MONTHS . '" step="1" class="form-control" style="max-width: 8rem" id="backupinfo-restore-months-' . $id
                . '" name="restore_months" value="' . ($override === null ? '' : (int) $override) . '">';
            echo Html::hidden('computers_id', ['value' => $id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'set_interval', 'class' => 'btn btn-sm btn-outline-secondary']);
            Html::closeForm();
        }
    }

    public static function restoreBadge(string $status): string
    {
        [$color, $label] = match ($status) {
            RestoreInterval::OK      => ['success', __('Restore tested', 'backupinfo')],
            RestoreInterval::DUE     => ['warning', __('Restore test due soon', 'backupinfo')],
            RestoreInterval::EXEMPT  => ['secondary', __('Restore test not required', 'backupinfo')],
            RestoreInterval::OVERDUE => ['danger', __('Restore test overdue', 'backupinfo')],
            default                  => ['danger', __('No restore test', 'backupinfo')],
        };
        return '<span class="badge bg-' . $color . '-lt fs-5">' . htmlescape($label) . '</span>';
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

    /** Vinchin sends sizes as text ("12.5 GB"); show it as is, and format bare byte counts. */
    public static function sizeText(string $value): string
    {
        $value = trim($value);
        return ctype_digit($value) ? self::size((int) $value) : $value;
    }

    /** Pure: bytes in binary units; '' for 0 (unknown). */
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
