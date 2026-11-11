<?php

namespace GlpiPlugin\Backupinfo\Source;

use GlpiPlugin\Backupinfo\Vinchin\Client;
use GlpiPlugin\Backupinfo\Http\CurlTransport;
use GlpiPlugin\Backupinfo\Vinchin\VinchinSource;
use GlpiPlugin\Backupinfo\Veeam\Client as VeeamClient;
use GlpiPlugin\Backupinfo\Veeam\VeeamSource;

final class Registry
{
    /** @return array<string, string> source type => label (shown in the Type dropdown) */
    public static function types(): array
    {
        return [
            'vinchin' => 'Vinchin',
            'veeam'   => 'Veeam B&R 13 (preview, not verified on a server)',
        ];
    }

    /** @return array<string, string> source type => short label (used in media text) */
    public static function labels(): array
    {
        return ['vinchin' => 'Vinchin', 'veeam' => 'Veeam'];
    }

    /** Default source type for new connection records. */
    public const DEFAULT_TYPE = 'vinchin';

    /**
     * Per-type form field names, from the source class's FORM_FIELDS, or [] for an unknown type.
     *
     * @return list<string>
     */
    public static function formFields(string $type): array
    {
        $src = self::sourceClass($type);
        return $src === null ? [] : ($src::FORM_FIELDS ?? []);
    }

    /** Default values for a new connection of the given type, from the source class's DEFAULTS. */
    public static function defaults(string $type): array
    {
        $src = self::sourceClass($type);
        return $src === null ? [] : ($src::DEFAULTS ?? []);
    }

    /**
     * Per-type declared audit gaps, section key => one sentence, from the source class's GAPS.
     *
     * @return array<string, string>
     */
    public static function gaps(string $type): array
    {
        $src = self::sourceClass($type);
        return $src === null ? [] : ($src::GAPS ?? []);
    }

    /**
     * Resolve the source class for a type, or null.
     *
     * @return class-string|null
     */
    private static function sourceClass(string $type): ?string
    {
        return match ($type) {
            'vinchin' => \GlpiPlugin\Backupinfo\Vinchin\VinchinSource::class,
            'veeam'   => \GlpiPlugin\Backupinfo\Veeam\VeeamSource::class,
            default   => null,
        };
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
        $type   = (string) ($connectionRow['type'] ?? '');
        $defaults = self::defaults($type);
        if (self::sourceClass($type) !== null && trim((string) ($connectionRow['host'] ?? '')) === '') {
            // 0.5.0-rc4: a blank host built "https://:9419/..." and failed deep in the client.
            throw new \InvalidArgumentException('Host is empty: enter the backup server address in Setup > Backup Info > Connections.');
        }

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
                (($connectionRow['auth_mode'] ?? $defaults['auth_mode'] ?? 'api_key') === 'login'),
                $vmNames ?? static fn(): array => []
            ),
            'veeam' => new VeeamSource(
                new VeeamClient(
                    (string) ($connectionRow['host'] ?? ''),
                    new CurlTransport(),
                    (string) ($connectionRow['username'] ?? ''),
                    $decrypt((string) ($connectionRow['password'] ?? '')),
                    trim((string) ($connectionRow['api_version'] ?? '')) ?: '1.3-rev2',
                    !empty($connectionRow['verify_tls'] ?? 1)
                )
            ),
            default => throw new \InvalidArgumentException("Unknown backup source type: {$type}"),
        };
    }
}
