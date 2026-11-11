<?php

/**
 * Backup objects: the "needs review" / "not found" list, with pin to a Computer and unpin.
 * Rows with a Computer follow the user's active entities; rows without one need config READ.
 * Pin/unpin need config UPDATE (CSRF is checked by the GLPI 11 kernel).
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryFunction;
use GlpiPlugin\Backupinfo\AuditTable;
use GlpiPlugin\Backupinfo\Cron;
use GlpiPlugin\Backupinfo\ObjectsView;
use GlpiPlugin\Backupinfo\RestoreTest;
use GlpiPlugin\Backupinfo\Rpo;
use GlpiPlugin\Backupinfo\Summary;
use GlpiPlugin\Backupinfo\SummaryExport;
use GlpiPlugin\Backupinfo\Csv;
use GlpiPlugin\Backupinfo\Xlsx;
use GlpiPlugin\Backupinfo\SummaryView;
use GlpiPlugin\Backupinfo\TableUi;
use GlpiPlugin\Backupinfo\RightDefinition;
use GlpiPlugin\Backupinfo\Source\Registry;

include('../../../inc/includes.php');
Session::checkRight(RightDefinition::VIEW, READ);

/** @var \DBmysql $DB */
global $DB; // front files run inside a function under GLPI 11 routing

$table = 'glpi_plugin_backupinfo_objects';

$loadObject = static function (int $id) use ($table): ?array {
    global $DB;
    $it = $DB->request(['FROM' => $table, 'WHERE' => ['id' => $id], 'LIMIT' => 1]);
    return count($it) > 0 ? $it->current() : null;
};
$computerOk = static function (int $cid): bool {
    $computer = new Computer();
    return $cid > 0 && $computer->can($cid, READ)
        && (int) $computer->fields['is_deleted'] === 0 && (int) $computer->fields['is_template'] === 0;
};
// The Computer an object is currently attached to: in the user's entities, or gone (purged).
$currentOk = static function (int $cid): bool {
    $computer = new Computer();
    return $cid <= 0 || !$computer->getFromDB($cid) || $computer->can($cid, READ);
};

if (isset($_POST['pin'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $oid = (int) ($_POST['id'] ?? 0);
    $cid = (int) ($_POST['items_id'] ?? 0);
    $row = $loadObject($oid);
    $old = $row === null ? 0 : (int) $row['items_id'];
    if ($row === null || !$currentOk($old)) {
        Session::addMessageAfterRedirect(__s('Backup object not found.', 'backupinfo'), false, ERROR);
    } elseif (!$computerOk($cid)) {
        Session::addMessageAfterRedirect(__s('Choose a computer you can see.', 'backupinfo'), false, ERROR);
    } else {
        $DB->update($table, [
            'match_state' => 'manual',
            'itemtype'    => 'Computer',
            'items_id'    => $cid,
            'date_mod'    => date('Y-m-d H:i:s'),
        ], ['id' => $oid]);
        Cron::applyFields(array_values(array_filter([$old, $cid])));
        Session::addMessageAfterRedirect(__s('Backup object pinned to the computer; the sync keeps this match.', 'backupinfo'));
    }
    Html::back();
}

if (isset($_POST['unpin'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $oid = (int) ($_POST['id'] ?? 0);
    $row = $loadObject($oid);
    $old = $row === null ? 0 : (int) $row['items_id'];
    if ($row === null || !$currentOk($old)) {
        Session::addMessageAfterRedirect(__s('Backup object not found.', 'backupinfo'), false, ERROR);
    } else {
        $DB->update($table, [
            'match_state' => 'unmatched',
            'itemtype'    => '',
            'items_id'    => 0,
            'date_mod'    => date('Y-m-d H:i:s'),
        ], ['id' => $oid]);
        if ($old > 0) {
            Cron::applyFields([$old]);
        }
        Session::addMessageAfterRedirect(__s('Manual pin removed; the next sync matches it automatically.', 'backupinfo'));
    }
    Html::back();
}

$page      = Html::getPrefixedUrl('/plugins/backupinfo/front/objects.php');
$canConfig = Session::haveRight(RightDefinition::CONFIG, READ);
$canPin    = Session::haveRight(RightDefinition::CONFIG, UPDATE);

$states = [
    'attention' => [__('Not matched', 'backupinfo'), ['review', 'unmatched']],
    'manual'    => [__('Pinned by hand', 'backupinfo'), ['manual']],
    'matched'   => [__('Matched', 'backupinfo'), ['matched']],
    'all'       => [__('All', 'backupinfo'), null],
];
$state = is_string($_GET['state'] ?? null) && isset($states[$_GET['state']]) ? $_GET['state'] : 'attention';

// Backup summary (2026-11-11): source switch (active connections only), age segments, attention toggle.
$activeSources = [];
$lastSyncs     = [];
$typesById     = [];
foreach ($DB->request(['FROM' => 'glpi_plugin_backupinfo_connections', 'WHERE' => ['is_active' => 1], 'ORDER' => ['name']]) as $c) {
    $activeSources[(int) $c['id']] = (string) $c['name'];
    $lastSyncs[(int) $c['id']]     = (string) ($c['last_sync'] ?? '');
    $typesById[(int) $c['id']]     = (string) ($c['type'] ?? '');
}
$source = is_string($_GET['source'] ?? null) && ctype_digit($_GET['source']) ? (int) $_GET['source'] : 0;
if (!isset($activeSources[$source])) {
    $source = 0; // inactive, deleted or not a connection: All
}
$ages = array_values(array_intersect(array_keys(Summary::AGE_BUCKETS), is_array($_GET['age'] ?? null) ? array_map('strval', $_GET['age']) : []));
$attentionOn = ($_GET['attention'] ?? '') === '1';
// Kept on every link and table reload of this page.
$keep = array_filter(['source' => $source ?: null, 'age' => $ages ?: null, 'attention' => $attentionOn ? '1' : null], static fn($v): bool => $v !== null);
$link = static fn(array $over = []): string => $page . '?' . http_build_query(array_filter(array_merge(['state' => $state], $keep, $over), static fn($v): bool => $v !== null));

// Visibility: the Computer's entity, or (no Computer) config READ.
$entity  = getEntitiesRestrictCriteria('glpi_computers');
$withPc  = ['glpi_computers.id' => ['>', 0]] + $entity;
// Config READ also sees rows whose Computer was purged (no join hit), so they can be unpinned.
$visible = $canConfig ? ['OR' => [[$table . '.items_id' => 0], ['glpi_computers.id' => null], $withPc]] : $withPc;
$where   = [$visible];
if ($source > 0) {
    $where[$table . '.connections_id'] = $source;
}
$objects = [];
foreach ($DB->request([
    'SELECT'    => [$table . '.*', 'glpi_computers.name AS computer_name', 'glpi_plugin_backupinfo_connections.name AS connection_name', 'glpi_plugin_backupinfo_connections.type AS connection_type', 'glpi_plugin_backupinfo_connections.is_active AS connection_active'],
    'FROM'      => $table,
    'LEFT JOIN' => [
        'glpi_computers' => ['ON' => [
            $table => 'items_id',
            'glpi_computers'   => 'id',
            ['AND' => [$table . '.itemtype' => 'Computer']],
        ]],
        'glpi_plugin_backupinfo_connections' => ['ON' => [
            $table                   => 'connections_id',
            'glpi_plugin_backupinfo_connections' => 'id',
        ]],
    ],
    'WHERE'     => $where,
    'ORDER'     => [$table . '.name'],
]) as $row) {
    $objects[] = $row;
}

// Newest stored run per connection and VM name: the newest start first, then its rows.
$lastTimes = [];
$connIds   = array_values(array_unique(array_map(static fn(array $o): int => (int) $o['connections_id'], $objects)));
if ($connIds !== []) {
    foreach ($DB->request([
        'SELECT'     => ['r.connections_id', 'i.vm_name', QueryFunction::max('r.start_time', 'last')],
        'FROM'       => 'glpi_plugin_backupinfo_runs_items AS i',
        'INNER JOIN' => ['glpi_plugin_backupinfo_runs AS r' => ['ON' => ['i' => 'runs_id', 'r' => 'id']]],
        'WHERE'      => ['r.connections_id' => $connIds],
        'GROUPBY'    => ['r.connections_id', 'i.vm_name'],
    ]) as $row) {
        if (!empty($row['last'])) {
            $lastTimes[(string) $row['last']] = true;
        }
    }
}
$runRows = [];
foreach (array_chunk(array_keys($lastTimes), 500) as $chunk) {
    foreach ($DB->request([
        'SELECT'     => ['r.connections_id', 'i.vm_name', 'r.start_time', 'r.finish_time', 'r.status', 'i.item_status', 'r.reason'],
        'FROM'       => 'glpi_plugin_backupinfo_runs_items AS i',
        'INNER JOIN' => ['glpi_plugin_backupinfo_runs AS r' => ['ON' => ['i' => 'runs_id', 'r' => 'id']]],
        'WHERE'      => ['r.connections_id' => $connIds, 'r.start_time' => $chunk],
    ]) as $row) {
        $runRows[] = $row;
    }
}
$last  = ObjectsView::lastRuns($runRows);

// The cards count every visible row of the source; the table shows the filtered ones.
$now  = time();
$gaps = [];
foreach ($typesById as $id => $type) {
    $gaps[$id] = Registry::gaps($type);
}
$model   = Summary::build($objects, $last, Rpo::fromConfig(), $now, $gaps);
$chosen  = $source > 0 ? [$source => $lastSyncs[$source]] : $lastSyncs;
$synced  = array_filter($chosen, static fn(string $d): bool => $d !== '');
$oldest  = count($synced) === count($chosen) && $synced !== [] ? min($synced) : null;
$objects = ObjectsView::select($objects, $states[$state][1], $ages, $attentionOn, $model['attentionIds'], $now);
$tests = RestoreTest::latest(array_map(static fn(array $o): int => (int) $o['items_id'], $objects));
$canTest = Session::haveRight(RightDefinition::RESTORETEST, CREATE);
$canSeeTests = Session::haveRight(RightDefinition::RESTORETEST, READ);

$labels = [
    'matched'   => __('Matched', 'backupinfo'),
    'manual'    => __('Pinned by hand', 'backupinfo'),
    'review'    => __('Needs review (several computers)', 'backupinfo'),
    'unmatched' => __('Not found', 'backupinfo'),
];
$meta = []; // object id => what the HTML cells need
foreach ($objects as $o) {
    $run = $last[(int) $o['connections_id'] . '|' . mb_strtolower(trim((string) $o['name']))] ?? null;
    $meta[(int) $o['id']] = ['cid' => (int) $o['items_id'], 'match' => (string) $o['match_state'], 'reason' => $run === null ? '' : (string) ($run['reason'] ?? '')];
}
// Excel export of the summary (sheet Summary: cards) and the filtered table (sheet Objects).
if (($_GET['export'] ?? '') === 'xlsx') {
    $tableRows = ObjectsView::rows($objects, $last, $tests, Registry::types(), $labels, $now);
    $filters   = [$states[$state][0]];
    foreach ($ages as $a) {
        $filters[] = Summary::AGE_BUCKETS[$a];
    }
    if ($attentionOn) {
        $filters[] = __('Needs attention', 'backupinfo');
    }
    $meta = [
        'Report'         => 'Backup Info backup summary',
        'Source'         => $source > 0 ? $activeSources[$source] : 'All sources',
        'Filters'        => implode(', ', $filters),
        'Last synced'    => $oldest ?? 'never',
        'Generated at'   => date('Y-m-d H:i:s', $now),
        'Generated by'   => (string) getUserName(Session::getLoginUserID()),
        'Plugin version' => PLUGIN_BACKUPINFO_VERSION,
    ];
    $inactive = count(array_filter($objects, static fn(array $o): bool => empty($o['connection_active'])));
    if ($inactive > 0) {
        $meta['Inactive connection rows'] = $inactive . ' (in the table, not in the cards)';
    }
    $sheets   = SummaryExport::sheets($model, ObjectsView::COLUMNS, $tableRows, $meta);
    $filename = 'backupinfo-summary-' . date('Ymd-Hi', $now);
    header('Cache-Control: no-store');
    if (Xlsx::available()) {
        header('Content-Type: ' . Xlsx::MIME);
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        $out = fopen('php://output', 'wb');
        Xlsx::write($out, Xlsx::parts($sheets));
        fclose($out);
    } else {
        // No zip writer: CSV of the Objects sheet.
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        echo Csv::render($sheets[1]['meta'], ['columns' => $sheets[1]['columns'], 'rows' => $sheets[1]['rows']]);
    }
    exit;
}

$columns = ObjectsView::COLUMNS;
if (!$canPin) {
    $columns[ObjectsView::COL_ACTION] = 'ID';
}
$view = AuditTable::view('backupinfo-objects', ['columns' => $columns, 'rows' => ObjectsView::rows($objects, $last, $tests, Registry::types(), $labels, $now)], $_GET,
    (int) ($_SESSION['glpilist_limit'] ?? 15), [
        'fixed' => [ObjectsView::COL_ACTION],
        'html'  => static function (array $row) use ($meta, $canPin, $page, $canTest, $canSeeTests): array {
            $id  = (int) $row[ObjectsView::COL_ACTION];
            $m   = $meta[$id] ?? ['cid' => 0, 'match' => '', 'reason' => ''];
            $out = [];
            $res = (string) $row[ObjectsView::COL_RESULT];
            if ($res !== '') {
                $out[ObjectsView::COL_RESULT] = '<span class="badge ' . ObjectsView::badge($res) . '"'
                    . ($m['reason'] !== '' && $res !== 'success' ? ' title="' . htmlescape($m['reason']) . '" data-bs-toggle="tooltip"' : '') . '>' . htmlescape($res) . '</span>';
            }
            if ($m['cid'] > 0) {
                // The VM name and the Computer both open the GLPI asset.
                $asset = htmlescape(Computer::getFormURLWithID($m['cid']));
                $out[ObjectsView::COL_NAME]     = '<a href="' . $asset . '">' . htmlescape((string) $row[ObjectsView::COL_NAME]) . '</a>';
                $out[ObjectsView::COL_COMPUTER] = '<a href="' . $asset . '">' . htmlescape((string) $row[ObjectsView::COL_COMPUTER]) . '</a>';
                // Newest restore test: opens this Computer's restore tests; none yet: record one.
                $test = (string) $row[ObjectsView::COL_RESTORE];
                if ($test !== '' && $canSeeTests) {
                    $result = (string) substr($test, (int) strrpos($test, ' ') + 1);
                    $out[ObjectsView::COL_RESTORE] = '<a href="' . htmlescape(RestoreTest::getSearchURL() . '?' . ObjectsView::restoreListQuery($m['cid'])) . '">'
                        . htmlescape(substr($test, 0, (int) strrpos($test, ' '))) . ' <span class="badge ' . ObjectsView::badge($result) . '">' . htmlescape($result) . '</span></a>';
                } elseif ($test === '' && $canTest) {
                    $out[ObjectsView::COL_RESTORE] = '<a class="text-muted" href="' . htmlescape(RestoreTest::getFormURL() . '?computers_id=' . $m['cid']) . '"><i class="ti ti-plus"></i> '
                        . __s('Record a test', 'backupinfo') . '</a>';
                }
            }
            if (!$canPin) {
                return $out;
            }
            $form = '<form method="post" action="' . htmlescape($page) . '" class="d-flex gap-2 align-items-center">' . Html::hidden('id', ['value' => $id]);
            if ($m['match'] === 'manual') {
                $form .= Html::submit(__('Unpin', 'backupinfo'), ['name' => 'unpin', 'class' => 'btn btn-sm btn-outline-secondary']);
            } else {
                $form .= Computer::dropdown(['name' => 'items_id', 'value' => $m['cid'], 'width' => '16rem', 'rand' => 'bi' . $id, 'display' => false]);
                $form .= Html::submit(__('Pin', 'backupinfo'), ['name' => 'pin', 'class' => 'btn btn-sm btn-primary']);
            }
            $out[ObjectsView::COL_ACTION] = $form . Html::closeForm(false);
            return $out;
        },
    ]);

Html::header(__('Backup objects', 'backupinfo'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'backupinfo', 'objects');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('backupinfo', 'objects');

echo SummaryView::render($model, $activeSources, $source, $page, $ages, $oldest);

echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
// Bordered button group, not .nav-pills: pills have no borders and their active tint is
// unreadable on GLPI's default palette (same finding as the shared ConfigTab sub-nav).
echo '<div class="btn-group" role="group" aria-label="' . __s('Filter', 'backupinfo') . '">';
foreach ($states as $key => [$label]) {
    $active = $key === $state;
    echo '<a class="btn btn-outline-secondary' . ($active ? ' active' : '') . '"'
        . ($active ? ' aria-current="page"' : '')
        . ' href="' . htmlescape($link(['state' => $key])) . '">' . htmlescape($label) . '</a>';
}
echo '</div>';
// Needs attention combines with the state buttons.
echo '<a class="btn btn-outline-danger' . ($attentionOn ? ' active' : '') . '" href="' . htmlescape($attentionOn ? $link(['attention' => null]) : $link(['attention' => '1'])) . '">'
    . '<i class="ti ti-alert-triangle me-1"></i>' . __s('Needs attention', 'backupinfo') . '</a>';
if ($ages !== []) {
    echo '<a class="btn btn-ghost-secondary" href="' . htmlescape($link(['age' => null])) . '">' . __s('Clear age filter', 'backupinfo') . '</a>';
}
echo '<div class="ms-auto d-flex gap-2">';
echo '<a class="btn btn-outline-secondary" href="' . htmlescape($link(['export' => 'xlsx'])) . '"><i class="ti ti-file-spreadsheet me-1"></i>' . __s('Export (Excel)', 'backupinfo') . '</a>';
echo TableUi::columnsButton('backupinfo-objects-cols');
echo '</div>';
echo '</div>';
if (!$canConfig) {
    echo '<div class="text-muted mb-2">' . __s('Objects without a computer are shown only to Backup Info administrators.', 'backupinfo') . '</div>';
}
echo '<div class="text-muted mb-2">' . __s('Last run and Result: the newest backup run stored by the sync for this object (same connection and VM name), with the reason of a failure on hover. Restore test: the newest restore test of its Computer.', 'backupinfo') . '</div>';

echo '<div id="backupinfo-objects">';
echo TemplateRenderer::getInstance()->render('components/datatable.html.twig', $view);
echo '</div>';

// GLPI's table calls reloadTab() for sort, pages, page size and filters: here it reloads the page
// with the next state in the URL (the state buttons start a fresh list).
$js = static fn(mixed $v): string => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
echo Html::scriptBlock(TableUi::script());
echo Html::scriptBlock('(() => {
    const T = window.backupinfoTable, root = document.getElementById("backupinfo-objects");
    const store = "backupinfo.objects.cols";
    const url = new URLSearchParams(window.location.search), f = new URLSearchParams();
    url.forEach((v, k) => { if (k.startsWith("filters[")) { f.append(k, v); } });
    const st = {filters: f.toString(), sort: url.get("sort") || "", order: url.get("order") || "ASC", start: parseInt(url.get("start") || "0", 10) || 0, limit: url.get("glpilist_limit") || ""};
    window.reloadTab = (add) => {
        window.location.href = ' . $js($link()) . ' + "&" + T.query(T.step(st, add));
    };
    T.bindFilters($(root), (add) => window.reloadTab(add));
    if (st.limit) { $(root).find("select[name=list_limit]").val(st.limit); }
    T.colMenu(store, root, document.getElementById("backupinfo-objects-cols"), ' . $js(__('Show all columns', 'backupinfo')) . ');
    T.applyCols(store, root);
})();');

Html::footer();