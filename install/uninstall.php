<?php

function plugin_backupinfo_uninstall()
{
    global $DB;

    $tables = [
        'glpi_plugin_backupinfo_events',
        'glpi_plugin_backupinfo_snapshots',
        'glpi_plugin_backupinfo_tickets',
        'glpi_plugin_backupinfo_runs_items',
        'glpi_plugin_backupinfo_runs',
        'glpi_plugin_backupinfo_restoretests',
        'glpi_plugin_backupinfo_restoreintervals',
        'glpi_plugin_backupinfo_policies',
        'glpi_plugin_backupinfo_objects',
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

    $cfg = Config::getConfigurationValues('plugin:backupinfo');
    if ($cfg !== []) {
        Config::deleteConfigurationValues('plugin:backupinfo', array_keys($cfg));
    }
    CronTask::unregister('backupinfo');

    $DB->doQuery("DELETE FROM `glpi_displaypreferences` WHERE `itemtype` LIKE 'GlpiPlugin\\\\Backupinfo\\\\%'");
    $DB->doQuery("DELETE FROM `glpi_notepads` WHERE `itemtype` LIKE 'GlpiPlugin\\\\Backupinfo\\\\%'");
    $DB->doQuery("DELETE FROM `glpi_savedsearches` WHERE `itemtype` LIKE 'GlpiPlugin\\\\Backupinfo\\\\%'");
    $DB->doQuery("DELETE FROM `glpi_documents_items` WHERE `itemtype` LIKE 'GlpiPlugin\\\\Backupinfo\\\\%'");
    $DB->doQuery("DELETE FROM `glpi_logs` WHERE `itemtype` LIKE 'GlpiPlugin\\\\Backupinfo\\\\%'");

    return true;
}
