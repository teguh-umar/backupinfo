<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/Rpo.php';
require BI_ROOT . '/src/Status.php';
require BI_ROOT . '/src/ObjectsView.php';
require BI_ROOT . '/src/Summary.php';

use GlpiPlugin\Backupinfo\Rpo;
use GlpiPlugin\Backupinfo\Status;
use GlpiPlugin\Backupinfo\Summary;

// --- sizes (1024 steps; Vinchin writes "683.47GB")
check('bytes: Vinchin style', Summary::bytes('683.47GB') === (int) round(683.47 * 1024 ** 3));
check('bytes: with a space', Summary::bytes('317.5 GB') === (int) round(317.5 * 1024 ** 3));
check('bytes: KB', Summary::bytes('2.9 KB') === (int) round(2.9 * 1024));
check('bytes: 0B', Summary::bytes('0B') === 0);
check('bytes: TB lower case', Summary::bytes('1 tb') === 1024 ** 4);
foreach (['', 'n/a', '1,5 GB', '12', 'GB', '-1 GB'] as $bad) {
    check("bytes: unknown text '$bad' counts 0", Summary::bytes($bad) === 0);
}
check('human: 0 B', Summary::human(0) === '0 B');
check('human: 22.0 GB', Summary::human(1024 ** 3 * 22) === '22.0 GB');
check('human: 1.1 PB', Summary::human((int) (1.1 * 1024 ** 5)) === '1.1 PB');
check('human: 512 B', Summary::human(512) === '512 B');

// --- age buckets
$now = 1767225600;
$at  = static fn(int $ago): string => date('Y-m-d H:i:s', $now - $ago);
check('age: 1 h is day', Summary::ageBucket($at(3600), $now) === 'day');
check('age: exactly 24 h is day', Summary::ageBucket($at(86400), $now) === 'day');
check('age: 24 h + 1 s is days3', Summary::ageBucket($at(86401), $now) === 'days3');
check('age: 3 days + 1 s is days7', Summary::ageBucket($at(3 * 86400 + 1), $now) === 'days7');
check('age: 7 days + 1 s is older', Summary::ageBucket($at(7 * 86400 + 1), $now) === 'older');
check('age: null is none', Summary::ageBucket(null, $now) === 'none');
check('age: zero date is none', Summary::ageBucket('0000-00-00 00:00:00', $now) === 'none');
check('age: garbage is none', Summary::ageBucket('garbage', $now) === 'none');
check('age: future is day', Summary::ageBucket($at(-3600), $now) === 'day');
check('age bucket keys', array_keys(Summary::AGE_BUCKETS) === ['day', 'days3', 'days7', 'older', 'none']);
check('age bucket labels', Summary::AGE_BUCKETS['day'] === '≤ 24 hours' && Summary::AGE_BUCKETS['days3'] === '1–3 days'
    && Summary::AGE_BUCKETS['days7'] === '3–7 days' && Summary::AGE_BUCKETS['older'] === '> 7 days' && Summary::AGE_BUCKETS['none'] === 'No timestamp');

// --- attention reasons
$rpo = new Rpo(26, 24);
$obj = static fn(array $o): array => $o + ['abnormal_chain' => 0, 'source_deleted' => 0, 'schedule_hours' => null, 'last_backup' => null];
check('reasons: chain, deleted, expired', Summary::reasons($obj(['abnormal_chain' => 1, 'source_deleted' => 1, 'last_backup' => $at(400 * 86400)]), null, $rpo, $now) === ['chain', 'deleted', 'expired']);
check('reasons: failed run then expired', Summary::reasons($obj(['last_backup' => $at(10 * 86400)]), ['status' => 'Failed'], $rpo, $now) === ['failed', 'expired']);
check('reasons: item status fail counts', Summary::reasons($obj(['last_backup' => $at(3600)]), ['status' => 'success', 'item_status' => 'fail'], $rpo, $now) === ['failed']);
check('reasons: fresh and success is none', Summary::reasons($obj(['last_backup' => $at(3600)]), ['status' => 'success'], $rpo, $now) === []);
check('reasons: no backup is never', Summary::reasons($obj([]), null, $rpo, $now) === ['never']);
check('reasons: schedule RPO not passed', Summary::reasons($obj(['schedule_hours' => 336, 'last_backup' => $at(300 * 3600)]), null, $rpo, $now) === []);
check('reasons: schedule RPO passed', Summary::reasons($obj(['schedule_hours' => 336, 'last_backup' => $at(400 * 3600)]), null, $rpo, $now) === ['expired']);
check('reason labels', array_keys(Summary::REASONS) === ['chain', 'deleted', 'failed', 'never', 'expired']);

// --- build
$row = static fn(array $o): array => $o + [
    'connections_id' => 1, 'connection_name' => 'vinchin-test', 'connection_active' => 1, 'kind' => 'vm', 'job_name' => 'JOB-DAILY',
    'restore_points' => 0, 'data_size' => '', 'abnormal_chain' => 0, 'source_deleted' => 0, 'items_id' => 0, 'schedule_hours' => null, 'last_backup' => null,
];
$rows = [
    $row(['id' => 1, 'name' => 'vm-app-01', 'data_size' => '1 GB', 'last_backup' => $at(3600), 'restore_points' => 7, 'items_id' => 11]),
    $row(['id' => 2, 'name' => 'vm-db-01', 'kind' => 'database', 'last_backup' => $at(10 * 86400), 'restore_points' => 3]),
    $row(['id' => 3, 'name' => 'vm-web-01', 'data_size' => '2 GB', 'last_backup' => $at(9 * 86400), 'abnormal_chain' => 1, 'restore_points' => 2, 'items_id' => 12]),
    $row(['id' => 4, 'name' => 'vm-old-01', 'connections_id' => 2, 'connection_name' => 'gone', 'connection_active' => 0, 'data_size' => '9 TB']),
];
$m = Summary::build($rows, [], $rpo, $now, []);
check('build: inactive connection left out', $m['count'] === 3);
check('build: kinds and sources', $m['kinds'] === 2 && $m['sources'] === 1);
check('build: bytes are 3 GiB', $m['bytes'] === 3 * 1024 ** 3);
check('build: fresh 1 of 3', $m['fresh'] === 1 && abs($m['freshPct'] - 100 / 3) < 0.01);
check('build: restore points and average', $m['points'] === 12 && abs($m['pointsAvg'] - 4.0) < 0.01);
check('build: matched counts items_id', $m['matched'] === 2);
check('build: age buckets', $m['age'] === ['day' => 1, 'days3' => 0, 'days7' => 0, 'older' => 2, 'none' => 0]);
check('build: two need attention, chain first', count($m['attention']) === 2 && $m['attention'][0]['name'] === 'vm-web-01' && $m['attention'][0]['reasons'] === ['chain', 'expired']);
check('build: attention row fields', $m['attention'][1]['id'] === 2 && $m['attention'][1]['job'] === 'JOB-DAILY' && $m['attention'][1]['ageDays'] === 10);
check('build: attention ids', $m['attentionIds'] === [3 => true, 2 => true]);
check('build: reason counts', $m['reasonCounts'] === ['chain' => 1, 'expired' => 2]);
check('build: largest, biggest first, sized only', array_column($m['largest'], 'name') === ['vm-web-01', 'vm-app-01']);
check('build: by kind, count desc', array_column($m['byKind'], 'kind') === ['vm', 'database'] && $m['byKind'][0]['label'] === 'VM'
    && $m['byKind'][0]['count'] === 2 && $m['byKind'][0]['bytes'] === 3 * 1024 ** 3 && abs($m['byKind'][0]['freshPct'] - 50.0) < 0.01);
check('build: no notes without gaps', $m['notes'] === []);

$last = ['1|vm-app-01' => ['status' => 'failed', 'start_time' => $at(3000)]];
$mf = Summary::build($rows, $last, $rpo, $now, []);
check('build: failed newest run needs attention', isset($mf['attentionIds'][1]) && $mf['reasonCounts']['failed'] === 1);

$empty = Summary::build([], [], $rpo, $now, []);
check('build: empty', $empty['count'] === 0 && $empty['freshPct'] === null && $empty['pointsAvg'] === null && $empty['attention'] === []
    && $empty['age'] === ['day' => 0, 'days3' => 0, 'days7' => 0, 'older' => 0, 'none' => 0] && $empty['largest'] === [] && $empty['byKind'] === []);

$veeam = [$row(['id' => 9, 'name' => 'vm-x-01', 'connections_id' => 2, 'connection_name' => 'veeam-test', 'last_backup' => $at(60)])];
$mv = Summary::build($veeam, [], $rpo, $now, [2 => ['size' => 'x', 'chain' => 'y']]);
check('build: size note', in_array('Size not reported by: veeam-test.', $mv['notes'], true));
check('build: chain note', in_array('Broken chain and source deleted not checked for: veeam-test.', $mv['notes'], true));

check('kind label: file', Status::kindLabel('file') === 'File');
check('kind label: empty is Other', Status::kindLabel('') === 'Other');
check('kind label: unknown is Other', Status::kindLabel('weird') === 'Other');

$veeamSrc = (string) file_get_contents(BI_ROOT . '/src/Veeam/VeeamSource.php');
check('Veeam declares size and chain gaps', str_contains($veeamSrc, "'size' => 'Size is not reported by this source.'")
    && str_contains($veeamSrc, "'chain' => 'Broken backup chains and deleted sources are not reported by this source.'"));

done();
