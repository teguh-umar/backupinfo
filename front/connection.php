<?php

use GlpiPlugin\Backupinfo\Connection;
use GlpiPlugin\Backupinfo\RightDefinition;

include('../../../inc/includes.php');
Session::checkRight(RightDefinition::CONFIG, READ);

Html::header(Connection::getTypeName(2), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'backupinfo', 'connection');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('backupinfo', 'connection');
Search::show(Connection::class);
Html::footer();
