<?php

namespace GlpiPlugin\Backupinfo\Source;

use GlpiPlugin\Backupinfo\Vinchin\Client;
use GlpiPlugin\Backupinfo\Http\CurlTransport;
use GlpiPlugin\Backupinfo\Vinchin\VinchinSource;

final class Registry
{
    /** @return array<string, string> source type => label */
    public static function types(): array
    {
        return ['vinchin' => 'Vinchin'];
    }

    /**
     * Build the BackupSource for a connection row.
     *
     * @param array<string, mixed> $connectionRow connection row, must carry 'type'
     * @param callable(string): string $decrypt   decrypts a stored secret (used by sources in later tasks)
     * @param (callable(): list<string>)|null $vmNames Computer names for the Vinchin job-history fallback
     *        (optional third argument so existing two-argument calls keep working; Task 10's cron injects it)
     * @throws \InvalidArgumentException when the type has no source implementation
     */
    public static function create(array $connectionRow, callable $decrypt, ?callable $vmNames = null): BackupSource
    {
        $type = (string) ($connectionRow['type'] ?? '');

        return match ($type) {
            'vinchin' => new VinchinSource(
                new Client(
                    (string) ($connectionRow['host'] ?? ''),
                    new CurlTransport(),
                    $decrypt((string) ($connectionRow['api_key'] ?? '')),
                    (string) ($connectionRow['username'] ?? ''),
                    $decrypt((string) ($connectionRow['password'] ?? '')),
                    !empty($connectionRow['verify_tls'] ?? 1)
                ),
                ($connectionRow['auth_mode'] ?? 'api_key') === 'login',
                $vmNames ?? static fn(): array => []
            ),
            default => throw new \InvalidArgumentException("Unknown backup source type: {$type}"),
        };
    }
}
