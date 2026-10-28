<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_backupinfo_view', READ);

Html::header('Backup Info - Protected Assets', $_SERVER['PHP_SELF'], 'plugins', 'backupinfo', 'assets');

echo "<div class='container-fluid'>";
echo "<div class='card'>";
echo "<div class='card-header'><h3 class='card-title'><i class='ti ti-server'></i> Asset yang mempunyai backup di software backup</h3></div>";
echo "<div class='card-body'>";
echo "<div class='alert alert-info'>Daftar asset (VM/Server) yang terproteksi oleh Vinchin/Veritas. Mapping ke asset GLPI dapat dilakukan manual atau otomatis berdasarkan nama/UUID.</div>";
echo "<p class='text-muted'>Belum ada data. Lakukan sync melalui <a href='connection.php'>Connections</a>.</p>";
echo "</div></div>";
echo "</div>";

Html::footer();
