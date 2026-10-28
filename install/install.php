<?php

use GlpiPlugin\Backupinfo\Cron;

function plugin_backupinfo_install()
{
    global $DB;

    $migration = new Migration(PLUGIN_BACKUPINFO_VERSION);
    $ctx       = 'plugin:backupinfo';

    // Connections (rc2 table is kept and upgraded in place)
    if (!$DB->tableExists('glpi_plugin_backupinfo_connections')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_backupinfo_connections` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL DEFAULT '',
            `type` varchar(50) NOT NULL DEFAULT 'vinchin',
            `host` varchar(255) DEFAULT NULL,
            `auth_mode` varchar(30) NOT NULL DEFAULT 'token',
            `api_key` text,
            `username` varchar(255) DEFAULT NULL,
            `password` text,
            `verify_tls` tinyint NOT NULL DEFAULT 1,
            `is_active` tinyint NOT NULL DEFAULT 1,
            `last_sync` timestamp NULL DEFAULT NULL,
            `last_status` varchar(50) DEFAULT NULL,
            `last_error` text,
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } else {
        $added = [
            'host'        => "varchar(255) DEFAULT NULL",
            'auth_mode'   => "varchar(30) NOT NULL DEFAULT 'token'",
            'username'    => "varchar(255) DEFAULT NULL",
            'password'    => "text",
            'verify_tls'  => "tinyint NOT NULL DEFAULT 1",
            'last_sync'   => "timestamp NULL DEFAULT NULL",
            'last_status' => "varchar(50) DEFAULT NULL",
            'last_error'  => "text",
        ];
        foreach ($added as $field => $definition) {
            if (!$DB->fieldExists('glpi_plugin_backupinfo_connections', $field)) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_backupinfo_connections` ADD COLUMN `$field` $definition");
            }
        }
    }

    // Backed-up objects reported by the source
    if (!$DB->tableExists('glpi_plugin_backupinfo_objects')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_backupinfo_objects` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `connections_id` int unsigned NOT NULL DEFAULT 0,
            `source_key` varchar(255) NOT NULL DEFAULT '',
            `name` varchar(255) DEFAULT NULL,
            `ip` varchar(255) DEFAULT NULL,
            `kind` varchar(50) DEFAULT NULL,
            `job_name` varchar(255) DEFAULT NULL,
            `last_backup` timestamp NULL DEFAULT NULL,
            `restore_points` int unsigned NOT NULL DEFAULT 0,
            `data_size` bigint unsigned NOT NULL DEFAULT 0,
            `abnormal_chain` tinyint NOT NULL DEFAULT 0,
            `source_deleted` tinyint NOT NULL DEFAULT 0,
            `itemtype` varchar(100) DEFAULT NULL,
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `match_state` varchar(30) DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`connections_id`, `source_key`),
            KEY `item` (`itemtype`, `items_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // Manual policy: assets expected to be backed up
    if (!$DB->tableExists('glpi_plugin_backupinfo_policies')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_backupinfo_policies` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`itemtype`, `items_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // One-shot: drop the rc1/rc2 tables (install runs on every upgrade)
    $marker = Config::getConfigurationValues($ctx, ['rc_tables_dropped']);
    if (empty($marker['rc_tables_dropped'])) {
        foreach (
            [
                'glpi_plugin_backupinfo_assets',
                'glpi_plugin_backupinfo_jobs',
                'glpi_plugin_backupinfo_storages',
                'glpi_plugin_backupinfo_mappings',
            ] as $old
        ) {
            $DB->doQuery("DROP TABLE IF EXISTS `$old`");
        }
        Config::setConfigurationValues($ctx, ['rc_tables_dropped' => 1]);
    }

    // Config defaults: only keys that are not set yet
    $defaults = [
        'rpo_hours'              => 26,
        'policy_mode'            => 'field',
        'fields_container'       => '',
        'fields_status_field'    => '',
        'fields_media_field'     => '',
        'fields_mandatory_field' => '',
    ];
    $current = Config::getConfigurationValues($ctx, array_keys($defaults));
    $missing = array_diff_key($defaults, $current);
    if ($missing !== []) {
        Config::setConfigurationValues($ctx, $missing);
    }

    // Cron (class resolved by name, it need not exist at install time)
    CronTask::register(Cron::class, 'Sync', HOUR_TIMESTAMP, [
        'comment' => 'Pull backup status from the configured sources',
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
    ]);

    // Set rights for Super-Admin
    if (class_exists('GlpiPlugin\Backupinfo\Profile')) {
        \GlpiPlugin\Backupinfo\Profile::install();
    }

    $migration->executeMigration();

    return true;
}
