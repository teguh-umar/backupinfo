<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_backupinfo_report', READ);

Html::header('Backup Info - Report', $_SERVER['PHP_SELF'], 'plugins', 'backupinfo', 'report');

echo "<div class='container-fluid'>";
echo "<div class='card'>";
echo "<div class='card-header'><h3 class='card-title'><i class='ti ti-file-report'></i> Report Backup Info</h3></div>";
echo "<div class='card-body'>";
echo "<div class='alert alert-info'>Report bisa di-generate dari data API/Excel. Bisa juga mengacu pada file PDF laporan bulanan yang ada di folder plugin.</div>";
echo "<p><strong>File laporan tersedia:</strong></p>";
echo "<ul>";
echo "<li>08_Report Backup Management (Agustus 2026)_signed.pdf</li>";
echo "<li>09_Report Backup Management (September 2026)_1003.pdf</li>";
echo "<li>Executive_Summary_Backup_Management_Agustus2026.pdf</li>";
echo "<li>Executive_Summary_Backup_Management_September2026_signed.pdf</li>";
echo "</ul>";
echo "<p class='text-muted'>Fitur export CSV/Excel akan ditambahkan sesuai kebutuhan.</p>";
echo "</div></div>";
echo "</div>";

Html::footer();
