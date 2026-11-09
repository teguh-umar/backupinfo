<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Vinchin/Signer.php';
require BI_ROOT . '/src/Http/Transport.php';
require BI_ROOT . '/src/Vinchin/Client.php';

use GlpiPlugin\Backupinfo\Vinchin\Client;
use GlpiPlugin\Backupinfo\Http\Transport;

final class FakeTransport implements Transport
{
    public array $calls = [];
    /** @var list<array> */
    public array $queue = [];

    public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body', 'verifyTls');
        return array_shift($this->queue) ?? ['status' => 500, 'headers' => [], 'body' => ''];
    }
}

function ok($data, array $headers = []): array
{
    return ['status' => 200, 'headers' => $headers, 'body' => json_encode(['success' => true, 'data' => $data])];
}

function mk(FakeTransport $t, string $key = 'KEY123', string $user = '', string $pw = ''): Client
{
    return new Client('https://vin.example/', $t, $key, $user, $pw, false, fn() => 1761638400000);
}

// headers + api-key mode + csrf propagation
$t = new FakeTransport();
$t->queue = [ok([], ['__token__' => 'TOK1']), ok([])];
$c = mk($t);
$c->getPaged('/x', ['a' => true, 'b' => false, 'n' => 3]);
$c->getPaged('/x', []);
$h = $t->calls[0]['headers'];
check('x-api-version', ($h['x-api-version'] ?? '') === '1.0-rev0');
check('X-Requested-With', ($h['X-Requested-With'] ?? '') === 'XMLHttpRequest');
check('X-API-Key sent', ($h['X-API-Key'] ?? '') === 'KEY123');
check('no Authorization in api-key mode', !isset($h['Authorization']));
check('first csrf empty', ($h['X-Csrf-Token'] ?? 'unset') === '');
check('csrf token reused', ($t->calls[1]['headers']['X-Csrf-Token'] ?? '') === 'TOK1');
check('base url normalised', str_starts_with($t->calls[0]['url'], 'https://vin.example/api/v1/x?'));
check('GET bools true/false', str_contains($t->calls[0]['url'], 'a=true') && str_contains($t->calls[0]['url'], 'b=false') && str_contains($t->calls[0]['url'], 'n=3'));
check('GET signed', str_contains($t->calls[0]['url'], 'sign=') && str_contains($t->calls[0]['url'], 'timestamp=1761638400000'));
check('GET no body', $t->calls[0]['body'] === null);
check('verifyTls forwarded', $t->calls[0]['verifyTls'] === false);

// case-insensitive token header
$t = new FakeTransport();
$t->queue = [ok([], ['__Token__' => 'UP']), ok([])];
$c = mk($t);
$c->getPaged('/x', []);
$c->getPaged('/x', []);
check('csrf header name case-insensitive', ($t->calls[1]['headers']['X-Csrf-Token'] ?? '') === 'UP');

// POST body
$t = new FakeTransport();
$t->queue = [ok(['rows' => [], 'total' => 0])];
mk($t)->postPaged('/p', ['q' => 'é']);
$b = json_decode($t->calls[0]['body'], true);
check('POST json body', $t->calls[0]['method'] === 'POST' && $b['q'] === 'é' && $b['offset'] === 0 && $b['limit'] === 100 && isset($b['sign'], $b['timestamp']));
check('POST content-type', ($t->calls[0]['headers']['Content-Type'] ?? '') === 'application/x-www-form-urlencoded; charset=UTF-8');
check('POST url has no query', $t->calls[0]['url'] === 'https://vin.example/api/v1/p');

// 910087 retry
$retry = ['status' => 200, 'headers' => ['__token__' => 'N'], 'body' => json_encode(['success' => false, 'code' => 910087])];
$t = new FakeTransport();
$t->queue = [$retry, $retry, ok(['rows' => [['id' => 1]], 'total' => 1])];
$rows = mk($t)->postPaged('/p', []);
check('retry then success', count($t->calls) === 3 && $rows === [['id' => 1]]);
check('retry carries new token', ($t->calls[1]['headers']['X-Csrf-Token'] ?? '') === 'N');

$t = new FakeTransport();
$t->queue = [$retry, $retry, $retry, $retry, $retry];
$msg = '';
try { mk($t)->postPaged('/p', []); } catch (\RuntimeException $e) { $msg = $e->getMessage(); }
check('retry stops after 3 retries (4 calls)', count($t->calls) === 4);
check('retry exhaustion error', str_starts_with($msg, '/p: code 910087'), $msg);

// paging
$t = new FakeTransport();
$t->queue = [ok(['rows' => [['i' => 1], ['i' => 2]], 'total' => 3]), ok(['list' => [['i' => 3]], 'total' => 3])];
$rows = mk($t)->postPaged('/p', [], 2);
check('paging stops at total', count($rows) === 3 && count($t->calls) === 2);
check('second page offset', json_decode($t->calls[1]['body'], true)['offset'] === 2);

$t = new FakeTransport();
$t->queue = [ok(['rows' => [['i' => 1], ['i' => 2]], 'total' => 99]), ok(['rows' => [['i' => 3], ['i' => 4]], 'total' => 99])];
$rows = mk($t)->postPaged('/p', [], 2, 3);
check('paging respects max', count($rows) === 4 && count($t->calls) === 2);

$t = new FakeTransport();
$t->queue = [ok(['rows' => [['i' => 1]], 'total' => 50]), ok(['rows' => [['i' => 2]], 'total' => 50])];
$rows = mk($t)->getPaged('/g', [], 1, 3000, fn(array $batch) => $batch[0]['i'] === 1);
check('getPaged stop callback', count($rows) === 1 && count($t->calls) === 1);

// errors
$t = new FakeTransport();
$t->queue = [['status' => 502, 'headers' => [], 'body' => '<html>' . str_repeat('x', 300)]];
$msg = '';
try { mk($t, 'SECRETKEY')->getPaged('/g', []); } catch (\RuntimeException $e) { $msg = $e->getMessage(); }
check('non-JSON error format', str_starts_with($msg, '/g: HTTP 502 not JSON (<html>') && strlen($msg) < 160, $msg);
check('non-JSON error has no key', !str_contains($msg, 'SECRETKEY'));

$t = new FakeTransport();
$t->queue = [['status' => 200, 'headers' => [], 'body' => json_encode(['success' => false, 'code' => 5, 'message' => 'bad'])]];
$msg = '';
try { mk($t)->getPaged('/g', []); } catch (\RuntimeException $e) { $msg = $e->getMessage(); }
check('API error format', $msg === '/g: code 5 bad', $msg);

// login
$t = new FakeTransport();
$t->queue = [
    ['status' => 200, 'headers' => [], 'body' => json_encode(['success' => false, 'code' => 1, 'message' => 'no'])],
    ok(['access_token' => 'ACCESS9']),
    ok(['rows' => [], 'total' => 0]),
];
$c = mk($t, '', 'admin', 'pw-plain');
$c->login();
$first = json_decode($t->calls[0]['body'], true);
$second = json_decode($t->calls[1]['body'], true);
check('login tries RSA first', $first['username'] === 'admin' && $first['password'] !== 'pw-plain' && strlen(base64_decode($first['password'])) === 128);
check('login falls back to plain', $second['password'] === 'pw-plain');
check('login url', $t->calls[0]['url'] === 'https://vin.example/api/v1/login');
$c->postPaged('/p', []);
check('Authorization without Bearer', ($t->calls[2]['headers']['Authorization'] ?? '') === 'ACCESS9');
check('no X-API-Key when key empty', !isset($t->calls[2]['headers']['X-API-Key']));

$t = new FakeTransport();
$bad = ['status' => 200, 'headers' => [], 'body' => json_encode(['success' => false, 'code' => 9, 'message' => 'denied'])];
$t->queue = [$bad, $bad];
$msg = '';
try { mk($t, '', 'admin', 'TOPSECRETPW')->login(); } catch (\RuntimeException $e) { $msg = $e->getMessage(); }
check('login failure message', str_contains($msg, 'code 9 denied') && !str_contains($msg, 'TOPSECRETPW'), $msg);

done();
