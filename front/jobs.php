<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_backupinfo_view', READ);

Html::header('Backup Info - Backup Jobs', $_SERVER['PHP_SELF'], 'plugins', 'backupinfo', 'jobs');

echo "<div class='container-fluid'>";
echo "<div class='card'>";
echo "<div class='card-header'><h3 class='card-title'><i class='ti ti-list-check'></i> Hasil Backup (Job History)</h3></div>";
echo "<div class='card-body'>";
echo "<div class='alert alert-info'>Riwayat job backup per periode (status Success/Failed/Warning), durasi, ukuran data, dan error message.</div>";
echo "<p class='text-muted'>Belum ada data. Lakukan sync melalui <a href='connection.php'>Connections</a>.</p>";
echo "</div></div>";
echo "</div>";

Html::footer();
