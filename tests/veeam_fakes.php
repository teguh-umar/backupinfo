<?php

// Shared fakes for the Veeam source tests (no network).

use GlpiPlugin\Backupinfo\Http\Transport;

/** Answers by "METHOD /path" (path after the host, query stripped) to queued responses. */
final class VeeamT implements Transport
{
    public array $calls = [];
    /** @var array<string, list<array>> "GET /api/v1/sessions" => queue of responses */
    private array $routes = [];

    public function __construct(array $routes = [])
    {
        $this->routes = $routes;
    }

    public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body', 'verifyTls');
        $path = (string) parse_url($url, PHP_URL_PATH);
        $key  = $method . ' ' . $path;
        if (!empty($this->routes[$key])) {
            return array_shift($this->routes[$key]);
        }
        return ['status' => 404, 'headers' => [], 'body' => json_encode(['errorCode' => 'NOT_FOUND', 'message' => 'no route'])];
    }

    public function queue(string $key, array $response): void
    {
        $this->routes[$key][] = $response;
    }
}

function vj(mixed $data, int $status = 200): array
{
    return ['status' => $status, 'headers' => [], 'body' => json_encode($data)];
}

function vpage(array $rows, int $total, int $skip = 0): array
{
    return [
        'status' => 200,
        'headers' => [],
        'body' => json_encode([
            'data'       => $rows,
            'pagination' => ['total' => $total, 'count' => count($rows), 'skip' => $skip, 'limit' => 200],
        ]),
    ];
}
