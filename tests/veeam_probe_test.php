<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Source/BackupObject.php';
require BI_ROOT . '/src/Source/Event.php';
require BI_ROOT . '/src/Source/Target.php';
require BI_ROOT . '/src/Source/Run.php';
require BI_ROOT . '/src/Source/Time.php';
require BI_ROOT . '/src/Source/BackupSource.php';
require BI_ROOT . '/src/Source/ProbeableSource.php';
require BI_ROOT . '/src/Source/AuditProbeableSource.php';
require BI_ROOT . '/src/Source/AuditSource.php';
require BI_ROOT . '/src/Source/PartialFetch.php';
require BI_ROOT . '/src/Source/RunHistorySource.php';
require BI_ROOT . '/src/Matching/Matcher.php';
require BI_ROOT . '/src/Matching/AssetLookup.php';
require BI_ROOT . '/src/Probe.php';
require BI_ROOT . '/src/Http/Transport.php';
require BI_ROOT . '/src/Http/CurlTransport.php';
require BI_ROOT . '/src/Veeam/Client.php';
require BI_ROOT . '/src/Veeam/ApiError.php';
require BI_ROOT . '/src/Veeam/Shape.php';
require BI_ROOT . '/src/Veeam/ObjectMapper.php';
require BI_ROOT . '/src/Veeam/TargetMapper.php';
require BI_ROOT . '/src/Veeam/EventMapper.php';
require BI_ROOT . '/src/Veeam/VeeamSource.php';

require __DIR__ . '/veeam_fakes.php';

use GlpiPlugin\Backupinfo\Source\BackupObject;
use GlpiPlugin\Backupinfo\Source\Event;
use GlpiPlugin\Backupinfo\Source\Target;
use GlpiPlugin\Backupinfo\Source\Time;
use GlpiPlugin\Backupinfo\Probe;
use GlpiPlugin\Backupinfo\Veeam\Client;
use GlpiPlugin\Backupinfo\Veeam\Shape;
use GlpiPlugin\Backupinfo\Veeam\VeeamSource;

date_default_timezone_set('Asia/Jakarta');

// ------------------------------------------------------------------
// Shape::missing
// ------------------------------------------------------------------
check('Shape::missing: dot paths', Shape::missing(
    ['result' => ['result' => 'Success'], 'id' => '1'],
    ['result.result', 'id', 'missing.path']
) === ['missing.path']);
check('Shape::missing: all present', Shape::missing(
    ['a' => ['b' => 'c']], ['a.b']
) === []);
check('Shape::missing: empty required', Shape::missing(['a' => 1], []) === []);

// ------------------------------------------------------------------
// probe: every endpoint OK
// ------------------------------------------------------------------
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/backupObjects', vpage([['id' => 'o1', 'name' => 'vm-app-01', 'type' => 'VM', 'restorePointsCount' => 3]], 1, 0));
$t->queue('GET /api/v1/restorePoints', vpage([['id' => 'rp1', 'name' => 'vm-app-01', 'creationTime' => '2026-11-01T00:00:00Z', 'backupId' => 'b1']], 1, 0));
$t->queue('GET /api/v1/backups', vpage([['backupId' => 'b1', 'name' => 'JOB-DAILY']], 1, 0));
$t->queue('GET /api/v1/sessions', vpage([['id' => 's1', 'name' => 'JOB-DAILY', 'jobId' => 'j1', 'creationTime' => '2026-11-01T01:00:00Z', 'endTime' => '2026-11-01T02:00:00Z', 'state' => 'Stopped', 'result' => ['result' => 'Success', 'isCanceled' => false]]], 1, 0));
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$p = $src->probe(5);
check('probe: every endpoint OK', array_filter($p['raw'], fn($r) => isset($r['_check']) && $r['_check'] !== 'OK') === [], json_encode($p['raw']));
check('probe: no errors', $p['errors'] === []);
check('probe: objects mapped', count($p['objects']) === 1);
check('probe: no access-xyz in serialized output', !str_contains(serialize($p), 'access-xyz'));

// ------------------------------------------------------------------
// 403 on /authorization/events -> refused (role?)
// ------------------------------------------------------------------
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/backupObjects', vpage([], 0, 0));
$t->queue('GET /api/v1/restorePoints', vpage([], 0, 0));
$t->queue('GET /api/v1/backups', vpage([], 0, 0));
$t->queue('GET /api/v1/sessions', vpage([], 0, 0));   // first id lookup
$t->queue('GET /api/v1/sessions', vpage([], 0, 0));   // BackupCopyJob
$t->queue('GET /api/v1/authorization/events', vj([], 403));
$t->queue('GET /api/v1/malwareDetection/events', vpage([], 0, 0));
$t->queue('GET /api/v1/backupInfrastructure/repositories/states', vpage([], 0, 0));
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$pa = $src->probeAudit(5);
$actual = $pa['raw']['/authorization/events'][0]['_check'] ?? 'NOT SET';
$expected = "refused (role?): GET /api/v1/authorization/events: HTTP 403 [api 1.3-rev2] - the account's role cannot read this (Backup Viewer; Security Administrator for authorization events)";
check('probeAudit: 403 -> refused (role?) on authorization/events', $actual === $expected, 'actual: ' . $actual);
check('probeAudit: OK elsewhere', $pa['raw']['/backupInfrastructure/repositories/states'][0]['_check'] === 'OK');

// ------------------------------------------------------------------
// /sessions row without result -> unexpected shape
// ------------------------------------------------------------------
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/backupObjects', vpage([], 0, 0));
$t->queue('GET /api/v1/restorePoints', vpage([], 0, 0));
$t->queue('GET /api/v1/backups', vpage([], 0, 0));
$t->queue('GET /api/v1/sessions', vpage([['id' => 's1', 'name' => 'J', 'jobId' => 'j', 'creationTime' => '2026-11-01T01:00:00Z', 'endTime' => '2026-11-01T02:00:00Z', 'state' => 'Stopped']], 1, 0));
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$p = $src->probe(5);
check('probe: missing result.result -> unexpected shape', $p['raw']['/sessions'][0]['_check'] === 'unexpected shape: missing result.result');

// ------------------------------------------------------------------
// Probe::fromSource / fromAuditSource accept VeeamSource
// ------------------------------------------------------------------
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/backupObjects', vpage([], 0, 0));
$t->queue('GET /api/v1/restorePoints', vpage([], 0, 0));
$t->queue('GET /api/v1/backups', vpage([], 0, 0));
$t->queue('GET /api/v1/sessions', vpage([], 0, 0));
$src = new VeeamSource(new Client('vbr.example.test', $t, 'u', 'p'));
$lookup = new class implements \GlpiPlugin\Backupinfo\Matching\AssetLookup {
    public function byName(string $name): array { return []; }
    public function byIp(string $ip): array { return []; }
};
$m = new \GlpiPlugin\Backupinfo\Matching\Matcher($lookup);
$ps = Probe::fromSource($src, $m);
check('fromSource accepts VeeamSource', is_array($ps));
$pa = Probe::fromAuditSource($src);
check('fromAuditSource accepts VeeamSource', is_array($pa));

done();
