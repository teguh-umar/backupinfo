<?php

require __DIR__ . '/bootstrap.php';

$install   = (string) file_get_contents(BI_ROOT . '/install/install.php');
$uninstall = (string) file_get_contents(BI_ROOT . '/install/uninstall.php');

$tables = [
    'glpi_plugin_backupinfo_connections' => ['id', 'name', 'type', 'host', 'auth_mode', 'api_key', 'username',
        'password', 'verify_tls', 'is_active', 'last_sync', 'last_status', 'last_error', 'comment',
        'date_creation', 'date_mod'],
    'glpi_plugin_backupinfo_objects'     => ['id', 'connections_id', 'source_key', 'name', 'ip', 'kind', 'job_name',
        'last_backup', 'restore_points', 'data_size', 'abnormal_chain', 'source_deleted', 'itemtype', 'items_id',
        'match_state', 'date_mod'],
    'glpi_plugin_backupinfo_policies'    => ['id', 'itemtype', 'items_id'],
];

foreach ($tables as $table => $columns) {
    check("install creates $table", str_contains($install, "CREATE TABLE `$table`"));
    check("install guards $table with tableExists", (bool) preg_match("/tableExists\(\s*'$table'/", $install));
    check("uninstall drops $table", str_contains($uninstall, $table));
    $start = strpos($install, "CREATE TABLE `$table`");
    $end   = $start === false ? false : strpos($install, 'ENGINE=', $start);
    $block = ($start === false || $end === false) ? '' : substr($install, $start, $end - $start);
    foreach ($columns as $col) {
        check("$table has column $col", str_contains($block, "`$col`"));
    }
}
check('objects unique (connections_id, source_key)',
    (bool) preg_match('/UNIQUE KEY `[a-z_]+` \(`connections_id`,\s*`source_key`\)/', $install));
check('objects index (itemtype, items_id)',
    (bool) preg_match('/KEY `[a-z_]+` \(`itemtype`,\s*`items_id`\)/', $install));
check('policies unique (itemtype, items_id)',
    (bool) preg_match('/UNIQUE KEY `[a-z_]+` \(`itemtype`,\s*`items_id`\)/', $install));
check('install adds missing columns idempotently (fieldExists)', str_contains($install, 'fieldExists'));

// One-shot drop of the rc tables.
foreach (['assets', 'jobs', 'storages', 'mappings'] as $old) {
    check("install drops rc table _$old", str_contains($install, "glpi_plugin_backupinfo_$old"));
}
check('rc drop is behind rc_tables_dropped marker', substr_count($install, 'rc_tables_dropped') >= 2);
check('marker read via getConfigurationValues', str_contains($install, 'getConfigurationValues'));
check('marker written via setConfigurationValues', str_contains($install, 'setConfigurationValues'));
check('config context plugin:backupinfo', str_contains($install, "plugin:backupinfo"));
$posIf  = strpos($install, 'rc_tables_dropped');
$posSet = strrpos($install, 'rc_tables_dropped');
$posDrop = strpos($install, 'DROP TABLE');
check('marker checked before drop, set after drop', $posIf !== false && $posDrop !== false && $posIf < $posDrop && $posSet > $posDrop);

// Config defaults.
foreach (['rpo_hours', 'policy_mode', 'fields_container', 'fields_status_field', 'fields_media_field',
    'fields_mandatory_field'] as $key) {
    check("install seeds config $key", str_contains($install, "'$key'"));
}
check('rpo_hours default 26', (bool) preg_match("/'rpo_hours'\s*=>\s*(26|'26')/", $install));
check('policy_mode default field', (bool) preg_match("/'policy_mode'\s*=>\s*'field'/", $install));
check('defaults do not overwrite existing values', str_contains($install, 'getConfigurationValues'));

// Cron.
check('cron registered', str_contains($install, "CronTask::register(Cron::class, 'Sync', HOUR_TIMESTAMP"));
check('cron class imported by name', str_contains($install, 'use GlpiPlugin\Backupinfo\Cron;'));
check('uninstall unregisters cron', str_contains($uninstall, 'CronTask::unregister'));
check('uninstall deletes config context', str_contains($uninstall, 'deleteConfigurationValues')
    && str_contains($uninstall, 'plugin:backupinfo'));
check('uninstall drops leftover rc tables', str_contains($uninstall, 'glpi_plugin_backupinfo_assets'));
check('function names kept', str_contains($install, 'function plugin_backupinfo_install()')
    && str_contains($uninstall, 'function plugin_backupinfo_uninstall()'));

done();
