<?php

/**
 * Saves a connection. The form itself is shown in Backup Info's section on the PGN Suite
 * settings page (Setup); every action returns there. A plain GET (old link, search result)
 * opens that section with the form for this id.
 */

use GlpiPlugin\Backupinfo\Connection;
use GlpiPlugin\Backupinfo\RightDefinition;
use GlpiPlugin\Backupinfo\SuiteConfig;

include('../../../inc/includes.php');
Session::checkRight(RightDefinition::CONFIG, READ);

$connection = new Connection();
$id         = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

if (isset($_POST['add'])) {
    $connection->check(-1, CREATE, $_POST);
    $new = $connection->add($_POST);
    Html::redirect(SuiteConfig::sectionUrl($new ? (int) $new : 0));
}

if (isset($_POST['update'])) {
    $connection->check($id, UPDATE);
    $connection->update($_POST);
    Html::redirect(SuiteConfig::sectionUrl($id));
}

// GLPI 11 posts "purge" for an item without trash; "delete" is accepted as an alias
// because the connections table has no is_deleted column (no trash).
if (isset($_POST['purge']) || isset($_POST['delete'])) {
    $connection->check($id, PURGE);
    $connection->delete(['id' => $id], true);
    Html::redirect(SuiteConfig::sectionUrl());
}

Html::redirect(SuiteConfig::sectionUrl($id));
