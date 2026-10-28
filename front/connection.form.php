<?php

use GlpiPlugin\Backupinfo\Connection;
use GlpiPlugin\Backupinfo\RightDefinition;

include('../../../inc/includes.php');
Session::checkRight(RightDefinition::CONFIG, READ);

$connection = new Connection();
$id         = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

if (isset($_POST['add'])) {
    $connection->check(-1, CREATE, $_POST);
    $new = $connection->add($_POST);
    Html::redirect($new ? Connection::getFormURLWithID($new) : Connection::getFormURL());
}

if (isset($_POST['update'])) {
    $connection->check($id, UPDATE);
    $connection->update($_POST);
    Html::back();
}

// GLPI 11 posts "purge" for an item without trash; "delete" is accepted as an alias
// because the connections table has no is_deleted column (no trash).
if (isset($_POST['purge']) || isset($_POST['delete'])) {
    $connection->check($id, PURGE);
    $connection->delete(['id' => $id], true);
    Html::redirect(Connection::getSearchURL());
}

$menus = ['central' => [\GlpiPlugin\Pgnsuite\Menu::SECTOR, 'backupinfo', 'connection'], 'helpdesk' => []];
Connection::displayFullPageForItem($id, $menus, []);
