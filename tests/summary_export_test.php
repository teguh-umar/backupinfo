<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/Rpo.php';
require BI_ROOT . '/src/Status.php';
require BI_ROOT . '/src/ObjectsView.php';
require BI_ROOT . '/src/Summary.php';
require BI_ROOT . '/src/Xlsx.php';
require BI_ROOT . '/src/SummaryExport.php';

use GlpiPlugin\Backupinfo\ObjectsView;
use GlpiPlugin\Backupinfo\Rpo;
use GlpiPlugin\Backupinfo\Summary;
use GlpiPlugin\Backupinfo\SummaryExport;

$now = 1767225600;
$at  = static fn(int $ago): string => date('Y-m-d H:i:s', $now - $ago);
$row = static fn(array $o): array => $o + [
    'connections_id' => 1, 'connection_name' => 'vinchin-test', 'connection_type' => 'vinchin', 'connection_active' => 1, 'kind' => 'vm', 'job_name' => 'JOB-DAILY',
    'restore_points' => 1, 'data_size' => '1 GB', 'abnormal_chain' => 0, 'source_deleted' => 0, 'items_id' => 0, 'schedule_hours' => null, 'last_backup' => null,
    'ip' => '', 'match_state' => 'unmatched', 'computer_name' => null,
];
$objects = [
    $row(['id' => 1, 'name' => 'vm-app-01', 'last_backup' => $at(3600)]),
    $row(['id' => 2, 'name' => 'vm-db-01', 'last_backup' => $at(20 * 86400)]),
    $row(['id' => 3, 'name' => 'vm-web-01']),
];
$model = Summary::build($objects, [], new Rpo(26, 24), $now, []);
$rows  = ObjectsView::rows($objects, [], [], ['vinchin' => 'Vinchin'], [], $now);
$meta  = ['Report' => 'Backup Info backup summary', 'Source' => 'All'];
$sheets = SummaryExport::sheets($model, ObjectsView::COLUMNS, $rows, $meta);

check('two sheets: Summary, Objects', count($sheets) === 2 && $sheets[0]['name'] === 'Summary' && $sheets[1]['name'] === 'Objects');
check('summary columns', $sheets[0]['columns'] === ['Section', 'Item', 'Value', 'Detail']);
check('summary KPI row', in_array(['KPI', 'Protected objects', '3', '1 kinds · 1 sources'], $sheets[0]['rows'], true), json_encode($sheets[0]['rows'][0] ?? null));
$attentionRows = array_values(array_filter($sheets[0]['rows'], static fn(array $r): bool => $r[0] === 'Needs attention'));
check('one row per Needs attention entry', count($attentionRows) === count($model['attention']) && count($attentionRows) === 2);
check('age rows', count(array_filter($sheets[0]['rows'], static fn(array $r): bool => $r[0] === 'Last backup age')) === 5);
check('summary meta says cards follow the source only', ($sheets[0]['meta']['Counts'] ?? '') === 'Cards: source choice only' && $sheets[0]['meta']['Source'] === 'All');
check('objects meta says table follows filters', ($sheets[1]['meta']['Rows'] ?? '') === 'Table: source and filters');
check('objects sheet without Action', !in_array('Action', $sheets[1]['columns'], true) && count($sheets[1]['columns']) === count(ObjectsView::COLUMNS) - 1);
check('objects rows one cell shorter', count($sheets[1]['rows']) === 3 && count($sheets[1]['rows'][0]) === count(ObjectsView::COLUMNS) - 1);

$noted = $model;
$noted['notes'] = ['Size not reported by: veeam-test.'];
$s2 = SummaryExport::sheets($noted, ObjectsView::COLUMNS, $rows, $meta);
check('notes as Note rows', in_array(['Note', '', 'Size not reported by: veeam-test.', ''], $s2[0]['rows'], true));

$empty = SummaryExport::sheets(Summary::build([], [], new Rpo(26, 24), $now, []), ObjectsView::COLUMNS, [], $meta);
check('empty: both sheets with columns and meta', count($empty) === 2 && $empty[1]['rows'] === [] && $empty[1]['columns'] !== [] && isset($empty[0]['meta']['Report']));

$obj = (string) file_get_contents(BI_ROOT . '/front/objects.php');
check('page writes the workbook', str_contains($obj, 'Xlsx::write(') && str_contains($obj, 'SummaryExport::sheets('));
check('export branch before the HTML', strpos($obj, 'SummaryExport::sheets(') < strpos($obj, 'Html::header('));

done();
