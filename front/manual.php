<?php

use GlpiPlugin\Backupinfo\Manual;
use GlpiPlugin\Backupinfo\RightDefinition;

include('../../../inc/includes.php');

// 0.5.0-rc1: the user manual (suite rule 2026-11-09). Anyone who may see the plugin may read it.
Session::checkRight(RightDefinition::VIEW, READ);

Html::header(__('Manual', 'backupinfo'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'backupinfo', 'manual');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('backupinfo', 'manual');

echo '<div class="card"><div class="card-body">';
echo Manual::body(Manual::read(), PLUGIN_BACKUPINFO_VERSION);
echo '</div></div>';

Html::footer();
