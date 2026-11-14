<?php

namespace GlpiPlugin\Backupinfo;

/**
 * HTML of the backup summary cards (Summary::build() model). Pure: GLPI 11 / Tabler cards,
 * badges and progress bars; every name, job and note is escaped.
 */
final class SummaryView
{
    private const AGE_COLORS = ['day' => 'bg-success', 'days3' => 'bg-lime', 'days7' => 'bg-warning', 'older' => 'bg-danger', 'none' => 'bg-secondary'];
    private const REASON_COLORS = ['chain' => 'bg-danger', 'deleted' => 'bg-danger', 'failed' => 'bg-danger', 'never' => 'bg-danger', 'expired' => 'bg-warning'];

    /**
     * @param array<string, mixed> $model Summary::build()
     * @param array<int, string> $sources active connections, id => name
     * @param int $activeSource 0 = All
     * @param list<string> $ageOn age bucket keys filtering the table now
     * @param string|null $lastSync oldest last_sync of the chosen connections
     * @param bool $attentionOn the Needs attention toggle is on (kept by the age segments)
     */
    public static function render(array $model, array $sources, int $activeSource, string $baseUrl, array $ageOn, ?string $lastSync, bool $attentionOn = false): string
    {
        // An age segment toggles itself in the current selection; several can be on at once.
        $segUrl = static function (string $key) use ($baseUrl, $activeSource, $ageOn, $attentionOn): string {
            $ages = in_array($key, $ageOn, true) ? array_values(array_diff($ageOn, [$key])) : [...$ageOn, $key];
            return htmlescape($baseUrl . '?' . http_build_query(array_filter(
                ['state' => 'all', 'source' => $activeSource, 'age' => $ages ?: null, 'attention' => $attentionOn ? '1' : null],
                static fn($v): bool => $v !== null
            )));
        };
        // A card or a side-card item opens the table narrowed to what it counts (0.5.2-rc1): a fresh
        // view of the source (other filters off), scrolled to the table.
        $card = static fn(array $q): string => htmlescape($baseUrl . '?' . http_build_query(['state' => 'all', 'source' => $activeSource] + $q) . '#backupinfo-objects');
        $e   = static fn(mixed $s): string => htmlescape((string) $s);
        $url = static fn(int $source, string $extra = ''): string => htmlescape($baseUrl . '?state=all&source=' . $source . $extra);
        $pct = static fn(?float $v): string => $v === null ? '—' : sprintf('%.1f%%', $v);
        $n   = (int) $model['count'];
        $notReported = __('Not reported by this source', 'backupinfo');
        $size = static fn(int $b): string => ($model['sizeReported'] ?? true) ? Summary::human($b) : $notReported;

        $h = '<div id="backupinfo-summary" class="mb-4">';

        // Source switch and data line.
        $h .= '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
        $h .= '<div class="btn-group" role="group" aria-label="' . __s('Source', 'backupinfo') . '">';
        $buttons = [0 => __('All sources', 'backupinfo')] + $sources;
        foreach ($buttons as $id => $label) {
            $on = (int) $id === $activeSource;
            $h .= '<a class="btn btn-outline-secondary' . ($on ? ' active' : '') . '"' . ($on ? ' aria-current="page"' : '')
                . ' href="' . $url((int) $id) . '">' . ($id === 0 ? 'All' : $e($label)) . '</a>';
        }
        $h .= '</div>';
        $h .= '<div class="text-muted small">'
            . $e($lastSync === null ? __('never synced', 'backupinfo') : sprintf(__('Last synced %s', 'backupinfo'), $lastSync))
            . ' · ' . $e(sprintf(__('%d of %d matched to a Computer', 'backupinfo'), (int) $model['matched'], $n)) . '</div>';
        $h .= '</div>';

        // KPI cards.
        $reasonLine = [];
        foreach (Summary::REASONS as $key => $label) {
            if (!empty($model['reasonCounts'][$key])) {
                $reasonLine[] = __($label, 'backupinfo') . ' ' . (int) $model['reasonCounts'][$key];
            }
        }
        $kpis = [
            [__('Protected objects', 'backupinfo'), (string) $n, sprintf(__('%d kinds · %d sources', 'backupinfo'), (int) $model['kinds'], (int) $model['sources']), 'ti-shield-check', 'text-primary', []],
            [__('Fresh backups', 'backupinfo'), $pct($model['freshPct']), sprintf(__('%d objects ≤ 24 hours', 'backupinfo'), (int) $model['fresh']), 'ti-bolt', 'text-success', ['age' => ['day']]],
            [__('Total data', 'backupinfo'), $size((int) $model['bytes']), __('protected data size', 'backupinfo'), 'ti-database', 'text-azure', ['sort' => 'c' . ObjectsView::COL_SIZE, 'order' => 'DESC']],
            [__('Restore points', 'backupinfo'), (string) (int) $model['points'], $model['pointsAvg'] === null ? '—' : sprintf(__('average %.1f per object', 'backupinfo'), $model['pointsAvg']), 'ti-history', 'text-purple', ['sort' => 'c' . ObjectsView::COL_POINTS, 'order' => 'DESC']],
            [__('Needs attention', 'backupinfo'), (string) count($model['attention']), $reasonLine === [] ? __('nothing to check', 'backupinfo') : implode(' · ', $reasonLine), 'ti-alert-triangle', 'text-danger', ['attention' => '1']],
        ];
        $h .= '<div class="row row-cards mb-3">';
        foreach ($kpis as [$title, $value, $sub, $icon, $color, $q]) {
            $h .= '<div class="col-sm-6 col-lg"><a class="card card-sm card-link text-reset text-decoration-none h-100" href="' . $card($q) . '"><div class="card-body"><div class="d-flex align-items-center gap-3">'
                . '<i class="ti ' . $icon . ' fs-1 ' . $color . '"></i><div>'
                . '<div class="text-uppercase text-muted small">' . $e($title) . '</div>'
                . '<div class="h2 m-0 ' . $color . '">' . $e($value) . '</div>'
                . '<div class="text-muted small">' . $e($sub) . '</div></div></div></div></a></div>';
        }
        $h .= '</div>';

        // Last backup age bar.
        $h .= '<div class="card mb-3"><div class="card-header d-flex justify-content-between"><h3 class="card-title">' . __s('Last backup age', 'backupinfo') . '</h3>'
            . '<span class="text-muted small">' . __s('Click a segment to filter the table', 'backupinfo') . '</span></div><div class="card-body">';
        $h .= '<div class="progress progress-lg mb-2" style="height:1.5rem">';
        foreach (Summary::AGE_BUCKETS as $key => $label) {
            $count = (int) $model['age'][$key];
            if ($count === 0 || $n === 0) {
                continue;
            }
            $dim = $ageOn !== [] && !in_array($key, $ageOn, true) ? ' opacity-50' : '';
            $h  .= '<a class="progress-bar ' . self::AGE_COLORS[$key] . $dim . '" style="width:' . sprintf('%.1f', $count * 100 / $n) . '%"'
                . ' href="' . $segUrl($key) . '" title="' . $e(__($label, 'backupinfo') . ': ' . $count) . '"></a>';
        }
        $h .= '</div><div class="d-flex flex-wrap gap-3 small">';
        foreach (Summary::AGE_BUCKETS as $key => $label) {
            $count = (int) $model['age'][$key];
            $h .= '<a class="text-reset text-decoration-none" href="' . $segUrl($key) . '"><span class="badge ' . self::AGE_COLORS[$key] . ' me-1">&nbsp;</span>' . $e(__($label, 'backupinfo'))
                . ' <strong>' . $count . '</strong> · ' . ($n === 0 ? '0%' : sprintf('%d%%', (int) round($count * 100 / $n))) . '</a>';
        }
        $h .= '</div></div></div>';

        // Three side cards.
        $h .= '<div class="row row-cards mb-3">';
        $h .= '<div class="col-lg-4"><div class="card h-100"><div class="card-header"><h3 class="card-title">' . __s('By kind', 'backupinfo') . '</h3></div><div class="card-body">';
        foreach ($model['byKind'] as $k) {
            $h .= '<a class="d-block mb-3 text-reset text-decoration-none" href="' . $card(['kind' => $k['kind']]) . '"><div class="d-flex justify-content-between"><strong>' . $e(__($k['label'], 'backupinfo')) . '</strong>'
                . '<span class="text-muted">' . (int) $k['count'] . ' · ' . $e($size((int) $k['bytes'])) . '</span></div>'
                . '<div class="progress progress-sm my-1"><div class="progress-bar bg-success" style="width:' . sprintf('%.1f', $k['freshPct']) . '%"></div></div>'
                . '<div class="text-muted small">' . $e(sprintf(__('%d%% ≤ 24 hours', 'backupinfo'), (int) round($k['freshPct']))) . '</div></a>';
        }
        $h .= '</div></div></div>';

        $h .= '<div class="col-lg-4"><div class="card h-100"><div class="card-header"><h3 class="card-title">' . __s('Needs attention', 'backupinfo')
            . ' <span class="text-muted">(' . count($model['attention']) . ')</span></h3></div><div class="list-group list-group-flush" style="max-height:22rem;overflow:auto">';
        foreach ($model['attention'] as $a) {
            $age = $a['ageDays'] === null ? __('No backup', 'backupinfo') : sprintf(__('Last backup %d days ago', 'backupinfo'), (int) $a['ageDays']);
            $h  .= '<a class="list-group-item list-group-item-action" href="' . $card(['obj' => (int) $a['id']]) . '"><div class="fw-bold">' . $e($a['name']) . '</div><div class="text-muted small">' . $e($age)
                . ($a['job'] !== '' ? ' · ' . $e($a['job']) : '') . '</div><div>';
            foreach ($a['reasons'] as $r) {
                $h .= '<span class="badge ' . self::REASON_COLORS[$r] . ' me-1">' . $e(__(Summary::REASONS[$r], 'backupinfo')) . '</span>';
            }
            $h .= '</div></a>';
        }
        $h .= '</div></div></div>';

        $h .= '<div class="col-lg-4"><div class="card h-100"><div class="card-header"><h3 class="card-title">' . __s('Largest objects', 'backupinfo') . '</h3></div><div class="card-body">';
        $top = (int) ($model['largest'][0]['bytes'] ?? 0);
        if ($model['largest'] === [] && !($model['sizeReported'] ?? true)) {
            $h .= '<div class="text-muted">' . $e($notReported) . '</div>';
        }
        foreach ($model['largest'] as $l) {
            $h .= '<a class="d-block mb-2 text-reset text-decoration-none" href="' . $card(['obj' => (int) $l['id']]) . '"><div class="d-flex justify-content-between"><span>' . $e($l['name']) . '</span><span class="text-muted">' . $e(Summary::human((int) $l['bytes'])) . '</span></div>'
                . '<div class="progress progress-sm"><div class="progress-bar bg-azure" style="width:' . sprintf('%.1f', $top > 0 ? $l['bytes'] * 100 / $top : 0) . '%"></div></div></a>';
        }
        $h .= '</div></div></div>';
        $h .= '</div>';

        if ($model['notes'] !== []) {
            $h .= '<div class="alert alert-info">' . implode('<br>', array_map($e, $model['notes'])) . '</div>';
        }
        return $h . '</div>';
    }
}
