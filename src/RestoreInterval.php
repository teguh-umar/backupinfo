<?php

namespace GlpiPlugin\Backupinfo;

use Config;

/**
 * How often a Computer's backup must be restore-tested (user, 2026-11-04: flexible). A Setup default
 * in months, overridable per Computer; 0 = no requirement (exempt). Pure helpers plus the DB part.
 */
final class RestoreInterval
{
    public const TABLE      = 'glpi_plugin_backupinfo_restoreintervals';
    public const MAX_MONTHS = 120;
    /** "due" this many days before the due date */
    public const DUE_DAYS   = 30;

    public const EXEMPT  = 'exempt';
    public const NONE    = 'none';
    public const OK      = 'ok';
    public const DUE     = 'due';
    public const OVERDUE = 'overdue';

    public static function effective(int $global, ?int $override): int
    {
        return max(0, $override ?? $global);
    }

    public static function status(?string $lastDate, int $months, int $nowS): string
    {
        if ($months <= 0) {
            return self::EXEMPT;
        }
        $due = self::nextDue($lastDate, $months);
        if ($due === null) {
            return self::NONE;
        }
        $today = date('Y-m-d', $nowS);
        if ($today > $due) {
            return self::OVERDUE;
        }
        $daysLeft = (int) ((strtotime($due) - strtotime($today)) / 86400);
        return $daysLeft <= self::DUE_DAYS ? self::DUE : self::OK;
    }

    /** Last test date + months, clamped to the month's end (30 Nov + 3 = 28 Feb); null when exempt or no test. */
    public static function nextDue(?string $lastDate, int $months): ?string
    {
        if ($months <= 0 || $lastDate === null) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($lastDate), 0, 10));
        if ($d === false || $d->format('Y-m-d') !== substr(trim($lastDate), 0, 10)) {
            return null;
        }
        $first = $d->modify('first day of this month')->modify("+$months months");
        $day   = min((int) $d->format('j'), (int) $first->format('t'));
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('Y-m-d');
    }

    /**
     * A per-Computer override typed in a form: blank = none (Setup default), else 0..MAX_MONTHS.
     *
     * @return array{ok: bool, months: ?int}
     */
    public static function parseOverride(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => true, 'months' => null];
        }
        if (preg_match('/^\d{1,3}$/', $raw) !== 1 || (int) $raw > self::MAX_MONTHS) {
            return ['ok' => false, 'months' => null];
        }
        return ['ok' => true, 'months' => (int) $raw];
    }

    public static function globalMonths(): int
    {
        $cfg = Config::getConfigurationValues(SuiteConfig::CONTEXT, ['restore_interval_months']);
        return max(0, (int) ($cfg['restore_interval_months'] ?? 12));
    }

    public static function get(int $computerId): ?int
    {
        global $DB;

        foreach ($DB->request(['SELECT' => ['months'], 'FROM' => self::TABLE, 'WHERE' => ['computers_id' => $computerId]]) as $row) {
            return (int) $row['months'];
        }
        return null;
    }

    /** @param list<int> $computerIds @return array<int, int> computers_id => months */
    public static function overrides(array $computerIds): array
    {
        global $DB;

        $out = [];
        foreach (array_chunk(array_values(array_unique($computerIds)), 500) as $chunk) {
            foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['computers_id' => $chunk]]) as $row) {
                $out[(int) $row['computers_id']] = (int) $row['months'];
            }
        }
        return $out;
    }

    /** null removes the override (the Setup default applies again). */
    public static function set(int $computerId, ?int $months): void
    {
        global $DB;

        $DB->delete(self::TABLE, ['computers_id' => $computerId]);
        if ($months !== null) {
            $DB->insert(self::TABLE, ['computers_id' => $computerId, 'months' => $months]);
        }
    }
}
