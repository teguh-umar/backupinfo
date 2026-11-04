<?php

/**
 * Restore tests: the standard search list (entity-restricted by core), with documents per record.
 */

use GlpiPlugin\Backupinfo\RestoreTest;
use GlpiPlugin\Backupinfo\RightDefinition;

include('../../../inc/includes.php');
Session::checkRight(RightDefinition::RESTORETEST, READ);

Html::header(RestoreTest::getTypeName(Session::getPluralNumber()), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'backupinfo', 'restoretests');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('backupinfo', 'restoretests');
Search::show(RestoreTest::class);
Html::footer();
