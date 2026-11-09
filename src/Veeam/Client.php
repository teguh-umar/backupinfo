<?php

namespace GlpiPlugin\Backupinfo\Veeam;

use GlpiPlugin\Backupinfo\Http\Transport;

/**
 * Read-only Veeam Backup & Replication 13 REST client (OAuth2 password grant).
 *
 * GET only, plus POST to /api/oauth2/token and /api/oauth2/logout. Every request
 * carries x-api-version. Errors are self-explaining ApiErrors naming the endpoint,
 * HTTP status, API version and a hint. No secret ever appears in a message.
 */
final class Client
{
    private const POST_ROUTES = ['/api/oauth2/token', '/api/oauth2/logout'];

    /** API v1 base for GET/paged (e.g. https://host:port/api/v1) */
    private string $base;
    /** OAuth2 base for login/refresh/logout (e.g. https://host:port/api) */
    private string $oauthBase;
    private Transport $transport;
    private string $username;
    private string $password;
    private string $apiVersion;
    private bool $verifyTls;
    private \Closure $clock;

    /** @var bool set after the first paged()/get() call; true only when stopped by maxRows */
    public bool $lastTruncated = false;

    private string $accessToken = '';
    private string $refreshToken = '';
    private int $expiresAt = 0;

    public function __construct(
        string $host,
        Transport $transport,
        string $username,
        string $password,
        string $apiVersion = '1.3-rev2',
        bool $verifyTls = true,
        ?\Closure $clock = null
    ) {
        $host = rtrim(trim($host), '/');
        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }
        $parsed = parse_url($host);
        $scheme = $parsed['scheme'] ?? 'https';
        $hostPart = $parsed['host'] ?? $host;
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : ':9419';
        $this->base     = $scheme . '://' . $hostPart . $port . '/api/v1';
        $this->oauthBase = $scheme . '://' . $hostPart . $port . '/api';
        $this->transport   = $transport;
        $this->username    = $username;
        $this->password    = $password;
        $this->apiVersion  = $apiVersion;
        $this->verifyTls   = $verifyTls;
        $this->clock       = $clock ?? fn(): int => (int) time();
    }

    public function login(): void
    {
        $body = http_build_query([
            'grant_type' => 'password',
            'username'   => $this->username,
            'password'   => $this->password,
        ]);
        $r = $this->sendRequest('POST', $this->oauthBase . '/oauth2/token', [
            'x-api-version'  => $this->apiVersion,
            'Accept'         => 'application/json',
            'Content-Type'   => 'application/x-www-form-urlencoded',
        ], $body);
        $this->processTokenResponse($r, $this->oauthBase . '/oauth2/token');
    }

    public function logout(): void
    {
        if ($this->accessToken === '') {
            return;
        }
        try {
            $this->sendRequest('POST', $this->oauthBase . '/oauth2/logout', [
                'x-api-version' => $this->apiVersion,
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->accessToken,
            ], null);
        } catch (\Throwable) {
            // logout never throws
        }
        $this->accessToken = '';
        $this->refreshToken = '';
        $this->expiresAt   = 0;
    }

    /** @return array<string,mixed> decoded JSON */
    public function get(string $path, array $query = []): array
    {
        $r = $this->request('GET', $path, $query);
        $js = json_decode($r['body'], true);
        if (!is_array($js)) {
            throw new ApiError('GET', $this->apiPath($this->base . $path), $r['status'], $this->apiVersion, $this->detail($r));
        }
        return $js;
    }

    /**
     * @return list<array> rows from data[]
     */
    public function paged(string $path, array $query = [], int $limit = 200, int $maxRows = 2000, ?callable $stop = null): array
    {
        $rows = [];
        $skip = 0;
        $this->lastTruncated = false;
        while ($skip < $maxRows) {
            $q = $query + ['skip' => $skip, 'limit' => $limit];
            $r = $this->request('GET', $path, $q);
            $js = json_decode($r['body'], true);
            if (!is_array($js) || !isset($js['data']) || !is_array($js['data'])) {
                throw new ApiError('GET', $path, $r['status'], $this->apiVersion, $this->detail($r));
            }
            $page = $js['data'];
            array_push($rows, ...$page);
            $skip += count($page);
            $pagination = $js['pagination'] ?? [];
            $total = (int) ($pagination['total'] ?? 0);
            if ($page === [] || $skip >= $total || ($stop !== null && $stop($page) === true)) {
                break;
            }
        }
        if ($skip >= $maxRows) {
            $this->lastTruncated = true;
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Guard + request
    // ------------------------------------------------------------------

    /**
     * Route/method guard: any method other than GET/POST, or POST to a route
     * outside the allowlist, throws \LogicException before the transport is called.
     */
    private function request(string $method, string $path, array $query = [], ?string $body = null): array
    {
        if ($method === 'GET') {
            if ($this->accessToken === '') {
                $this->login();
            } elseif (($this->clock)() >= $this->expiresAt - 60) {
                $this->refresh();
            }
        }

        $url = $this->base . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $headers = [
            'x-api-version' => $this->apiVersion,
            'Accept'        => 'application/json',
        ];
        if ($this->accessToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        $r = $this->sendRequest($method, $url, $headers, $body);

        // Reactive 401: refresh once, else log in again, then retry once.
        if ($method === 'GET' && $r['status'] === 401) {
            $retried = false;
            try {
                $this->refresh();
                $retried = true;
            } catch (\Throwable) {
                try {
                    $this->login();
                    $retried = true;
                } catch (\Throwable) {
                    $retried = false;
                }
            }
            if ($retried) {
                $headers['Authorization'] = 'Bearer ' . $this->accessToken;
                $r = $this->sendRequest($method, $url, $headers, $body);
                if ($r['status'] === 401) {
                    throw new ApiError($method, $this->apiPath($url), $r['status'], $this->apiVersion, $this->detail($r));
                }
            }
        }

        if ($r['status'] >= 400) {
            throw new ApiError($method, $this->apiPath($url), $r['status'], $this->apiVersion, $this->detail($r));
        }

        return $r;
    }

    /**
     * Send through the transport, converting any \Throwable into an ApiError
     * with status 0 and the curl text as detail.
     */
    private function sendRequest(string $method, string $url, array $headers, ?string $body): array
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($method !== 'GET' && $method !== 'POST') {
            throw new \LogicException("Method {$method} not allowed");
        }
        if ($method === 'POST' && !in_array($path, self::POST_ROUTES, true)) {
            throw new \LogicException("POST {$path} not allowed");
        }
        try {
            return $this->transport->send($method, $url, $headers, $body, $this->verifyTls);
        } catch (\Throwable $e) {
            throw new ApiError($method, $url, 0, $this->apiVersion, $e->getMessage());
        }
    }

    private function processTokenResponse(array $r, string $url): void
    {
        if ($r['status'] >= 400) {
            throw new ApiError('POST', $url, $r['status'], $this->apiVersion, $this->detail($r));
        }
        $js = json_decode($r['body'], true);
        if (!is_array($js)) {
            throw new ApiError('POST', $url, $r['status'], $this->apiVersion, $this->detail($r));
        }
        $this->accessToken = (string) ($js['access_token'] ?? '');
        $this->refreshToken = (string) ($js['refresh_token'] ?? '');
        $this->expiresAt   = (int) ($this->clock)() + (int) ($js['expires_in'] ?? 900);
    }

    private function refresh(): void
    {
        if ($this->refreshToken === '') {
            $this->login();
            return;
        }
        $body = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ]);
        $r = $this->sendRequest('POST', $this->oauthBase . '/oauth2/token', [
            'x-api-version'  => $this->apiVersion,
            'Accept'         => 'application/json',
            'Content-Type'   => 'application/x-www-form-urlencoded',
        ], $body);
        $this->processTokenResponse($r, $this->oauthBase . '/oauth2/token');
    }

    /** Extract the API path (e.g. /api/v1/jobs) from a full URL. */
    private function apiPath(string $url): string
    {
        return parse_url($url, PHP_URL_PATH) ?? '';
    }

    /** @return string body's errorCode and message, cut to 200 chars */
    private function detail(array $r): string
    {
        $js = json_decode($r['body'], true);
        if (!is_array($js)) {
            return substr((string) $r['body'], 0, 200);
        }
        $code = (string) ($js['errorCode'] ?? '');
        $msg  = (string) ($js['message'] ?? '');
        $detail = $code !== '' ? "$code: $msg" : $msg;
        return substr($detail, 0, 200);
    }
}
