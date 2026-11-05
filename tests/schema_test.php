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
    // 0.3.0 audit evidence (stage 2)
    'glpi_plugin_backupinfo_runs'        => ['id', 'connections_id', 'history_uuid', 'job_name', 'job_uuid', 'start_time',
        'finish_time', 'status', 'status_text', 'size_text', 'duration_s', 'reason', 'date_creation'],
    'glpi_plugin_backupinfo_runs_items'  => ['id', 'runs_id', 'computers_id', 'vm_name', 'item_status', 'tickets_id', 'problems_id'],
    'glpi_plugin_backupinfo_restoretests' => ['id', 'entities_id', 'is_recursive', 'computers_id', 'date', 'method', 'result',
        'rto_minutes', 'users_id', 'source', 'source_ref', 'comment', 'date_creation', 'date_mod'],
    'glpi_plugin_backupinfo_restoreintervals' => ['id', 'computers_id', 'months'],
    // 0.3.0-rc5: copy runs, job/system logs and system alarms kept after Vinchin purges them
    'glpi_plugin_backupinfo_events' => ['id', 'connections_id', 'kind', 'event_time', 'fingerprint', 'data', 'date_creation'],
    'glpi_plugin_backupinfo_snapshots' => ['id', 'connections_id', 'kind', 'snap_date', 'taken_at', 'data'],
    'glpi_plugin_backupinfo_tickets' => ['id', 'runs_id', 'computers_id', 'tickets_id', 'problems_id', 'date_creation'],
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
check('runs unique (connections_id, history_uuid)',
    (bool) preg_match('/UNIQUE KEY `[a-z_]+` \(`connections_id`,\s*`history_uuid`\)/', $install));
check('runs_items unique (runs_id, vm_name): unmatched VMs (computers_id 0) do not collide',
    (bool) preg_match('/UNIQUE KEY `[a-z_]+` \(`runs_id`,\s*`vm_name`\)/', $install));
check('events unique (connections_id, kind, fingerprint)',
    (bool) preg_match('/UNIQUE KEY `[a-z_]+` \(`connections_id`,\s*`kind`,\s*`fingerprint`\)/', $install));
check('events index (kind, event_time)', (bool) preg_match('/KEY `[a-z_]+` \(`kind`,\s*`event_time`\)/', $install));
check('restoretests unique (source, source_ref)',
    (bool) preg_match('/UNIQUE KEY `[a-z_]+` \(`source`,\s*`source_ref`\)/', $install));
check('restoreintervals unique (computers_id)',
    (bool) preg_match('/UNIQUE KEY `[a-z_]+` \(`computers_id`\)/', $install));
check('objects table has timepoint_uuid', str_contains($install, '`timepoint_uuid` varchar(64) DEFAULT NULL'));
check('upgrade adds timepoint_uuid behind fieldExists', (bool) preg_match("/fieldExists\\('glpi_plugin_backupinfo_objects', 'timepoint_uuid'\\)/", $install));
foreach (['run_pages_max' => 20, 'run_keep_months' => 36, 'restore_interval_months' => 12] as $key => $value) {
    check("config default $key = $value", (bool) preg_match("/'$key'\s*=>\s*$value,/", $install));
}

// One-shot drop of the rc tables.
foreach (['assets', 'jobs', 'storages', 'mappings'] as $old) {
    check("install drops rc table _$old", str_contains($install, "glpi_plugin_backupinfo_$old"));
}
check('marker read via getConfigurationValues', str_contains($install, 'getConfigurationValues'));
check('marker written via setConfigurationValues', str_contains($install, 'setConfigurationValues'));
check('config context plugin:backupinfo', str_contains($install, "plugin:backupinfo"));
$guarded = preg_match('/if \(empty\(\$marker\[\'rc_tables_dropped\'\]\)\) \{(.*?)\r?\n    \}\r?\n/s', $install, $m) ? $m[1] : '';
check('rc drop guarded by empty(marker) block', $guarded !== '');
foreach (['assets', 'jobs', 'storages', 'mappings'] as $old) {
    check("rc table _$old appears only inside guarded block",
        substr_count($install, "glpi_plugin_backupinfo_$old") === 1 && str_contains($guarded, "glpi_plugin_backupinfo_$old"));
}
check('all DROP TABLE statements are inside guarded block', substr_count($install, 'DROP TABLE') === substr_count($guarded, 'DROP TABLE')
    && substr_count($guarded, 'DROP TABLE') >= 1);
$lastDrop = strrpos($guarded, 'DROP TABLE');
$setPos   = strpos($guarded, "setConfigurationValues(\$ctx, ['rc_tables_dropped'");
check('marker set inside guarded block after the last DROP', $setPos !== false && $lastDrop !== false && $setPos > $lastDrop);
check('marker set only once in install', substr_count($install, "'rc_tables_dropped' =>") === 1);

// Legacy connections columns.
check('api_url copied to host only where host empty', (bool) preg_match('/SET `host` = `api_url` WHERE \(`host` IS NULL OR `host` = \'\'\)/', $install));
foreach (['api_url', 'api_secret', 'token', 'site'] as $legacy) {
    check("legacy column $legacy dropped behind fieldExists", str_contains($install, "'$legacy'")
        && (bool) preg_match('/foreach \(\[[^\]]*\'' . $legacy . '\'[^\]]*\] as \$legacy\) \{\s*if \(\$DB->fieldExists\([^)]*\$legacy\)\) \{\s*\$DB->doQuery\("ALTER TABLE [^"]*DROP COLUMN/', $install));
}

// Config defaults.
foreach (['rpo_hours', 'policy_mode', 'fields_container', 'fields_status_field', 'fields_media_field',
    'fields_mandatory_field'] as $key) {
    check("install seeds config $key", str_contains($install, "'$key'"));
}
check('rpo_grace_hours default 24', (bool) preg_match("/'rpo_grace_hours'\s*=>\s*(24|'24')/", $install));
check('objects table has schedule_hours (NULL = unknown)', str_contains($install, '`schedule_hours` int unsigned DEFAULT NULL,'));
check('upgrade adds schedule_hours behind fieldExists', (bool) preg_match("/fieldExists\\('glpi_plugin_backupinfo_objects', 'schedule_hours'\\)\\) \\{\\s*\\/\\/[^\\n]*\\n\\s*\\\$DB->doQuery\\(\"ALTER TABLE `glpi_plugin_backupinfo_objects` ADD COLUMN `schedule_hours`/", $install));
check('rpo_hours default 26', (bool) preg_match("/'rpo_hours'\s*=>\s*(26|'26')/", $install));
check('policy_mode default field', (bool) preg_match("/'policy_mode'\s*=>\s*'field'/", $install));
check('defaults only written for keys missing from current values',
    str_contains($install, 'getConfigurationValues($ctx, array_keys($defaults))')
    && str_contains($install, 'array_diff_key($defaults, $current)')
    && str_contains($install, 'setConfigurationValues($ctx, $missing)')
    && !str_contains($install, 'setConfigurationValues($ctx, $defaults)'));

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
