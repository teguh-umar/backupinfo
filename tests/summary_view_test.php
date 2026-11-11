<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/Rpo.php';
require BI_ROOT . '/src/Status.php';
require BI_ROOT . '/src/ObjectsView.php';
require BI_ROOT . '/src/Summary.php';
require BI_ROOT . '/src/SummaryView.php';

use GlpiPlugin\Backupinfo\Rpo;
use GlpiPlugin\Backupinfo\Summary;
use GlpiPlugin\Backupinfo\SummaryView;

$now = 1767225600;
$at  = static fn(int $ago): string => date('Y-m-d H:i:s', $now - $ago);
$row = static fn(array $o): array => $o + [
    'connections_id' => 1, 'connection_name' => 'vinchin-test', 'connection_active' => 1, 'kind' => 'vm', 'job_name' => 'JOB-DAILY',
    'restore_points' => 1, 'data_size' => '1 GB', 'abnormal_chain' => 0, 'source_deleted' => 0, 'items_id' => 0, 'schedule_hours' => null, 'last_backup' => null,
];
$rows = [
    $row(['id' => 1, 'name' => 'vm-app-01', 'last_backup' => $at(3600)]),
    $row(['id' => 2, 'name' => '<b>x</b>', 'job_name' => '=1+1', 'last_backup' => $at(20 * 86400)]),
];
$model = Summary::build($rows, [], new Rpo(26, 24), $now, []);
$html  = SummaryView::render($model, [1 => 'vinchin-test'], 0, '/plugins/backupinfo/front/objects.php', [], '2026-01-01 00:00:00');

check('wrapper id', str_contains($html, 'id="backupinfo-summary"'));
foreach (['Protected objects', 'Fresh backups', 'Total data', 'Restore points', 'Needs attention', 'Last backup age', 'By kind', 'Largest objects'] as $t) {
    check("has $t", str_contains($html, $t));
}
check('age segment link filters the table', str_contains($html, 'age%5B0%5D=older') && str_contains($html, 'source=0'));
check('one active source button, All', substr_count($html, 'btn btn-outline-secondary active') === 1
    && (bool) preg_match('/btn btn-outline-secondary active"[^>]*>All</', $html));
check('connection button links its id', str_contains($html, 'source=1'));
check('object name escaped', str_contains($html, '&lt;b&gt;x&lt;/b&gt;') && !str_contains($html, '<b>x</b>'));
check('last synced line', str_contains($html, 'Last synced') && str_contains($html, '0 of 2 matched to a Computer'));
check('clicking hint', str_contains($html, 'Click a segment to filter the table'));
check('reason badge shown', str_contains($html, 'Older than RPO'));

$empty = SummaryView::render(Summary::build([], [], new Rpo(26, 24), $now, []), [], 0, '/p', [], null);
check('empty: dash for fresh', str_contains($empty, '—'));
check('empty: no NAN or INF widths', !str_contains($empty, 'NAN') && !str_contains($empty, 'INF') && !str_contains($empty, 'nan'));
check('empty: never synced', str_contains($empty, 'never synced'));

$noted = $model;
$noted['notes'] = ['Size not reported by: veeam-test.'];
$h3 = SummaryView::render($noted, [1 => 'vinchin-test'], 1, '/p', ['older'], null);
check('notes in alert-info', (bool) preg_match('/alert alert-info[^>]*>.*Size not reported by: veeam-test\./s', $h3));
check('active source is the connection', (bool) preg_match('/btn btn-outline-secondary active"[^>]*>vinchin-test</', $h3));
check('age filter on dims the other segments', str_contains($h3, 'opacity-50'));

// Final review fix: segments toggle, several can be on, Needs attention is kept.
$rows3 = [
    $row(['id' => 1, 'name' => 'vm-app-01', 'last_backup' => $at(3600)]),
    $row(['id' => 2, 'name' => 'vm-db-01', 'last_backup' => $at(20 * 86400)]),
];
$h4 = SummaryView::render(Summary::build($rows3, [], new Rpo(26, 24), $now, []), [1 => 'vinchin-test'], 1, '/p', ['older'], null, true);
preg_match_all('/class="progress-bar [^"]*"[^>]*href="([^"]+)"/', $h4, $mm);
$links = [];
foreach ($mm[1] as $href) {
    parse_str((string) parse_url(html_entity_decode($href), PHP_URL_QUERY), $q);
    $links[] = $q;
}
$day   = array_values(array_filter($links, static fn(array $q): bool => in_array('day', $q['age'] ?? [], true)));
$older = array_values(array_filter($links, static fn(array $q): bool => !in_array('day', $q['age'] ?? [], true)));
check('segment adds itself to the ages already on', count($day) === 1 && $day[0]['age'] === ['older', 'day'], json_encode($links));
check('segment already on removes itself', count($older) === 1 && !isset($older[0]['age']));
check('segments keep source, attention and state all', $day[0]['source'] === '1' && $day[0]['attention'] === '1' && $day[0]['state'] === 'all');
check('segment title attribute well formed', !str_contains($h4, 'title=""'));
check('legend items are links too', substr_count($h4, 'class="text-reset') >= 5);
check('page passes the attention toggle to the cards', str_contains((string) file_get_contents(BI_ROOT . '/front/objects.php'), '$ages, $oldest, $attentionOn)'));

done();
