<?php

function plugin_backupinfo_uninstall()
{
    global $DB;

    $tables = [
        'glpi_plugin_backupinfo_mappings',
        'glpi_plugin_backupinfo_storages',
        'glpi_plugin_backupinfo_jobs',
        'glpi_plugin_backupinfo_assets',
        'glpi_plugin_backupinfo_connections',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE IF EXISTS `$table`");
        }
    }

    $DB->doQuery("DELETE FROM `glpi_displaypreferences` WHERE `itemtype` LIKE 'GlpiPlugin\\\\Backupinfo\\\\%'");
    $DB->doQuery("DELETE FROM `glpi_notepads` WHERE `itemtype` LIKE 'GlpiPlugin\\\\Backupinfo\\\\%'");
    $DB->doQuery("DELETE FROM `glpi_savedsearches` WHERE `itemtype` LIKE 'GlpiPlugin\\\\Backupinfo\\\\%'");
    $DB->doQuery("DELETE FROM `glpi_logs` WHERE `itemtype` LIKE 'GlpiPlugin\\\\Backupinfo\\\\%'");

    return true;
}
