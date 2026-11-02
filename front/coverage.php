<?php

/**
 * Coverage: computers that must be backed up vs their backup status, in the user's
 * active entities; plus backup objects without a computer (config READ only).
 */

use GlpiPlugin\Backupinfo\CoveragePage;
use GlpiPlugin\Backupinfo\Policy;
use GlpiPlugin\Backupinfo\RightDefinition;
use GlpiPlugin\Backupinfo\Rpo;
use GlpiPlugin\Backupinfo\Sync\DbObjectStore;

include('../../../inc/includes.php');
Session::checkRight(RightDefinition::REPORT, READ);
global $DB; // front files run inside a function under GLPI 11 routing

$rpo    = Rpo::fromConfig();
$now    = time();
$policy = Policy::fromConfig();

// Mandatory Computers visible in the active entities (not deleted, not templates).
$computers = [];
foreach (array_chunk($policy->mandatoryIds(), 500) as $chunk) {
    foreach ($DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => 'glpi_computers',
        'WHERE'  => ['id' => $chunk, 'is_deleted' => 0, 'is_template' => 0] + getEntitiesRestrictCriteria('glpi_computers'),
    ]) as $row) {
        $computers[(int) $row['id']] = ['name' => (string) $row['name']];
    }
}
$model = CoveragePage::build($computers, Policy::groupByItem((new DbObjectStore())->matched()), $rpo, $now);

// Objects with no Computer cannot be placed in an entity: config READ only.
$unmatched      = null;
$unmatchedTotal = 0;
if (Session::haveRight(RightDefinition::CONFIG, READ)) {
    $where = ['match_state' => ['unmatched', 'review'], 'items_id' => 0];
    $unmatchedTotal = count($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_backupinfo_objects', 'WHERE' => $where]));
    $unmatched = [];
    foreach ($DB->request(['FROM' => 'glpi_plugin_backupinfo_objects', 'WHERE' => $where, 'ORDER' => ['name'], 'LIMIT' => 20]) as $row) {
        $unmatched[] = $row;
    }
}

Html::header(__('Backup coverage', 'backupinfo'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'backupinfo', 'coverage');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('backupinfo', 'coverage');
CoveragePage::render($model, $policy->mode(), $rpo, $unmatched, $unmatchedTotal, $now);
Html::footer();
