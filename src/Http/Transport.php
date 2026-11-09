<?php

namespace GlpiPlugin\Backupinfo\Http;

interface Transport
{
    /**
     * @param array<string, string> $headers
     * @return array{status:int, headers:array<string,string>, body:string} response header names lower-cased
     */
    public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls): array;
}
