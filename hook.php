<?php

require_once __DIR__ . '/install/install.php';
require_once __DIR__ . '/install/uninstall.php';

use GlpiPlugin\Backupinfo\RightDefinition;
use GlpiPlugin\Backupinfo\SearchOptions;

/**
 * Backup search options on Computer (IDs 8370-8379). Core calls this by name
 * (Plugin::getAddSearchOptionsNew); there is no $PLUGIN_HOOKS entry for it.
 */
function plugin_backupinfo_getAddSearchOptionsNew($itemtype)
{
    if (!Session::haveRight(RightDefinition::VIEW, READ)) {
        return [];
    }
    return SearchOptions::forItemtype((string) $itemtype);
}
