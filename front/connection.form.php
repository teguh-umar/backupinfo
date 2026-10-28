<?php

use GlpiPlugin\Backupinfo\Connection;
use GlpiPlugin\Backupinfo\RightDefinition;

include('../../../inc/includes.php');
Session::checkRight(RightDefinition::CONFIG, READ);

$connection = new Connection();

if (isset($_POST['add'])) {
    Session::checkRight(RightDefinition::CONFIG, CREATE);
    $connection->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $connection->update($_POST);
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight(RightDefinition::CONFIG, PURGE);
    $connection->delete($_POST);
    $connection->redirectToList();
}

$menus = ['central' => [\GlpiPlugin\Pgnsuite\Menu::SECTOR, 'backupinfo', 'connection'], 'helpdesk' => []];
Connection::displayFullPageForItem((int) ($_GET['id'] ?? 0), $menus, []);
