<?php

namespace GlpiPlugin\Backupinfo\Veeam;

/**
 * Self-explaining Veeam REST error: names the endpoint, HTTP status, API version
 * and a hint. Never carries secrets.
 */
final class ApiError extends \RuntimeException
{
    public readonly int $status;

    /**
     * @param string $detail body's errorCode and message, cut to 200 chars
     */
    public function __construct(
        string $method,
        string $path,
        int $status,
        string $apiVersion,
        string $detail = ''
    ) {
        $hint = self::hint($status);
        $detailPart = $detail !== '' ? " ({$detail})" : '';
        parent::__construct("{$method} {$path}: HTTP {$status}{$detailPart} [api {$apiVersion}] - {$hint}");
        $this->status = $status;
    }

    private static function hint(int $status): string
    {
        return match ($status) {
            400 => 'check the API version (1.3-rev2 = 13.1, 1.3-rev1 = 13.0.1)',
            401 => 'check the username and password (an account without MFA)',
            403 => "the account's role cannot read this (Backup Viewer; Security Administrator for authorization events)",
            404 => 'endpoint not on this server version',
            0   => 'no answer: check host, port 9419 and the TLS certificate',
            default => 'unexpected answer',
        };
    }
}
