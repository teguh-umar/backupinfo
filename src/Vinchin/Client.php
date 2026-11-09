<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

use GlpiPlugin\Backupinfo\Http\Transport;

/** Vinchin console-protocol client (port of the Python VinchinClient). Errors never carry secrets. */
final class Client
{
    private const API_VERSION = '1.0-rev0';

    private string $base;
    private string $token = '';
    private string $csrf = '';
    private \Closure $clock;

    public function __construct(
        string $host,
        private Transport $transport,
        private string $apiKey = '',
        private string $username = '',
        private string $password = '',
        private bool $verifyTls = false,
        ?\Closure $clock = null
    ) {
        $host = rtrim(trim($host), '/');
        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }
        $this->base = $host . '/api/v1';
        $this->clock = $clock ?? static fn(): int => (int) (microtime(true) * 1000);
    }

    public function login(): void
    {
        $last = [];
        foreach ([Signer::rsaEncrypt($this->password), $this->password] as $pw) {
            $js = $this->request('POST', '/login', ['username' => $this->username, 'password' => $pw]);
            $tok = is_array($js['data'] ?? null) ? ($js['data']['access_token'] ?? '') : '';
            if (is_string($tok) && $tok !== '') {
                $this->token = $tok;
                return;
            }
            $last = $js;
        }
        throw new \RuntimeException('Vinchin login failed: code ' . self::str($last['code'] ?? '') . ' ' . self::str($last['message'] ?? ($last['msg'] ?? '')));
    }

    /** @return list<array<string,mixed>> */
    public function postPaged(string $path, array $params, int $page = 100, int $max = 20000, ?callable $stop = null): array
    {
        return $this->paged('POST', $path, $params, $page, $max, $stop);
    }

    /** @return list<array<string,mixed>> */
    public function getPaged(string $path, array $params, int $page = 100, int $max = 3000, ?callable $stop = null): array
    {
        return $this->paged('GET', $path, $params, $page, $max, $stop);
    }

    /** One GET returning the response's data (e.g. a single job's details). */
    public function get(string $path, array $params = []): mixed
    {
        return $this->call('GET', $path, $params);
    }

    private function paged(string $method, string $path, array $params, int $page, int $max, ?callable $stop): array
    {
        $rows = [];
        $off = 0;
        while ($off < $max) {
            $data = $this->call($method, $path, $params + ['offset' => $off, 'limit' => $page]);
            $data = is_array($data) ? $data : [];
            $batch = $data['rows'] ?? ($data['list'] ?? []);
            $batch = is_array($batch) ? array_values($batch) : [];
            array_push($rows, ...$batch);
            $total = (int) ($data['total'] ?? 0);
            $off += count($batch);
            if ($batch === [] || $off >= $total || ($stop !== null && $stop($batch))) {
                break;
            }
        }
        return $rows;
    }

    private function call(string $method, string $path, array $params)
    {
        $js = $this->request($method, $path, $params);
        if (empty($js['success'])) {
            throw new \RuntimeException($path . ': code ' . self::str($js['code'] ?? '') . ' ' . self::str($js['message'] ?? ($js['msg'] ?? '')));
        }
        return $js['data'] ?? null;
    }

    /** 910087 = CSRF token not yet valid; the response carries a fresh one, repeat (max 3 retries). */
    private function request(string $method, string $path, array $params): array
    {
        $js = [];
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $js = $this->requestOnce($method, $path, $params);
            if (!empty($js['success']) || (string) ($js['code'] ?? '') !== '910087') {
                return $js;
            }
        }
        return $js;
    }

    private function requestOnce(string $method, string $path, array $params): array
    {
        $signed = Signer::sign($params, ($this->clock)());
        $url = $this->base . $path;
        $headers = $this->headers();
        $body = null;
        if ($method === 'GET') {
            $q = [];
            foreach ($signed as $k => $v) {
                $q[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            }
            $url .= '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
        } else {
            $body = Signer::json($signed);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        }
        $r = $this->transport->send($method, $url, $headers, $body, $this->verifyTls);
        foreach ($r['headers'] as $name => $value) {
            if (strtolower((string) $name) === '__token__' && $value !== '') {
                $this->csrf = (string) $value;
            }
        }
        $js = json_decode($r['body'], true);
        if (!is_array($js)) {
            throw new \RuntimeException($path . ': HTTP ' . $r['status'] . ' not JSON (' . substr($r['body'], 0, 120) . ')');
        }
        return $js;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        $h = [
            'x-api-version'    => self::API_VERSION,
            'X-Csrf-Token'     => $this->csrf,
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept'           => 'application/json',
        ];
        if ($this->apiKey !== '') {
            $h['X-API-Key'] = $this->apiKey;
        }
        if ($this->token !== '') {
            $h['Authorization'] = $this->token;
        }
        return $h;
    }

    private static function str($v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
