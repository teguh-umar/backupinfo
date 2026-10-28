<?php

namespace GlpiPlugin\Backupinfo\Source;

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
     * @throws \InvalidArgumentException when the type has no source implementation
     */
    public static function create(array $connectionRow, callable $decrypt): BackupSource
    {
        $type = (string) ($connectionRow['type'] ?? '');

        return match ($type) {
            // Task 6 adds: 'vinchin' => new VinchinSource(...)
            default => throw new \InvalidArgumentException("Unknown backup source type: {$type}"),
        };
    }
}
