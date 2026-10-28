<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_backupinfo_config', READ);

Html::header('Backup Info - Connections', $_SERVER['PHP_SELF'], 'plugins', 'backupinfo', 'connection');

Search::show('GlpiPlugin\\Backupinfo\\Connection');

Html::footer();
