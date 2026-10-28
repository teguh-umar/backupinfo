<?php

function plugin_backupinfo_install()
{
    global $DB;

    $migration = new Migration(PLUGIN_BACKUPINFO_VERSION);

    // Connections table
    if (!$DB->tableExists('glpi_plugin_backupinfo_connections')) {
        $query = "CREATE TABLE `glpi_plugin_backupinfo_connections` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL DEFAULT '',
            `type` varchar(50) NOT NULL DEFAULT 'vinchin',
            `site` varchar(100) DEFAULT NULL,
            `api_url` varchar(255) DEFAULT NULL,
            `api_key` text,
            `api_secret` text,
            `token` text,
            `is_active` tinyint NOT NULL DEFAULT 1,
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $DB->doQuery($query);
    }

    // Protected assets
    if (!$DB->tableExists('glpi_plugin_backupinfo_assets')) {
        $query = "CREATE TABLE `glpi_plugin_backupinfo_assets` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `connections_id` int unsigned NOT NULL DEFAULT 0,
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `itemtype` varchar(100) DEFAULT NULL,
            `vm_name` varchar(255) DEFAULT NULL,
            `vm_uuid` varchar(255) DEFAULT NULL,
            `host_name` varchar(255) DEFAULT NULL,
            `ip_address` varchar(255) DEFAULT NULL,
            `os_type` varchar(100) DEFAULT NULL,
            `backup_enabled` tinyint NOT NULL DEFAULT 0,
            `last_backup_date` timestamp NULL DEFAULT NULL,
            `protection_policy` varchar(255) DEFAULT NULL,
            `source` varchar(50) DEFAULT 'api',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `connections_id` (`connections_id`),
            KEY `items_id` (`items_id`),
            KEY `itemtype` (`itemtype`),
            KEY `vm_name` (`vm_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $DB->doQuery($query);
    }

    // Backup jobs
    if (!$DB->tableExists('glpi_plugin_backupinfo_jobs')) {
        $query = "CREATE TABLE `glpi_plugin_backupinfo_jobs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `connections_id` int unsigned NOT NULL DEFAULT 0,
            `assets_id` int unsigned NOT NULL DEFAULT 0,
            `job_id` varchar(255) DEFAULT NULL,
            `job_name` varchar(255) DEFAULT NULL,
            `vm_name` varchar(255) DEFAULT NULL,
            `status` varchar(50) DEFAULT NULL,
            `type` varchar(50) DEFAULT NULL,
            `start_time` timestamp NULL DEFAULT NULL,
            `end_time` timestamp NULL DEFAULT NULL,
            `duration` int unsigned DEFAULT 0,
            `size_bytes` bigint unsigned DEFAULT 0,
            `transferred_bytes` bigint unsigned DEFAULT 0,
            `error_message` text,
            `source` varchar(50) DEFAULT 'api',
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `connections_id` (`connections_id`),
            KEY `assets_id` (`assets_id`),
            KEY `status` (`status`),
            KEY `start_time` (`start_time`),
            KEY `vm_name` (`vm_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $DB->doQuery($query);
    }

    // Storage
    if (!$DB->tableExists('glpi_plugin_backupinfo_storages')) {
        $query = "CREATE TABLE `glpi_plugin_backupinfo_storages` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `connections_id` int unsigned NOT NULL DEFAULT 0,
            `name` varchar(255) DEFAULT NULL,
            `type` varchar(100) DEFAULT NULL,
            `site` varchar(100) DEFAULT NULL,
            `total_size` bigint unsigned DEFAULT 0,
            `used_size` bigint unsigned DEFAULT 0,
            `free_size` bigint unsigned DEFAULT 0,
            `unit` varchar(10) DEFAULT 'GB',
            `status` varchar(50) DEFAULT NULL,
            `source` varchar(50) DEFAULT 'api',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `connections_id` (`connections_id`),
            KEY `site` (`site`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $DB->doQuery($query);
    }

    // Mappings
    if (!$DB->tableExists('glpi_plugin_backupinfo_mappings')) {
        $query = "CREATE TABLE `glpi_plugin_backupinfo_mappings` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `vm_name` varchar(255) DEFAULT NULL,
            `vm_uuid` varchar(255) DEFAULT NULL,
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `itemtype` varchar(100) DEFAULT NULL,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `is_manual` tinyint NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `vm_name` (`vm_name`),
            KEY `vm_uuid` (`vm_uuid`),
            KEY `items_id` (`items_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $DB->doQuery($query);
    }

    // Set rights for Super-Admin
    if (class_exists('GlpiPlugin\\Backupinfo\\Profile')) {
        \GlpiPlugin\Backupinfo\Profile::install();
    }

    $migration->executeMigration();

    return true;
}
