<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Source/Time.php';
require BI_ROOT . '/src/Http/Transport.php';
require BI_ROOT . '/src/Http/CurlTransport.php';
require BI_ROOT . '/src/Veeam/ApiError.php';
require BI_ROOT . '/src/Veeam/Client.php';

require __DIR__ . '/veeam_fakes.php';

use GlpiPlugin\Backupinfo\Source\Time;
use GlpiPlugin\Backupinfo\Http\Transport;
use GlpiPlugin\Backupinfo\Veeam\ApiError;
use GlpiPlugin\Backupinfo\Veeam\Client;

// ------------------------------------------------------------------
// Time
// ------------------------------------------------------------------
date_default_timezone_set('Asia/Jakarta');
check('Time local Z -> Jakarta', Time::local('2026-10-31T17:00:00Z') === '2026-11-01 00:00:00');
check('Time local +07:00 -> same', Time::local('2026-11-01T00:00:00+07:00') === '2026-11-01 00:00:00');
check('Time local no offset -> same', Time::local('2026-11-01 00:00:00') === '2026-11-01 00:00:00');
check('Time local garbage is null', Time::local('x') === null);
check('Time local empty is null', Time::local('') === null);
check('Time local null is null', Time::local(null) === null);

// ------------------------------------------------------------------
// login: form-encoded body with http_build_query, x-api-version header
// ------------------------------------------------------------------
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$c = new Client('vbr.example.test', $t, 'DOMAIN\\svc', 'p&w', '1.3-rev1', true);
$c->login();
$body = $t->calls[0]['body'];
check('login body equals http_build_query', $body === http_build_query(['grant_type' => 'password', 'username' => 'DOMAIN\\svc', 'password' => 'p&w']), $body);
check('login header x-api-version', ($t->calls[0]['headers']['x-api-version'] ?? '') === '1.3-rev1');

// ------------------------------------------------------------------
// default port 9419 when host has none; custom port kept
// ------------------------------------------------------------------
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vpage([], 0, 0));
$c = new Client('vbr.example.test', $t, 'u', 'p');
$c->get('/sessions');
check('default port', str_starts_with($t->calls[1]['url'], 'https://vbr.example.test:9419/api/v1/sessions'));

$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vpage([], 0, 0));
$c = new Client('vbr.example.test:9500', $t, 'u', 'p');
$c->get('/sessions');
check('custom port kept', str_starts_with($t->calls[1]['url'], 'https://vbr.example.test:9500/api/v1/sessions'));

// ------------------------------------------------------------------
// paging: three pages of 2 rows with total 5
// ------------------------------------------------------------------
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vpage([['id' => 1], ['id' => 2]], 5, 0));
$t->queue('GET /api/v1/sessions', vpage([['id' => 3], ['id' => 4]], 5, 2));
$t->queue('GET /api/v1/sessions', vpage([['id' => 5]], 5, 4));
$c = new Client('vbr.example.test', $t, 'u', 'p');
$rows = $c->paged('/sessions');
check('paging: 5 rows', count($rows) === 5, json_encode($rows));
check('paging: skip values', $t->calls[2]['url'] === 'https://vbr.example.test:9419/api/v1/sessions?skip=2&limit=200' && $t->calls[3]['url'] === 'https://vbr.example.test:9419/api/v1/sessions?skip=4&limit=200', json_encode(array_column($t->calls, 'url')));
check('paging: lastTruncated false', $c->lastTruncated === false);

// paging with maxRows 4 -> lastTruncated true
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vpage([['id' => 1], ['id' => 2]], 5, 0));
$t->queue('GET /api/v1/sessions', vpage([['id' => 3], ['id' => 4]], 5, 2));
$c = new Client('vbr.example.test', $t, 'u', 'p');
$rows = $c->paged('/sessions', [], 200, 4);
check('paging maxRows: 4 rows', count($rows) === 4);
check('paging maxRows: lastTruncated true', $c->lastTruncated === true);

// paging with stop callback
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vpage([['id' => 1]], 5, 0));
$t->queue('GET /api/v1/sessions', vpage([['id' => 2]], 5, 2));
$c = new Client('vbr.example.test', $t, 'u', 'p');
$rows = $c->paged('/sessions', [], 200, 2000, fn(array $page): bool => count($page) >= 1);
check('paging stop: one page', count($rows) === 1 && count($t->calls) === 2, json_encode(array_column($t->calls, 'method')));

// ------------------------------------------------------------------
// 401: refresh once, then retry with new token
// ------------------------------------------------------------------
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-first', 'refresh_token' => 'refresh-first', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vj([], 401));
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-abc', 'refresh_token' => 'refresh-abc', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vpage([['id' => 1]], 1, 0));
$c = new Client('vbr.example.test', $t, 'u', 'p');
$rows = $c->get('/sessions');
$posts = array_values(array_filter($t->calls, fn($c2) => $c2['method'] === 'POST' && str_contains($c2['url'], '/token')));
check('401: exactly two token POSTs (login + refresh)', count($posts) === 2, json_encode(array_column($t->calls, 'method')));
check('401: rows returned', isset($rows['data'][0]) && $rows['data'][0]['id'] === 1);
check('401: retry uses new token', str_contains(($t->calls[3]['headers']['Authorization'] ?? ''), 'access-abc'));

// 401 after refresh -> login again -> 200
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-first', 'refresh_token' => 'refresh-first', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vj([], 401));
$t->queue('POST /api/oauth2/token', vj([], 400));           // refresh fails
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-def', 'refresh_token' => 'refresh-def', 'expires_in' => 900])); // login
$t->queue('GET /api/v1/sessions', vpage([['id' => 1]], 1, 0));
$c = new Client('vbr.example.test', $t, 'u', 'p');
$rows = $c->get('/sessions');
check('401: refresh then login works', isset($rows['data'][0]) && $rows['data'][0]['id'] === 1);

// second 401 throws ApiError
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-first', 'refresh_token' => 'refresh-first', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vj([], 401));
$t->queue('POST /api/oauth2/token', vj([], 401));           // refresh fails with 401
$t->queue('POST /api/oauth2/token', vj([], 401));           // login fails with 401
$c = new Client('vbr.example.test', $t, 'u', 'p');
try {
    $c->get('/sessions');
    check('401: second 401 does not throw', false);
} catch (ApiError $e) {
    check('401: second 401 throws ApiError status 401', $e->status === 401);
}

// ------------------------------------------------------------------
// near expiry: clock >= expiresAt - 60 triggers refresh
// ------------------------------------------------------------------
$now = 1000;
$clock = function() use (&$now) { return $now; };
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-first', 'refresh_token' => 'refresh-first', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vpage([], 0, 0));
$c = new Client('vbr.example.test', $t, 'u', 'p', '1.3-rev2', true, $clock);
$c->get('/sessions'); // login at now=1000, expiresAt = 1900

// now = 1840 => expiresAt - 60 = 1840, refresh before next GET
$now = 1840;
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-refreshed', 'refresh_token' => 'refresh-refreshed', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vpage([], 0, 0));
$c->get('/sessions');
$posts = array_values(array_filter($t->calls, fn($c2) => $c2['method'] === 'POST' && str_contains($c2['url'], '/token')));
check('near expiry: refresh before expiry', count($posts) === 2, json_encode(array_column($t->calls, 'method')));
check('near expiry: second GET uses refreshed token', str_contains(($t->calls[3]['headers']['Authorization'] ?? ''), 'access-refreshed'));

// ------------------------------------------------------------------
// guard: POST to non-oauth2 route throws LogicException, no transport call
// ------------------------------------------------------------------
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$c = new Client('vbr.example.test', $t, 'u', 'p');
$ref = new ReflectionMethod($c, 'request');
$ref->setAccessible(true);
try {
    $ref->invoke($c, 'POST', '/api/v1/jobs/x/start', []);
    check('guard: POST other route does not throw', false);
} catch (\LogicException $e) {
    check('guard: POST other route throws LogicException', true);
}
check('guard: no transport call for POST other route', $t->calls === []);

// DELETE throws too
try {
    $ref->invoke($c, 'DELETE', '/api/v1/sessions', []);
    check('guard: DELETE does not throw', false);
} catch (\LogicException $e) {
    check('guard: DELETE throws LogicException', true);
}

// ------------------------------------------------------------------
// errors: 400 message names endpoint, status, api version, hint
// ------------------------------------------------------------------
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/jobs', vj(['errorCode' => 'BAD_VERSION', 'message' => 'unsupported'], 400));
$c = new Client('vbr.example.test', $t, 'u', 'p', '1.3-rev2');
try {
    $c->get('/jobs');
    check('400: does not throw', false);
} catch (ApiError $e) {
    $msg = $e->getMessage();
    check('400: names endpoint', str_contains($msg, 'GET /api/v1/jobs'));
    check('400: names HTTP 400', str_contains($msg, 'HTTP 400'));
    check('400: names api version', str_contains($msg, '[api 1.3-rev2]'));
    check('400: hint about 1.3-rev1 = 13.0.1', str_contains($msg, '1.3-rev1 = 13.0.1'));
    check('400: status', $e->status === 400);
}

// 403 mentions Backup Viewer
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/authorization/events', vj(['errorCode' => 'FORBIDDEN', 'message' => 'access denied'], 403));
$c = new Client('vbr.example.test', $t, 'u', 'p');
try {
    $c->get('/authorization/events');
} catch (ApiError $e) {
    check('403: mentions Backup Viewer', str_contains($e->getMessage(), 'Backup Viewer'));
}

// transport throw -> status 0 and curl text
$failing = new class implements Transport {
    public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls): array
    {
        throw new \RuntimeException('could not connect to host');
    }
};
$c = new Client('vbr.example.test', $failing, 'u', 'p');
try {
    $c->get('/sessions');
    check('transport throw: does not throw', false);
} catch (ApiError $e) {
    check('transport throw: status 0', $e->status === 0);
    check('transport throw: detail mentions port 9419', str_contains($e->getMessage(), 'port 9419'));
}

// ------------------------------------------------------------------
// no secret: no password, access-xyz, refresh-xyz in any message
// ------------------------------------------------------------------
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('GET /api/v1/sessions', vj([], 500));
$c = new Client('vbr.example.test', $t, 'u', 'TOPSECRETPW');
try {
    $c->login();
    $c->get('/sessions');
} catch (ApiError $e) {
    $msg = $e->getMessage();
    check('no secret: no password', !str_contains($msg, 'TOPSECRETPW'));
    check('no secret: no access-xyz', !str_contains($msg, 'access-xyz'));
    check('no secret: no refresh-xyz', !str_contains($msg, 'refresh-xyz'));
}

// logout swallows 500
$t = new VeeamT();
$t->queue('POST /api/oauth2/token', vj(['access_token' => 'access-xyz', 'refresh_token' => 'refresh-xyz', 'expires_in' => 900]));
$t->queue('POST /api/oauth2/logout', vj([], 500));
$c = new Client('vbr.example.test', $t, 'u', 'p');
$c->login();
$c->logout();
check('logout: swallows 500 without throwing', true);

done();
