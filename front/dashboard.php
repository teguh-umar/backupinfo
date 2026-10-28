<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_backupinfo_view', READ);

Html::header('Backup Info - Dashboard', $_SERVER['PHP_SELF'], 'plugins', 'backupinfo', 'dashboard');

echo "<div class='container-fluid'>";
echo "<div class='row'>";
echo "<div class='col-12'>";
echo "<div class='card'>";
echo "<div class='card-header'><h3 class='card-title'><i class='ti ti-layout-dashboard'></i> Backup Info Dashboard</h3></div>";
echo "<div class='card-body'>";
echo "<div class='alert alert-info'>Dashboard menampilkan ringkasan asset terproteksi, status backup job, dan penggunaan storage dari Vinchin/Veritas. Gunakan halaman <strong>Connections</strong> untuk mengkonfigurasi API atau import Excel.</div>";
echo "</div></div>";
echo "</div></div>";

echo "<div class='row mt-3'>";
echo "<div class='col-md-4'><div class='card'><div class='card-body'><h4>Protected Assets</h4><p class='text-muted'>Belum ada data sync</p></div></div></div>";
echo "<div class='col-md-4'><div class='card'><div class='card-body'><h4>Backup Jobs (Last 30 Days)</h4><p class='text-muted'>Belum ada data sync</p></div></div></div>";
echo "<div class='col-md-4'><div class='card'><div class='card-body'><h4>Storage Usage</h4><p class='text-muted'>Belum ada data sync</p></div></div></div>";
echo "</div>";

echo "</div>";

Html::footer();
