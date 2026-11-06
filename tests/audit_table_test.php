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

$page = $read('front/audit.php');
check('page: sections render GLPI\'s datatable', str_contains($page, "render('components/datatable.html.twig', \$view)") && str_contains($page, 'AuditTable::view('));
check('page: reloadTab defined for the section on screen', str_contains($page, 'window.reloadTab = (add) =>') && str_contains($page, '.tab-pane.active'));
check('page: filter row bound per section (show filters, input, change, Enter)', str_contains($page, '".show_filters"') && str_contains($page, '"input", ".filter_row') && str_contains($page, '"change", ".filter_row') && str_contains($page, 'ev.key === "Enter"'));
check('page: Columns menu kept per section in this browser', str_contains($page, '"backupinfo.audit.cols." + key') && str_contains($page, 'id="backupinfo-audit-cols-'));
check('page: Columns checkboxes stay inside the menu (no .form-check negative margin)', str_contains($page, '"dropdown-item d-flex align-items-center gap-2"') && !str_contains($page, 'dropdown-item form-check'));
check('page: localStorage wrapped in try', substr_count($page, 'try { localStorage') === 1 && str_contains($page, 'try { return new Set(JSON.parse(localStorage'));
check('page: no 500-row screen cap any more', !str_contains($page, '$maxRows'));
// Exports stay complete (user, 2026-11-06): they never read the screen filters.
$exportPart = substr($page, 0, (int) strpos($page, "isset(\$_GET['section'])"));
check('page: exports ignore filters, sort and columns', !str_contains($exportPart, "\$_GET['filters']") && !str_contains($exportPart, 'AuditTable::') && !str_contains($exportPart, "\$_GET['sort']") && str_contains($exportPart, 'Xlsx::sheet($tabs[$key], $head, $section[\'columns\'], $section[\'rows\'])'));

done();
