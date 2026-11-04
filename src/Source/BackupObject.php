<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * One protected (or unprotected) object as reported by a backup source.
 * lastBackup is a UTC epoch in seconds, or null when the source never reported a backup.
 * scheduleHours is the job schedule interval (see Rpo), or null when unknown.
 * timepointUuid is the newest restore point the source listed (its verification history), or null.
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
        public readonly ?int $scheduleHours = null,
        public readonly ?string $timepointUuid = null,
    ) {
    }

    public function withSchedule(?int $hours): self
    {
        return new self($this->sourceKey, $this->name, $this->ip, $this->kind, $this->jobName, $this->lastBackup,
            $this->restorePoints, $this->dataSize, $this->abnormalChain, $this->sourceDeleted, $hours, $this->timepointUuid);
    }
}
