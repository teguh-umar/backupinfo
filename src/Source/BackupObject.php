<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * One protected (or unprotected) object as reported by a backup source.
 * lastBackup is a UTC epoch in seconds, or null when the source never reported a backup.
 * scheduleHours is the job schedule interval (see Rpo), or null when unknown.
 * timepointUuid is the newest restore point the source listed (its verification history), or null.
 * jobType is the source's job kind ("Backup", "Copy"; several sorted and ", "-joined), '' when unknown.
 * storage is where the backup is written ("NAME (Type)", several ", "-joined), '' when unknown.
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
        public readonly string $jobType = '',
        public readonly string $storage = '',
    ) {
    }

    public function withSchedule(?int $hours): self
    {
        return new self($this->sourceKey, $this->name, $this->ip, $this->kind, $this->jobName, $this->lastBackup,
            $this->restorePoints, $this->dataSize, $this->abnormalChain, $this->sourceDeleted, $hours, $this->timepointUuid, $this->jobType, $this->storage);
    }

    public function withStorage(string $storage): self
    {
        return new self($this->sourceKey, $this->name, $this->ip, $this->kind, $this->jobName, $this->lastBackup,
            $this->restorePoints, $this->dataSize, $this->abnormalChain, $this->sourceDeleted, $this->scheduleHours,
            $this->timepointUuid, $this->jobType, $storage);
    }
}
