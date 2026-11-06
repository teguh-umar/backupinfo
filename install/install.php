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

        // Legacy rc columns: carry api_url over to host, then drop the unused ones
        if ($DB->fieldExists('glpi_plugin_backupinfo_connections', 'api_url')) {
            $DB->doQuery("UPDATE `glpi_plugin_backupinfo_connections`
                SET `host` = `api_url` WHERE (`host` IS NULL OR `host` = '')");
        }
        foreach (['api_url', 'api_secret', 'token', 'site'] as $legacy) {
            if ($DB->fieldExists('glpi_plugin_backupinfo_connections', $legacy)) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_backupinfo_connections` DROP COLUMN `$legacy`");
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
            `data_size` varchar(64) NOT NULL DEFAULT '',
            `schedule_hours` int unsigned DEFAULT NULL,
            `timepoint_uuid` varchar(64) DEFAULT NULL,
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
    } else {
        if (!$DB->fieldExists('glpi_plugin_backupinfo_objects', 'schedule_hours')) {
            // 0.2.0-rc6: job schedule interval (NULL = unknown, the global RPO applies)
            $DB->doQuery("ALTER TABLE `glpi_plugin_backupinfo_objects` ADD COLUMN `schedule_hours` int unsigned DEFAULT NULL AFTER `data_size`");
        }
        if (!$DB->fieldExists('glpi_plugin_backupinfo_objects', 'timepoint_uuid')) {
            // 0.3.0: latest restore point, to read its Vinchin verification history
            $DB->doQuery("ALTER TABLE `glpi_plugin_backupinfo_objects` ADD COLUMN `timepoint_uuid` varchar(64) DEFAULT NULL AFTER `schedule_hours`");
        }
    }

    // 0.3.0 audit evidence: every backup run, append-only (the ICOFR population)
    if (!$DB->tableExists('glpi_plugin_backupinfo_runs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_backupinfo_runs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `connections_id` int unsigned NOT NULL DEFAULT 0,
            `history_uuid` varchar(64) NOT NULL DEFAULT '',
            `job_name` varchar(255) NOT NULL DEFAULT '',
            `job_uuid` varchar(64) NOT NULL DEFAULT '',
            `start_time` timestamp NULL DEFAULT NULL,
            `finish_time` timestamp NULL DEFAULT NULL,
            `status` varchar(20) NOT NULL DEFAULT '',
            `status_text` varchar(64) NOT NULL DEFAULT '',
            `size_text` varchar(64) NOT NULL DEFAULT '',
            `duration_s` int unsigned DEFAULT NULL,
            `reason` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`connections_id`, `history_uuid`),
            KEY `start_time` (`start_time`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // Which Computers a run covered; tickets_id / problems_id are filled by stage 3 (0.4.0)
    if (!$DB->tableExists('glpi_plugin_backupinfo_runs_items')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_backupinfo_runs_items` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `runs_id` int unsigned NOT NULL DEFAULT 0,
            `computers_id` int unsigned NOT NULL DEFAULT 0,
            `vm_name` varchar(255) NOT NULL DEFAULT '',
            `item_status` varchar(20) NOT NULL DEFAULT '',
            `tickets_id` int unsigned NOT NULL DEFAULT 0,
            `problems_id` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`runs_id`, `vm_name`),
            KEY `computers_id` (`computers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // 0.3.0-rc5: off-site copy runs, job/system logs and system alarms, append-only, so the audit
    // page keeps them after the backup system purges its own history
    if (!$DB->tableExists('glpi_plugin_backupinfo_events')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_backupinfo_events` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `connections_id` int unsigned NOT NULL DEFAULT 0,
            `kind` varchar(16) NOT NULL DEFAULT '',
            `event_time` timestamp NULL DEFAULT NULL,
            `fingerprint` char(40) NOT NULL DEFAULT '',
            `data` mediumtext,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`connections_id`, `kind`, `fingerprint`),
            KEY `kind_time` (`kind`, `event_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // 0.4.0: the Incident opened for a failed run x Computer (once, ever) and its Problem
    if (!$DB->tableExists('glpi_plugin_backupinfo_tickets')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_backupinfo_tickets` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `runs_id` int unsigned NOT NULL DEFAULT 0,
            `computers_id` int unsigned NOT NULL DEFAULT 0,
            `tickets_id` int unsigned NOT NULL DEFAULT 0,
            `problems_id` int unsigned NOT NULL DEFAULT 0,
            `followup_done` tinyint NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`runs_id`, `computers_id`),
            KEY `computers_id` (`computers_id`),
            KEY `tickets_id` (`tickets_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // 0.4.0-rc3: the optional "Backup succeeded again" follow-up is added once per Incident
    if (!$DB->fieldExists('glpi_plugin_backupinfo_tickets', 'followup_done')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_backupinfo_tickets` ADD COLUMN `followup_done` tinyint NOT NULL DEFAULT 0 AFTER `problems_id`");
    }

    // 0.3.0-rc7: daily state of what the backup system only shows "as of now" (targets, platform VMs)
    if (!$DB->tableExists('glpi_plugin_backupinfo_snapshots')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_backupinfo_snapshots` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `connections_id` int unsigned NOT NULL DEFAULT 0,
            `kind` varchar(16) NOT NULL DEFAULT '',
            `snap_date` date DEFAULT NULL,
            `taken_at` timestamp NULL DEFAULT NULL,
            `data` mediumtext,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`connections_id`, `kind`, `snap_date`),
            KEY `kind_taken` (`kind`, `taken_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // rc6: events are stored in the common Event shape. rc5 stored Vinchin rows as they came: clear
    // them once (only test installs had them); the next sync reads them back from the backup system.
    $fmt = Config::getConfigurationValues('plugin:backupinfo', ['events_format']);
    if ((int) ($fmt['events_format'] ?? 0) < 2) {
        $DB->doQuery('TRUNCATE TABLE `glpi_plugin_backupinfo_events`');
        Config::setConfigurationValues('plugin:backupinfo', ['events_format' => 2]);
    }

    // Restore tests: manual records and imported Vinchin verifications (source = vinchin)
    if (!$DB->tableExists('glpi_plugin_backupinfo_restoretests')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_backupinfo_restoretests` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `computers_id` int unsigned NOT NULL DEFAULT 0,
            `date` date DEFAULT NULL,
            `method` varchar(30) NOT NULL DEFAULT '',
            `result` varchar(20) NOT NULL DEFAULT '',
            `rto_minutes` int unsigned DEFAULT NULL,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `source` varchar(20) NOT NULL DEFAULT 'manual',
            `source_ref` varchar(128) DEFAULT NULL,
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `source_ref` (`source`, `source_ref`),
            KEY `computer_date` (`computers_id`, `date`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // Per-Computer restore-test interval override (months, 0 = exempt); no row = Setup default
    if (!$DB->tableExists('glpi_plugin_backupinfo_restoreintervals')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_backupinfo_restoreintervals` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `computers_id` int unsigned NOT NULL DEFAULT 0,
            `months` smallint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`computers_id`)
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
        'rpo_grace_hours'        => 24,
        'policy_mode'            => 'field',
        'fields_container'       => '',
        'fields_status_field'    => '',
        'fields_media_field'     => '',
        'fields_mandatory_field' => '',
        'run_pages_max'          => 20,
        'run_keep_months'        => 36,
        'restore_interval_months' => 12,
        'tickets_enabled'        => 0,
        'tickets_since'          => '',
        'problem_count'          => 3,
        'problem_days'           => 30,
        'findings_enabled'       => 0,
        'followup_on_success'    => 0,
    ];
    $current = Config::getConfigurationValues($ctx, array_keys($defaults));
    $missing = array_diff_key($defaults, $current);
    if ($missing !== []) {
        Config::setConfigurationValues($ctx, $missing);
    }
    // 0.4.0: the ticket templates the admin fills in (requester, groups, supplier, category)
    \GlpiPlugin\Backupinfo\Tickets\TicketService::ensureTemplates();

    // Cron (class resolved by name, it need not exist at install time)
    CronTask::register(Cron::class, 'Sync', HOUR_TIMESTAMP, [
        'comment' => 'Pull backup status from the configured sources',
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
    ]);

    // Set rights for Super-Admin
    if (class_exists('GlpiPlugin\Backupinfo\Profile')) {
        \GlpiPlugin\Backupinfo\Profile::install();

        // One-shot (0.2.0): config UPDATE holders also get CREATE|PURGE (connection add/delete)
        $marker = Config::getConfigurationValues($ctx, ['config_rights_v020']);
        if (empty($marker['config_rights_v020'])) {
            // Marker only after the read-back confirms the rights; otherwise the next upgrade retries.
            if (\GlpiPlugin\Backupinfo\Profile::grantCreatePurgeToEditors()) {
                Config::setConfigurationValues($ctx, ['config_rights_v020' => 1]);
            }
        }
    }

    $migration->executeMigration();

    return true;
}
