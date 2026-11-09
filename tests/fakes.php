<?php

// Shared fakes for the Vinchin source tests (no network).

use GlpiPlugin\Backupinfo\Http\Transport;

/** Answers by request path (method + path, no query), so tests do not depend on call order. */
final class RouteT implements Transport
{
    public array $calls = [];
    /** @var array<string, list<array>> "GET /jobs/history" => queue of responses */
    public array $routes = [];

    public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls): array
    {
        $this->calls[] = compact('method', 'url', 'body');
        $path = (string) parse_url($url, PHP_URL_PATH);
        $key  = $method . ' ' . substr($path, strlen('/api/v1'));
        if (!empty($this->routes[$key])) {
            return array_shift($this->routes[$key]);
        }
        return ['status' => 200, 'headers' => [], 'body' => json_encode(['success' => false, 'code' => 404, 'message' => 'no route'])];
    }

    /** @return list<array{method:string,url:string,body:?string}> calls to $method $path */
    public function to(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => $c['method'] === $method && parse_url($c['url'], PHP_URL_PATH) === '/api/v1' . $path
        ));
    }
}

function okd($data): array
{
    return ['status' => 200, 'headers' => [], 'body' => json_encode(['success' => true, 'data' => $data])];
}
function rows(array $rows): array
{
    return okd(['total' => count($rows), 'rows' => $rows]);
}
function query(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    return $q;
}
