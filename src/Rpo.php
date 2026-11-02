<?php

namespace GlpiPlugin\Backupinfo;

/**
 * The age limit for one backup object: its job's schedule interval plus a grace, read from the
 * backup system (schedule_hours, set by sync), or the global RPO when the schedule is unknown.
 * Job names do not follow the real schedule (user, 2026-11-02), so they are never parsed.
 * Pure apart from fromConfig().
 */
final class Rpo
{
    public const DEFAULT_GLOBAL = 26;
    public const DEFAULT_GRACE  = 24;
    public const GRACE_MAX      = 168;

    public const SOURCE_SCHEDULE = 'schedule';
    public const SOURCE_GLOBAL   = 'global';

    public function __construct(public readonly int $globalHours, public readonly int $graceHours = self::DEFAULT_GRACE)
    {
    }

    /** An int is the global RPO with the default grace (job schedules still apply). */
    public static function of(int|self $rpo): self
    {
        return $rpo instanceof self ? $rpo : new self($rpo);
    }

    public static function fromConfig(): self
    {
        return self::fromValues(\Config::getConfigurationValues('plugin:backupinfo', ['rpo_hours', 'rpo_grace_hours']));
    }

    /** @param array<string, mixed> $cfg rpo_hours, rpo_grace_hours; bad or missing values use the defaults */
    public static function fromValues(array $cfg): self
    {
        $global = (int) ($cfg['rpo_hours'] ?? self::DEFAULT_GLOBAL);
        $raw    = $cfg['rpo_grace_hours'] ?? null;
        $grace  = ($raw === null || $raw === '' || (int) $raw < 0 || (int) $raw > self::GRACE_MAX) ? self::DEFAULT_GRACE : (int) $raw;
        return new self($global > 0 ? $global : self::DEFAULT_GLOBAL, $grace);
    }

    /** @param array<string, mixed> $object row with schedule_hours (null, '' or 0 = unknown) */
    public function hoursFor(array $object): int
    {
        $schedule = self::schedule($object);
        return $schedule === null ? $this->globalHours : $schedule + $this->graceHours;
    }

    public function sourceFor(array $object): string
    {
        return self::schedule($object) === null ? self::SOURCE_GLOBAL : self::SOURCE_SCHEDULE;
    }

    /** "360 h (schedule 336 h + 24 h)" or "26 h (global)", for tables. */
    public function label(array $object): string
    {
        $schedule = self::schedule($object);
        return $schedule === null
            ? sprintf('%d h (global)', $this->globalHours)
            : sprintf('%d h (schedule %d h + %d h)', $schedule + $this->graceHours, $schedule, $this->graceHours);
    }

    private static function schedule(array $object): ?int
    {
        $value = $object['schedule_hours'] ?? null;
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }
        return (int) $value;
    }
}
