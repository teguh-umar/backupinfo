<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/AuditTable.php';
require BI_ROOT . '/src/ObjectsView.php';

use GlpiPlugin\Backupinfo\ObjectsView;

$read = static fn (string $rel): string => (string) @file_get_contents(BI_ROOT . '/' . $rel);

$runs = [
    ['connections_id' => 1, 'vm_name' => 'VM-A', 'start_time' => '2026-10-30 01:00:00', 'status' => 'success', 'item_status' => 'success', 'reason' => ''],
    ['connections_id' => 1, 'vm_name' => 'vm-a', 'start_time' => '2026-10-31 01:00:00', 'status' => 'failed', 'item_status' => 'failed', 'reason' => 'Disk full'],
    ['connections_id' => 2, 'vm_name' => 'VM-A', 'start_time' => '2026-10-31 05:00:00', 'status' => 'success', 'item_status' => '', 'reason' => ''],
    ['connections_id' => 1, 'vm_name' => '', 'start_time' => '2026-11-01 01:00:00', 'status' => 'failed', 'item_status' => 'failed', 'reason' => ''],
];
$last = ObjectsView::lastRuns($runs);
check('last run: newest per connection and VM, any case', $last['1|vm-a']['start_time'] === '2026-10-31 01:00:00' && $last['2|vm-a']['start_time'] === '2026-10-31 05:00:00' && count($last) === 2);
check('result: the VM\'s own status, else the run\'s', ObjectsView::result($last['1|vm-a']) === 'failed' && ObjectsView::result($last['2|vm-a']) === 'success');

$objects = [
    ['id' => 5, 'connections_id' => 1, 'name' => 'VM-A', 'ip' => '', 'kind' => 'vm', 'job_name' => 'JOB-1', 'last_backup' => '2026-10-30 01:10:00', 'restore_points' => 7, 'data_size' => '12 GB',
        'match_state' => 'matched', 'items_id' => 11, 'computer_name' => 'SRV-A', 'connection_name' => 'Main', 'connection_type' => 'vinchin'],
    ['id' => 6, 'connections_id' => 1, 'name' => 'VM-NEW', 'ip' => '', 'kind' => 'vm', 'job_name' => '', 'last_backup' => null, 'restore_points' => 0, 'data_size' => '',
        'match_state' => 'unmatched', 'items_id' => 0, 'computer_name' => null, 'connection_name' => 'Main', 'connection_type' => 'vinchin'],
];
$rows = ObjectsView::rows($objects, $last, ['vinchin' => 'Vinchin'], ['matched' => 'Matched', 'unmatched' => 'Not found']);
check('rows: one per object, in the page columns', count($rows) === 2 && count($rows[0]) === count(ObjectsView::COLUMNS));
check('rows: matched object shows its stored facts and newest run', $rows[0] === ['VM-A', '', 'vm', 'JOB-1', '2026-10-30 01:10:00', '7', '12 GB', '2026-10-31 01:00:00', 'failed', 'Vinchin · Main', 'Matched', 'SRV-A', '5'], json_encode($rows[0]));
check('rows: no stored run -> empty last run and result', $rows[1][7] === '' && $rows[1][8] === '' && $rows[1][11] === '' && $rows[1][12] === '6');
check('columns: result, computer and action indexes', ObjectsView::COLUMNS[ObjectsView::COL_RESULT] === 'Result' && ObjectsView::COLUMNS[ObjectsView::COL_COMPUTER] === 'Computer' && ObjectsView::COLUMNS[ObjectsView::COL_ACTION] === 'Action');
check('badge: green, red, yellow', ObjectsView::badge('success') === 'bg-success-lt' && ObjectsView::badge('failed') === 'bg-danger-lt' && ObjectsView::badge('abnormal') === 'bg-warning-lt');

$obj = $read('front/objects.php');
check('page: GLPI table with the objects rows', str_contains($obj, "render('components/datatable.html.twig', \$view)") && str_contains($obj, 'ObjectsView::rows('));
check('page: runs read from the stored runs, no source call', str_contains($obj, 'glpi_plugin_backupinfo_runs_items AS i') && !str_contains($obj, 'Registry::create('));
check('page: action column fixed (no filter, no sort)', str_contains($obj, "'fixed' => [ObjectsView::COL_ACTION]"));
check('page: failure reason only as escaped hover text', str_contains($obj, "title=\"' . htmlescape(\$m['reason'])"));
check('page: reloadTab reloads with the state kept', str_contains($obj, 'window.reloadTab = (add) =>') && str_contains($obj, "'?state=' . \$state"));
check('page: Columns menu kept in this browser', str_contains($obj, '"backupinfo.objects.cols"') && str_contains($obj, "TableUi::columnsButton('backupinfo-objects-cols')"));

done();
