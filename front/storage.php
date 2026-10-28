<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_backupinfo_view', READ);

Html::header('Backup Info - Backup Storage', $_SERVER['PHP_SELF'], 'plugins', 'backupinfo', 'storage');

echo "<div class='container-fluid'>";
echo "<div class='card'>";
echo "<div class='card-header'><h3 class='card-title'><i class='ti ti-database'></i> Informasi Storage Backup</h3></div>";
echo "<div class='card-body'>";
echo "<div class='alert alert-info'>Penggunaan storage backup (Total/Used/Free) per site DCS/DCI untuk Vinchin & Veritas.</div>";
echo "<p class='text-muted'>Belum ada data. Lakukan sync melalui <a href='connection.php'>Connections</a>.</p>";
echo "</div></div>";
echo "</div>";

Html::footer();
