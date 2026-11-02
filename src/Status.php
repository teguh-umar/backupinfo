<?php

namespace GlpiPlugin\Backupinfo;

/**
 * Pure backup status rules (no GLPI, no DB).
 *
 * Input objects are rows shaped like glpi_plugin_backupinfo_objects. $DB values may
 * arrive as strings, so last_backup is cast here: '' or null becomes null.
 */
final class Status
{
    public const PROTECTED = 'protected';
    public const EXPIRED = 'expired';
    public const NONE = 'none';

    private const KIND_LABELS = [
        'vm' => 'VM',
        'database' => 'Database',
        'file' => 'File',
        'agent' => 'Agent',
        'other' => 'Other',
    ];

    /**
     * Protected when any object's last backup is within that object's own RPO (its job
     * schedule + grace, or the global RPO; see Rpo). An int is the global RPO.
     * Objects that exist but report no backup time count as expired, not protected.
     *
     * @param list<array{last_backup: ?int, schedule_hours?: ?int}> $objects
     */
    public static function of(array $objects, int|Rpo $rpo, int $nowS): string
    {
        if ($objects === []) {
            return self::NONE;
        }

        $rpo = Rpo::of($rpo);
        foreach ($objects as $object) {
            $time = self::time($object);
            if ($time !== null && ($nowS - $time) <= $rpo->hoursFor($object) * 3600) {
                return self::PROTECTED;
            }
        }
        return self::EXPIRED;
    }

    public static function hasBackup(string $status): bool
    {
        return $status === self::PROTECTED || $status === self::EXPIRED;
    }

    /**
     * "Vinchin · VM · DAILY_PRODUCTION" from the newest object's kind and job.
     * Newest = greatest last_backup; when no object has a time, the first object is used.
     *
     * @param list<array{kind: string, job_name: string}> $objects
     */
    public static function mediaText(array $objects, string $sourceLabel): string
    {
        if ($objects === []) {
            return '';
        }

        $chosen = $objects[0];
        $chosenTime = self::time($chosen);
        foreach ($objects as $object) {
            $time = self::time($object);
            if ($time !== null && ($chosenTime === null || $time > $chosenTime)) {
                $chosen = $object;
                $chosenTime = $time;
            }
        }

        $kind = strtolower(trim((string) ($chosen['kind'] ?? '')));
        $parts = [$sourceLabel, self::KIND_LABELS[$kind] ?? 'Other'];
        $job = trim((string) ($chosen['job_name'] ?? ''));
        if ($job !== '') {
            $parts[] = $job;
        }

        return implode(' · ', array_filter($parts, static fn(string $p): bool => $p !== ''));
    }

    /**
     * last_backup as int seconds, or null for null, '' or a missing key.
     */
    private static function time(array $object): ?int
    {
        $value = $object['last_backup'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
