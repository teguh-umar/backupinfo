<?php

namespace GlpiPlugin\Backupinfo;

use Computer;
use Html;

/**
 * Coverage page model and HTML (plain tables, no charts). front/coverage.php loads the
 * entity-restricted data; build() is pure.
 */
final class CoveragePage
{
    public const MAX_ROWS = 200;

    private const ORDER = [Status::NONE => 0, Status::EXPIRED => 1, Status::PROTECTED => 2];

    /**
     * @param array<int, array{name: string}>        $computers     mandatory Computers visible to the user, by id
     * @param array<int, list<array<string, mixed>>> $objectsByItem matched objects keyed by Computer id
     * @return array{counts: array{mandatory:int,protected:int,expired:int,none:int}, percent: int,
     *               missing: list<array{id:int,name:string,status:string,last_backup:?int}>}
     */
    public static function build(array $computers, array $objectsByItem, int $rpoHours, int $nowS): array
    {
        $counts  = Policy::countCoverage(array_keys($computers), $objectsByItem, $rpoHours, $nowS);
        $missing = [];
        foreach ($computers as $id => $c) {
            $objects = $objectsByItem[$id] ?? [];
            $status  = Status::of($objects, $rpoHours, $nowS);
            if ($status === Status::PROTECTED) {
                continue;
            }
            $last = null;
            foreach ($objects as $o) {
                $t = $o['last_backup'] ?? null;
                if ($t !== null && $t !== '' && ($last === null || (int) $t > $last)) {
                    $last = (int) $t;
                }
            }
            $missing[] = ['id' => (int) $id, 'name' => (string) ($c['name'] ?? ''), 'status' => $status, 'last_backup' => $last];
        }
        usort($missing, static fn (array $a, array $b): int => [self::ORDER[$a['status']], $a['name']] <=> [self::ORDER[$b['status']], $b['name']]);

        return [
            'counts'  => $counts,
            'percent' => $counts['mandatory'] > 0 ? (int) round($counts['protected'] * 100 / $counts['mandatory']) : 0,
            'missing' => $missing,
        ];
    }

    /**
     * @param array{counts: array, percent: int, missing: list<array>} $model
     * @param list<array<string, mixed>>|null $unmatched null when the user may not see them
     */
    public static function render(array $model, string $policyMode, int $rpoHours, ?array $unmatched, int $unmatchedTotal, int $nowS): void
    {
        $c = $model['counts'];
        echo '<div class="card mb-3"><div class="card-body">';
        if ($c['mandatory'] === 0) {
            echo '<div class="alert alert-warning mb-0">' . htmlescape($policyMode === Policy::MODE_MANUAL
                ? __('The manual list of computers that must be backed up is empty (Setup > Backup Info).', 'backupinfo')
                : __('No computer in your entities has "Wajib Backup" = Yes, or the Fields mapping is not set (Setup > Backup Info).', 'backupinfo')) . '</div>';
        } else {
            echo '<div class="fs-2 mb-2">' . htmlescape(sprintf(
                __('%1$d %% of the computers that must be backed up are protected (%2$d of %3$d)', 'backupinfo'),
                $model['percent'],
                $c['protected'],
                $c['mandatory']
            )) . '</div>';
            echo '<table class="tab_cadre_fixehov"><tr class="noHover"><th>' . __s('Must be backed up', 'backupinfo') . '</th><th>'
                . __s('Protected', 'backupinfo') . '</th><th>' . __s('Expired', 'backupinfo') . '</th><th>' . __s('No backup', 'backupinfo') . '</th></tr>';
            echo '<tr class="tab_bg_1"><td>' . (int) $c['mandatory'] . '</td><td>' . (int) $c['protected'] . '</td><td>'
                . (int) $c['expired'] . '</td><td>' . (int) $c['none'] . '</td></tr></table>';
        }
        echo '<div class="text-muted mt-2">' . htmlescape(sprintf(__('Policy: %1$s. RPO: %2$d h.', 'backupinfo'), $policyMode === Policy::MODE_MANUAL ? __('manual list', 'backupinfo') : __('"Wajib Backup" field', 'backupinfo'), $rpoHours)) . '</div>';
        echo '</div></div>';

        echo '<h3>' . __s('Must be backed up, but not protected', 'backupinfo') . '</h3>';
        if ($model['missing'] === []) {
            echo '<p class="text-muted">' . __s('None.', 'backupinfo') . '</p>';
        } else {
            echo '<table class="tab_cadre_fixehov"><tr class="noHover"><th>' . __s('Computer') . '</th><th>' . __s('Status') . '</th><th>' . __s('Last backup', 'backupinfo') . '</th></tr>';
            foreach (array_slice($model['missing'], 0, self::MAX_ROWS) as $row) {
                echo '<tr class="tab_bg_1"><td><a href="' . htmlescape(Computer::getFormURLWithID($row['id'])) . '">' . htmlescape($row['name'] !== '' ? $row['name'] : '#' . $row['id']) . '</a></td>'
                    . '<td>' . AssetTab::badge($row['status']) . '</td>'
                    . '<td>' . ($row['last_backup'] === null ? '-' : htmlescape(Html::convDateTime(date('Y-m-d H:i:s', $row['last_backup'])) . ' (' . AssetTab::age($nowS - $row['last_backup']) . ')')) . '</td></tr>';
            }
            echo '</table>';
            if (count($model['missing']) > self::MAX_ROWS) {
                echo '<div class="text-muted">' . htmlescape(sprintf(__('Showing %1$d of %2$d.', 'backupinfo'), self::MAX_ROWS, count($model['missing']))) . '</div>';
            }
        }

        if ($unmatched === null) {
            return;
        }
        echo '<h3 class="mt-3">' . __s('Backup objects without a computer', 'backupinfo') . '</h3>';
        if ($unmatched === []) {
            echo '<p class="text-muted">' . __s('None.', 'backupinfo') . '</p>';
            return;
        }
        echo '<table class="tab_cadre_fixehov"><tr class="noHover"><th>' . __s('Name') . '</th><th>' . __s('IP') . '</th><th>'
            . __s('Kind', 'backupinfo') . '</th><th>' . __s('Match', 'backupinfo') . '</th></tr>';
        foreach ($unmatched as $o) {
            echo '<tr class="tab_bg_1"><td>' . htmlescape((string) ($o['name'] ?? '')) . '</td><td>' . htmlescape((string) ($o['ip'] ?? ''))
                . '</td><td>' . htmlescape((string) ($o['kind'] ?? '')) . '</td><td>' . htmlescape((string) ($o['match_state'] ?? '')) . '</td></tr>';
        }
        echo '</table>';
        echo '<div class="mt-2">' . htmlescape(sprintf(__('Showing %1$d of %2$d.', 'backupinfo'), count($unmatched), $unmatchedTotal))
            . ' <a href="' . htmlescape(Html::getPrefixedUrl('/plugins/backupinfo/front/objects.php')) . '">' . __s('Review and pin them', 'backupinfo') . '</a></div>';
    }
}
