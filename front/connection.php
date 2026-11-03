<?php

/**
 * Connections are managed in Backup Info's section on the PGN Suite settings page (Setup);
 * this old list URL forwards there.
 */

use GlpiPlugin\Backupinfo\RightDefinition;
use GlpiPlugin\Backupinfo\SuiteConfig;

include('../../../inc/includes.php');
Session::checkRight(RightDefinition::CONFIG, READ);

Html::redirect(SuiteConfig::sectionUrl());
