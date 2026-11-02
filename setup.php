<?php

/**
 * Backup Info - Vinchin/Veritas backup overview for GLPI 11 (PGN GLPI Suite)
 *
 * @author  ICT
 * @license GPL-3.0-or-later
 */

define('PLUGIN_BACKUPINFO_VERSION', '0.2.0-rc6');
define('PLUGIN_BACKUPINFO_MIN_GLPI', '11.0.0');
define('PLUGIN_BACKUPINFO_MAX_GLPI', '11.99.99');


function plugin_version_backupinfo()
{
    return [
        'name'         => 'Backup Info',
        'version'      => PLUGIN_BACKUPINFO_VERSION,
        'author'       => 'ICT',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_BACKUPINFO_MIN_GLPI,
                'max' => PLUGIN_BACKUPINFO_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_backupinfo_check_prerequisites()
{
    return true;
}

function plugin_backupinfo_check_config()
{
    return true;
}

function plugin_init_backupinfo()
{
    global $PLUGIN_HOOKS;

    // GLPI 11 CSRF compliant
    $PLUGIN_HOOKS['csrf_compliant']['backupinfo'] = true;

    // Secured fields for API credentials
    $PLUGIN_HOOKS['secured_fields']['backupinfo'] = [
        'glpi_plugin_backupinfo_connections.api_key',
        'glpi_plugin_backupinfo_connections.password',
    ];

    \Plugin::registerClass(\GlpiPlugin\Backupinfo\Profile::class, ['addtabon' => ['Profile']]);

    // Shared suite sidebar menu (ICT Tools)
    if (!class_exists(\GlpiPlugin\Pgnsuite\Menu::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/Menu.php';
    }
    \GlpiPlugin\Pgnsuite\Menu::register('backupinfo');
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\Menu::HOOK]['backupinfo'] = \GlpiPlugin\Backupinfo\Menu::class . '::entry';

    // Shared config tab in Setup > General
    if (!class_exists(\GlpiPlugin\Pgnsuite\ConfigTab::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/ConfigTab.php';
    }
    \GlpiPlugin\Pgnsuite\ConfigTab::register();
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\ConfigTab::HOOK]['backupinfo'] = \GlpiPlugin\Backupinfo\SuiteConfig::class . '::section';

    // "Backup" section of the shared PGN Suite asset tab, on Computer only (hub §5).
    // Search options on Computer: plugin_backupinfo_getAddSearchOptionsNew() in hook.php.
    if (!class_exists(\GlpiPlugin\Pgnsuite\AssetTab::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/AssetTab.php';
    }
    \GlpiPlugin\Pgnsuite\AssetTab::register(['Computer']);
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\AssetTab::HOOK]['backupinfo'] = \GlpiPlugin\Backupinfo\AssetTab::class . '::sections';
}
