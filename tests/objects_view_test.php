<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/AuditTable.php';
require BI_ROOT . '/src/ObjectsView.php';
require BI_ROOT . '/src/Summary.php';

use GlpiPlugin\Backupinfo\ObjectsView;

$read = static fn (string $rel): string => (string) @file_get_contents(BI_ROOT . '/' . $rel);

$runs = [
    ['connections_id' => 1, 'vm_name' => 'VM-A', 'job_name' => 'JOB-1', 'start_time' => '2026-10-30 01:00:00', 'status' => 'success', 'item_status' => 'success', 'reason' => ''],
    ['connections_id' => 1, 'vm_name' => 'vm-a', 'job_name' => 'job-1', 'start_time' => '2026-10-31 01:00:00', 'status' => 'failed', 'item_status' => 'failed', 'reason' => 'Disk full'],
    ['connections_id' => 2, 'vm_name' => 'VM-A', 'job_name' => 'JOB-1', 'start_time' => '2026-10-31 05:00:00', 'status' => 'success', 'item_status' => '', 'reason' => ''],
    ['connections_id' => 1, 'vm_name' => '', 'job_name' => 'JOB-1', 'start_time' => '2026-11-01 01:00:00', 'status' => 'failed', 'item_status' => 'failed', 'reason' => ''],
    // Another job that backs up the same name (a tape copy of the source job): never VM-A's JOB-1 run.
    ['connections_id' => 1, 'vm_name' => 'VM-A', 'job_name' => 'COPY JOB-1', 'start_time' => '2026-11-02 01:00:00', 'status' => 'success', 'item_status' => 'success', 'reason' => ''],
];

$objects = [
    ['id' => 5, 'connections_id' => 1, 'name' => 'VM-A', 'ip' => '', 'kind' => 'vm', 'job_name' => 'JOB-1', 'job_type' => 'Backup', 'storage' => 'NFS-ONE (NFS)', 'last_backup' => '2026-10-30 01:10:00', 'restore_points' => 7, 'data_size' => '12 GB',
        'match_state' => 'matched', 'items_id' => 11, 'computer_name' => 'SRV-A', 'connection_name' => 'Main', 'connection_type' => 'vinchin'],
    ['id' => 6, 'connections_id' => 1, 'name' => 'VM-NEW', 'ip' => '', 'kind' => 'vm', 'job_name' => '', 'last_backup' => null, 'restore_points' => 0, 'data_size' => '',
        'match_state' => 'unmatched', 'items_id' => 0, 'computer_name' => null, 'connection_name' => 'Main', 'connection_type' => 'vinchin'],
];
$last = ObjectsView::lastRuns($runs, $objects);
check('last run: per object, newest run of its own job, any case', ($last[5]['start_time'] ?? '') === '2026-10-31 01:00:00' && !isset($last[6]));
check('result: the VM own status, else the run status', ObjectsView::result($last[5]) === 'failed' && ObjectsView::result($runs[2]) === 'success');

// 2026-11-12 VM: two rows share a name (source job and its WEEKLY COPY to tape); each shows its own job's run.
$shared = [
    ['id' => 21, 'connections_id' => 1, 'name' => 'VM-A', 'job_name' => 'COPY JOB-1'],
    ['id' => 22, 'connections_id' => 1, 'name' => 'VM-A', 'job_name' => 'OTHER, JOB-1'],
    ['id' => 23, 'connections_id' => 1, 'name' => 'VM-A', 'job_name' => 'STOPPED-COPY'],
    ['id' => 24, 'connections_id' => 1, 'name' => 'VM-A', 'job_name' => ''],
    ['id' => 25, 'connections_id' => 2, 'name' => 'vm-a', 'job_name' => 'JOB-1'],
];
$ls = ObjectsView::lastRuns($runs, $shared);
check('last run: copy row gets the copy run', ($ls[21]['start_time'] ?? '') === '2026-11-02 01:00:00');
check('last run: several jobs -> newest of those jobs only', ($ls[22]['start_time'] ?? '') === '2026-10-31 01:00:00');
check('last run: no run of its own job -> none, never a run of another job', !isset($ls[23]));
check('last run: no job listed -> newest run of that name', ($ls[24]['start_time'] ?? '') === '2026-11-02 01:00:00');
check('last run: per connection', ($ls[25]['start_time'] ?? '') === '2026-10-31 05:00:00');

$rows = ObjectsView::rows($objects, $last, [11 => ['date' => '2026-10-20', 'result' => 'pass']], ['vinchin' => 'Vinchin'], ['matched' => 'Matched', 'unmatched' => 'Not found'], strtotime('2026-11-11 00:00:00'));
check('rows: one per object, in the page columns', count($rows) === 2 && count($rows[0]) === count(ObjectsView::COLUMNS));
check('rows: matched object shows its stored facts and newest run', $rows[0] === ['VM-A', '', 'vm', 'JOB-1', 'Backup', 'NFS-ONE (NFS)', '2026-10-30 01:10:00', '> 7 days', '7', '12 GB', '2026-10-31 01:00:00', 'failed', '2026-10-20 pass', 'Vinchin · Main', 'Matched', 'SRV-A', '5'], json_encode($rows[0]));
check('rows: no stored run, no Computer -> empty last run, result, restore test and Computer', $rows[1][4] === '' && $rows[1][5] === '' && $rows[1][7] === 'No timestamp' && $rows[1][10] === '' && $rows[1][11] === '' && $rows[1][12] === '' && $rows[1][15] === '' && $rows[1][16] === '6');
check('columns: name, result, restore test, computer and action indexes', ObjectsView::COLUMNS[ObjectsView::COL_NAME] === 'Name' && ObjectsView::COLUMNS[ObjectsView::COL_RESTORE] === 'Restore test' && ObjectsView::COLUMNS[ObjectsView::COL_RESULT] === 'Result' && ObjectsView::COLUMNS[ObjectsView::COL_COMPUTER] === 'Computer' && ObjectsView::COLUMNS[ObjectsView::COL_ACTION] === 'Action');
check('badge: green, red, yellow', ObjectsView::badge('success') === 'bg-success-lt' && ObjectsView::badge('failed') === 'bg-danger-lt' && ObjectsView::badge('abnormal') === 'bg-warning-lt' && ObjectsView::badge('pass') === 'bg-success-lt' && ObjectsView::badge('partial') === 'bg-warning-lt');
parse_str(ObjectsView::restoreListQuery(11), $q);
check('restore list narrowed to the Computer (search option 3, equals)', ($q['criteria'][0] ?? null) === ['field' => '3', 'searchtype' => 'equals', 'value' => '11'] && ($q['reset'] ?? '') === 'reset');
$so = $read('src/RestoreTest.php');
check('restore tests: search option 3 is the Computer', str_contains($so, "['id' => 3, 'table' => 'glpi_computers', 'field' => 'name', 'linkfield' => 'computers_id'"));

// Backup summary (2026-11-11): Age column and the table filters run in PHP.
check('columns: Age after Last backup', ObjectsView::COLUMNS[6] === 'Last backup' && ObjectsView::COLUMNS[7] === 'Age');
check('columns: Type and Storage after Job (0.5.1-rc2)', ObjectsView::COLUMNS[3] === 'Job' && ObjectsView::COLUMNS[4] === 'Type' && ObjectsView::COLUMNS[5] === 'Storage');
$all = [
    ['id' => 1, 'match_state' => 'review', 'last_backup' => '2026-11-10 23:00:00'],
    ['id' => 2, 'match_state' => 'unmatched', 'last_backup' => null],
    ['id' => 3, 'match_state' => 'matched', 'last_backup' => '2026-10-01 00:00:00'],
];
$t = strtotime('2026-11-11 00:00:00');
check('select: states', array_column(ObjectsView::select($all, ['review', 'unmatched'], [], false, [], $t), 'id') === [1, 2]);
check('select: ages', array_column(ObjectsView::select($all, null, ['none'], false, [], $t), 'id') === [2]);
check('select: two ages', array_column(ObjectsView::select($all, null, ['day', 'older'], false, [], $t), 'id') === [1, 3]);
check('select: attention only', array_column(ObjectsView::select($all, null, [], true, [2 => true], $t), 'id') === [2]);
check('select: nothing set keeps every row', ObjectsView::select($all, null, [], false, [], $t) === $all);

$obj = $read('front/objects.php');
check('page: GLPI table with the objects rows', str_contains($obj, "render('components/datatable.html.twig', \$view)") && str_contains($obj, 'ObjectsView::rows('));
check('page: runs read from the stored runs, no source call', str_contains($obj, 'glpi_plugin_backupinfo_runs_items AS i') && !str_contains($obj, 'Registry::create('));
check('page: action column fixed (no filter, no sort)', str_contains($obj, "'fixed' => [ObjectsView::COL_ACTION]"));
check('page: failure reason only as escaped hover text', str_contains($obj, "title=\"' . htmlescape(\$m['reason'])"));
check('page: reloadTab reloads with the state kept', str_contains($obj, 'window.reloadTab = (add) =>') && str_contains($obj, '$js($link())') && str_contains($obj, "['state' => \$state]"));
check('page: VM name and Computer open the GLPI asset', str_contains($obj, '$out[ObjectsView::COL_NAME]     = \'<a href="\' . $asset') && str_contains($obj, 'Computer::getFormURLWithID($m[\'cid\'])'));
check('page: restore test opens the Computer\'s tests; record one only with CREATE', str_contains($obj, 'ObjectsView::restoreListQuery($m[\'cid\'])') && str_contains($obj, 'RightDefinition::RESTORETEST, CREATE') && str_contains($obj, "'?computers_id=' . \$m['cid']"));
check('page: Columns menu kept in this browser', str_contains($obj, '"backupinfo.objects.cols"') && str_contains($obj, "TableUi::columnsButton('backupinfo-objects-cols')"));

// Backup summary wiring (2026-11-11).
check('page: summary model and cards', str_contains($obj, 'Summary::build(') && str_contains($obj, 'SummaryView::render('));
check('page: table rows filtered in PHP, not in SQL', str_contains($obj, 'ObjectsView::select(') && !str_contains($obj, "\$where[\$table . '.match_state']"));
check('page: button renamed Not matched, key kept', str_contains($obj, "'attention' => [__('Not matched', 'backupinfo')") && !str_contains($obj, 'Needs review or not found'));
check('page: active flag of the connection loaded', str_contains($obj, 'connection_active'));
check('page: Needs attention toggle', str_contains($obj, "__s('Needs attention', 'backupinfo')") && str_contains($obj, "\$link(['attention' => '1'])"));
check('page: export link', str_contains($obj, "'export' => 'xlsx'"));
check('page: source only for an active connection', str_contains($obj, 'isset($activeSources[$source])'));
check('page: reloads keep source, age and attention', str_contains($obj, '$keep') && str_contains($obj, "'source'") && str_contains($obj, "'age'"));
$menu = $read('src/Menu.php');
check('menu: Backup summary entry before Backup objects', str_contains($menu, "\$options['summary']") && str_contains($menu, "__('Backup summary', 'backupinfo')")
    && str_contains($menu, '#backupinfo-summary') && strpos($menu, "\$options['summary']") < strpos($menu, "\$options['objects']"));

done();
