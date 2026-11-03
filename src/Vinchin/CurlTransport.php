<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls): array
    {
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$respHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Vinchin connection failed: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $out];
    }
}
