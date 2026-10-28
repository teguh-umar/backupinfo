<?php

/**
 * Backup objects: the "needs review" / "not found" list, with pin to a Computer and unpin.
 * Rows with a Computer follow the user's active entities; rows without one need config READ.
 * Pin/unpin need config UPDATE (CSRF is checked by the GLPI 11 kernel).
 */

use GlpiPlugin\Backupinfo\Cron;
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

$canConfig = Session::haveRight(RightDefinition::CONFIG, READ);
$canPin    = Session::haveRight(RightDefinition::CONFIG, UPDATE);

$states = [
    'attention' => [__('Needs review or not found', 'backupinfo'), ['review', 'unmatched']],
    'manual'    => [__('Pinned by hand', 'backupinfo'), ['manual']],
    'matched'   => [__('Matched', 'backupinfo'), ['matched']],
    'all'       => [__('All', 'backupinfo'), null],
];
$state = is_string($_GET['state'] ?? null) && isset($states[$_GET['state']]) ? $_GET['state'] : 'attention';
$start = max(0, (int) ($_GET['start'] ?? 0));
$limit = 50;

// Visibility: the Computer's entity, or (no Computer) config READ.
$entity  = getEntitiesRestrictCriteria('glpi_computers');
$withPc  = ['glpi_computers.id' => ['>', 0]] + $entity;
// Config READ also sees rows whose Computer was purged (no join hit), so they can be unpinned.
$visible = $canConfig ? ['OR' => [[$table . '.items_id' => 0], ['glpi_computers.id' => null], $withPc]] : $withPc;
$where   = [$visible];
if ($states[$state][1] !== null) {
    $where[$table . '.match_state'] = $states[$state][1];
}
$criteria = [
    'SELECT'    => [$table . '.*', 'glpi_computers.name AS computer_name', 'glpi_plugin_backupinfo_connections.name AS connection_name', 'glpi_plugin_backupinfo_connections.type AS connection_type'],
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
];
$total = count($DB->request($criteria));
$rows  = $DB->request($criteria + ['ORDER' => [$table . '.name'], 'START' => $start, 'LIMIT' => $limit]);

Html::header(__('Backup objects', 'backupinfo'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'backupinfo', 'objects');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('backupinfo', 'objects');

$page = Html::getPrefixedUrl('/plugins/backupinfo/front/objects.php');
echo '<ul class="nav nav-pills mb-3">';
foreach ($states as $key => [$label]) {
    echo '<li class="nav-item"><a class="nav-link' . ($key === $state ? ' active' : '') . '" href="' . htmlescape($page . '?state=' . $key) . '">' . htmlescape($label) . '</a></li>';
}
echo '</ul>';
if (!$canConfig) {
    echo '<div class="text-muted mb-2">' . __s('Objects without a computer are shown only to Backup Info administrators.', 'backupinfo') . '</div>';
}

$types  = Registry::types();
$labels = [
    'matched'   => __('Matched', 'backupinfo'),
    'manual'    => __('Pinned by hand', 'backupinfo'),
    'review'    => __('Needs review (several computers)', 'backupinfo'),
    'unmatched' => __('Not found', 'backupinfo'),
];
echo '<table class="tab_cadre_fixehov"><tr class="noHover">'
    . '<th>' . __s('Name') . '</th><th>' . __s('IP') . '</th><th>' . __s('Kind', 'backupinfo') . '</th><th>' . __s('Job', 'backupinfo') . '</th>'
    . '<th>' . __s('Last backup', 'backupinfo') . '</th><th>' . __s('Source', 'backupinfo') . '</th><th>' . __s('Match', 'backupinfo') . '</th>'
    . '<th>' . __s('Computer') . '</th>' . ($canPin ? '<th>' . __s('Action') . '</th>' : '') . '</tr>';
$shown = 0;
foreach ($rows as $row) {
    $shown++;
    $match = (string) ($row['match_state'] ?? '');
    $cid   = (int) $row['items_id'];
    echo '<tr class="tab_bg_1">'
        . '<td>' . htmlescape((string) $row['name']) . '</td>'
        . '<td>' . htmlescape((string) $row['ip']) . '</td>'
        . '<td>' . htmlescape((string) $row['kind']) . '</td>'
        . '<td>' . htmlescape((string) $row['job_name']) . '</td>'
        . '<td>' . htmlescape(!empty($row['last_backup']) ? Html::convDateTime((string) $row['last_backup']) : '-') . '</td>'
        . '<td>' . htmlescape(($types[(string) $row['connection_type']] ?? (string) $row['connection_type']) . ' · ' . (string) $row['connection_name']) . '</td>'
        . '<td>' . htmlescape($labels[$match] ?? $match) . '</td>'
        . '<td>' . ($cid > 0 ? '<a href="' . htmlescape(Computer::getFormURLWithID($cid)) . '">' . htmlescape((string) ($row['computer_name'] ?? ('#' . $cid))) . '</a>' : '') . '</td>';
    if ($canPin) {
        echo '<td><form method="post" action="' . htmlescape($page) . '" class="d-flex gap-2 align-items-center">';
        echo Html::hidden('id', ['value' => (int) $row['id']]);
        if ($match === 'manual') {
            echo Html::submit(__('Unpin', 'backupinfo'), ['name' => 'unpin', 'class' => 'btn btn-sm btn-outline-secondary']);
        } else {
            Computer::dropdown(['name' => 'items_id', 'value' => $cid, 'width' => '16rem', 'rand' => 'bi' . (int) $row['id']]);
            echo Html::submit(__('Pin', 'backupinfo'), ['name' => 'pin', 'class' => 'btn btn-sm btn-primary']);
        }
        Html::closeForm();
        echo '</td>';
    }
    echo '</tr>';
}
if ($shown === 0) {
    echo '<tr class="tab_bg_1"><td colspan="' . ($canPin ? 9 : 8) . '">' . __s('None.', 'backupinfo') . '</td></tr>';
}
echo '</table>';

echo '<div class="d-flex gap-3 align-items-center mt-2">';
echo '<span>' . htmlescape(sprintf(__('%1$d-%2$d of %3$d', 'backupinfo'), $total === 0 ? 0 : $start + 1, $start + $shown, $total)) . '</span>';
if ($start > 0) {
    echo '<a href="' . htmlescape($page . '?state=' . $state . '&start=' . max(0, $start - $limit)) . '">' . __s('Previous') . '</a>';
}
if ($start + $limit < $total) {
    echo '<a href="' . htmlescape($page . '?state=' . $state . '&start=' . ($start + $limit)) . '">' . __s('Next') . '</a>';
}
echo '</div>';

Html::footer();
