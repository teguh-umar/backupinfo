<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * One protected (or unprotected) object as reported by a backup source.
 * lastBackup is a UTC epoch in seconds, or null when the source never reported a backup.
 */
final class BackupObject
{
    public function __construct(
        public readonly string $sourceKey,
        public readonly string $name,
        public readonly string $ip,
        public readonly string $kind,
        public readonly string $jobName,
        public readonly ?int $lastBackup,
        public readonly int $restorePoints,
        public readonly string $dataSize,
        public readonly bool $abnormalChain,
        public readonly bool $sourceDeleted,
    ) {
    }
}
