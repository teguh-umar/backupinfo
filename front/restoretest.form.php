<?php

/**
 * Restore test: add / update / purge, the form, and the per-Computer interval override posted from
 * the asset tab. CSRF: checked by the GLPI 11 kernel for every POST (plugin is csrf_compliant).
 */

use GlpiPlugin\Backupinfo\RestoreInterval;
use GlpiPlugin\Backupinfo\RestoreTest;
use GlpiPlugin\Backupinfo\RightDefinition;

include('../../../inc/includes.php');
Session::checkRight(RightDefinition::RESTORETEST, READ);

$test = new RestoreTest();
$id   = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

if (isset($_POST['set_interval'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $computerId = (int) ($_POST['computers_id'] ?? 0);
    $computer   = new Computer();
    if ($computerId <= 0 || !$computer->can($computerId, READ)) {
        Html::displayRightError();
    }
    $parsed = RestoreInterval::parseOverride((string) ($_POST['restore_months'] ?? ''));
    if ($parsed['ok']) {
        RestoreInterval::set($computerId, $parsed['months']);
    } else {
        Session::addMessageAfterRedirect(htmlescape(sprintf(__('The restore-test interval must be blank or 0 to %d months; it was not changed.', 'backupinfo'), RestoreInterval::MAX_MONTHS)), false, ERROR);
    }
    Html::back();
}

if (isset($_POST['add'])) {
    $test->check(-1, CREATE, $_POST);
    $newId = $test->add($_POST);
    if ($newId) {
        Html::redirect($test->getLinkURL());
    }
    Html::back();
}

if (isset($_POST['update'])) {
    $test->check($id, UPDATE);
    $test->update($_POST);
    Html::back();
}

// No trash (no is_deleted column): GLPI 11 posts "purge"; "delete" is accepted as an alias.
if (isset($_POST['purge']) || isset($_POST['delete'])) {
    $test->check($id, PURGE);
    $test->delete(['id' => $id], true);
    Html::redirect(RestoreTest::getSearchURL());
}

Html::header(RestoreTest::getTypeName(Session::getPluralNumber()), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'backupinfo', 'restoretests');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('backupinfo', 'restoretests');
$test->display(['id' => $id, 'computers_id' => (int) ($_GET['computers_id'] ?? 0)]);
Html::footer();
