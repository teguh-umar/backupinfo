<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_backupinfo_config', READ);

$connection = new GlpiPlugin\Backupinfo\Connection();

if (isset($_POST['add'])) {
    Session::checkRight('plugin_backupinfo_config', CREATE);
    $newID = $connection->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    Session::checkRight('plugin_backupinfo_config', UPDATE);
    $connection->update($_POST);
    Html::back();
} elseif (isset($_POST['delete'])) {
    Session::checkRight('plugin_backupinfo_config', PURGE);
    $connection->delete($_POST);
    $connection->redirectToList();
}

Html::header('Backup Info - Connection', $_SERVER['PHP_SELF'], 'plugins', 'backupinfo', 'connection');

$connection->display(['id' => $_GET['id'] ?? 0]);

Html::footer();
