<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/AuditTable.php';

use GlpiPlugin\Backupinfo\AuditTable;

$read = static fn (string $rel): string => (string) @file_get_contents(BI_ROOT . '/' . $rel);

$cols = ['Computer', 'Job', 'Start', 'Run status', 'Run ID'];
$rows = [];
for ($i = 1; $i <= 40; $i++) {
    $rows[] = ['PC-' . ($i % 3), 'JOB-A', sprintf('2026-10-%02d 01:00:00', $i % 28 + 1), $i % 10 === 0 ? 'failed' : 'success', 'run-' . $i];
}
$section = ['columns' => $cols, 'rows' => $rows];

check('keys: c0..cN', AuditTable::keys($cols) === ['c0' => 'Computer', 'c1' => 'Job', 'c2' => 'Start', 'c3' => 'Run status', 'c4' => 'Run ID']);

$ch = AuditTable::choices($cols, $rows);
check('choices: few distinct values -> choice list', isset($ch['c0'], $ch['c1'], $ch['c3']) && array_keys($ch['c3']) === ['failed', 'success']);
check('choices: one value per row or many values -> text filter', !isset($ch['c4']) && !isset($ch['c2']));
check('choices: a single row never gets a choice list', AuditTable::choices(['A'], [['x']]) === []);

$f = AuditTable::cleanFilters(['active' => '1', 'c3' => ['failed', '', ['x']], 'c4' => ' run-1 ', 'c9' => 'x', 'zz' => 'y'], AuditTable::keys($cols), $ch);
check('clean: known columns only, active kept, choices as lists', $f === ['active' => '1', 'c3' => ['failed'], 'c4' => 'run-1'], json_encode($f));
check('clean: not an array -> none', AuditTable::cleanFilters('c0=x', AuditTable::keys($cols), $ch) === []);
check('clean: text cut at 100', mb_strlen(AuditTable::cleanFilters(['c4' => str_repeat('a', 300)], AuditTable::keys($cols), $ch)['c4']) === 100);

check('apply: choice = exact value', count(AuditTable::apply($rows, ['c3' => ['failed']])) === 4);
check('apply: text = anywhere, any case', count(AuditTable::apply($rows, ['c4' => 'RUN-1'])) === 11);
check('apply: filters combine', count(AuditTable::apply($rows, ['c3' => ['failed'], 'c4' => 'run-10'])) === 1);
check('apply: only active -> every row', count(AuditTable::apply($rows, ['active' => '1'])) === 40);

$s = AuditTable::sort([['b10'], ['b9'], ['B1'], ['b9']], 0, false);
check('sort: natural, case-insensitive', array_column($s, 0) === ['B1', 'b9', 'b9', 'b10']);
check('sort: descending', array_column(AuditTable::sort([['1'], ['3'], ['2']], 0, true), 0) === ['3', '2', '1']);

// 0.5.2-rc1: a column can sort by a key, e.g. Size by bytes ("1.2 TB" above "883 GB"), not as text.
$sz  = [['883 GB'], [''], ['1.2 TB'], ['47.91GB']];
$key = static fn(string $v): int => ['1.2 TB' => 3, '883 GB' => 2, '47.91GB' => 1, '' => 0][$v];
check('sort: by a key', array_column(AuditTable::sort($sz, 0, true, $key), 0) === ['1.2 TB', '883 GB', '47.91GB', '']);
$vk = AuditTable::view('dt-k', ['columns' => ['Size'], 'rows' => $sz], ['sort' => 'c0', 'order' => 'DESC'], 15, ['sortKeys' => [0 => $key]]);
check('view: sortKeys option', array_column($vk['entries'], 'c0') === ['1.2 TB', '883 GB', '47.91GB', ''], json_encode($vk['entries']));
$fk = AuditTable::filtered(['columns' => ['Size'], 'rows' => $sz], ['sort' => 'c0', 'order' => 'ASC'], [], [0 => $key]);
check('filtered: sortKeys too (export = screen)', array_column($fk['rows'], 0) === ['', '47.91GB', '883 GB', '1.2 TB']);

// The screen filter finds rows beyond the first page: filtering runs on every row.
$v = AuditTable::view('dt-x', $section, ['filters' => ['c3' => ['failed']], 'sort' => 'c4', 'order' => 'desc'], 2);
check('view: counts total and filtered', $v['total_number'] === 40 && $v['filtered_number'] === 4);
check('view: page size from the user default', count($v['entries']) === 2 && $v['limit'] === 2);
check('view: sorted desc on the column', $v['entries'][0]['c4'] === 'run-40' && $v['order'] === 'DESC' && $v['sort'] === 'c4', json_encode($v['entries']));
check('view: choice columns use the "array" filter', $v['formatters']['c3'] === 'array' && isset($v['columns_values']['c3']));
check('view: GLPI tab-style reloads (pager, sort, filters)', $v['is_tab'] === true && $v['additional_params'] === '' && !array_key_exists('nofilter', $v));
$v = AuditTable::view('dt-x', $section, ['start' => '38', 'glpilist_limit' => '5', 'sort' => 'c99'], 15);
check('view: start and page size from the request; unknown sort ignored', $v['start'] === 38 && count($v['entries']) === 2 && $v['sort'] === null);
check('view: start past the end -> first page', AuditTable::view('dt-x', $section, ['start' => '999'], 15)['start'] === 0);

// HTML cells (links, badges, forms) and a fixed action column; filters and sort still read plain values.
$hcols = ['Name', 'Result', 'Action'];
$hrows = [['vm-a', 'failed', '7'], ['vm-b', 'success', '8'], ['<b>x</b>', 'failed', '9']];
$hv = AuditTable::view('dt-h', ['columns' => $hcols, 'rows' => $hrows], ['filters' => ['c1' => ['failed'], 'c2' => '8'], 'sort' => 'c2'], 15, [
    'fixed' => [2],
    'html'  => static fn (array $r): array => $r[0] === '<b>x</b>' ? [2 => '<form>' . $r[2] . '</form>'] : [1 => '<span class="badge">' . $r[1] . '</span>', 2 => '<form>' . $r[2] . '</form>'],
]);
check('html: filter reads the plain value, fixed column ignores its filter and sort', $hv['filtered_number'] === 2 && $hv['sort'] === null);
check('html: cells shown as given, column formatter raw_html', $hv['entries'][0]['c1'] === '<span class="badge">failed</span>' && $hv['formatters']['c1'] === 'raw_html');
check('html: a plain cell in an HTML column is escaped', $hv['entries'][1]['c1'] === 'failed' && $hv['entries'][1]['c0'] === '<b>x</b>');
check('html: choice filter kept through filter_formatter', ($hv['columns']['c1']['filter_formatter'] ?? '') === 'array' && $hv['formatters']['c1'] === 'raw_html');
check('html: fixed column has no filter and no sort', $hv['columns']['c2'] === ['label' => 'Action', 'no_filter' => true, 'nosort' => true] && !isset($hv['columns_values']['c2']));
$hv2 = AuditTable::view('dt-h', ['columns' => ['A'], 'rows' => [['<i>'], ['ok']]], [], 15, ['html' => static fn (array $r): array => $r[0] === 'ok' ? [0 => '<u>ok</u>'] : []]);
check('html: unsafe plain text next to HTML cells is escaped', $hv2['entries'][0]['c0'] === '&lt;i&gt;' && $hv2['entries'][1]['c0'] === '<u>ok</u>');

$ui = $read('src/TableUi.php');
check('ui: shared table script (step, query, columns, filters)', str_contains($ui, 'window.backupinfoTable') && str_contains($ui, 'return {step, query, applyCols, colMenu, bindFilters};'));
check('ui: localStorage wrapped in try', substr_count($ui, 'try { localStorage') === 1 && str_contains($ui, 'try { return new Set(JSON.parse(localStorage'));
check('ui: Columns checkboxes stay inside the menu (no .form-check negative margin)', str_contains($ui, '"dropdown-item d-flex align-items-center gap-2"') && !str_contains($ui, 'dropdown-item form-check'));
check('ui: filter row bound (show filters, input, change, Enter)', str_contains($ui, '".show_filters"') && str_contains($ui, '"input", ".filter_row') && str_contains($ui, '"change", ".filter_row') && str_contains($ui, 'ev.key === "Enter"'));

$page = $read('front/audit.php');
check('page: sections render GLPI\'s datatable', str_contains($page, "render('components/datatable.html.twig', \$view)") && str_contains($page, 'AuditTable::view('));
check('page: reloadTab defined for the section on screen', str_contains($page, 'window.reloadTab = (add) =>') && str_contains($page, '.tab-pane.active'));
check('page: filter row bound per section', str_contains($page, 'T.bindFilters($("#backupinfo-audit-body-" + key)') && str_contains($page, 'TableUi::script()'));
check('page: Columns menu kept per section in this browser', str_contains($page, '"backupinfo.audit.cols." + key') && str_contains($page, "TableUi::columnsButton('backupinfo-audit-cols-' . \$key)"));
check('page: Computer opens the asset when its name is unique in scope', str_contains($page, 'count($ids) !== 1') && str_contains($page, 'Computer::getFormURLWithID($ids[0])') && str_contains($page, "['html' => \$links]"));
check('page: restore test columns open the Computer\'s restore tests (READ)', str_contains($page, "array_search('Last test'") && str_contains($page, 'ObjectsView::restoreListQuery($ids[0])') && str_contains($page, 'RightDefinition::RESTORETEST, READ'));
check('page: no 500-row screen cap any more', !str_contains($page, '$maxRows'));
// Exports stay complete (user, 2026-11-06): they never read the screen filters.
$exportPart = substr($page, 0, (int) strpos($page, "isset(\$_GET['section'])"));
check('page: exports ignore filters, sort and columns', !str_contains($exportPart, "\$_GET['filters']") && !str_contains($exportPart, 'AuditTable::') && !str_contains($exportPart, "\$_GET['sort']") && str_contains($exportPart, 'Xlsx::sheet($tabs[$key], $head, $section[\'columns\'], $section[\'rows\'])'));

done();
