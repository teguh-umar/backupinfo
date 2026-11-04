<?php

namespace GlpiPlugin\Backupinfo;

/** Audit period from a key: `YYYY-MM`, `YYYY-Qn` or `YYYY`. Anything else is the last full month. */
final class AuditPeriod
{
    private const MIN_YEAR = 2020;

    /** @return array{key: string, label: string, from: string, to: string} `to` inclusive (23:59:59) */
    public static function parse(string $key, int $nowS): array
    {
        $key  = trim($key);
        $year = (int) date('Y', $nowS);
        $out  = null;
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $key, $m) === 1) {
            $out = self::range($key, (int) $m[1], (int) $m[2], 1);
        } elseif (preg_match('/^(\d{4})-Q([1-4])$/', $key, $m) === 1) {
            $out = self::range($key, (int) $m[1], ((int) $m[2] - 1) * 3 + 1, 3);
        } elseif (preg_match('/^\d{4}$/', $key) === 1) {
            $out = self::range($key, (int) $key, 1, 12);
        }
        $valid = $out !== null && (int) substr($key, 0, 4) >= self::MIN_YEAR && (int) substr($key, 0, 4) <= $year
            && $out['from'] <= date('Y-m-d H:i:s', $nowS);
        if (!$valid) {
            $last = strtotime(date('Y-m-01', $nowS) . ' -1 month');
            $out  = self::range(date('Y-m', $last), (int) date('Y', $last), (int) date('n', $last), 1);
        }
        return $out;
    }

    /** @return array<string, string> key => label, newest first: 13 months, 8 quarters, 3 years */
    public static function choices(int $nowS): array
    {
        $out   = [];
        $first = strtotime(date('Y-m-01', $nowS));
        for ($i = 0; $i < 13; $i++) {
            $t = strtotime("-$i month", $first);
            $out[date('Y-m', $t)] = date('Y-m', $t);
        }
        $year = (int) date('Y', $nowS);
        $q    = intdiv((int) date('n', $nowS) - 1, 3) + 1;
        for ($i = 0; $i < 8; $i++) {
            $out[sprintf('%d-Q%d', $year, $q)] = sprintf('%d Q%d', $year, $q);
            if (--$q === 0) {
                $q = 4;
                $year--;
            }
        }
        for ($y = (int) date('Y', $nowS); $y > (int) date('Y', $nowS) - 3; $y--) {
            $out[(string) $y] = (string) $y;
        }
        return $out;
    }

    private static function range(string $key, int $year, int $month, int $months): array
    {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $end  = date('Y-m-t', strtotime("$from +" . ($months - 1) . ' month'));
        return ['key' => $key, 'label' => str_replace('-Q', ' Q', $key), 'from' => $from . ' 00:00:00', 'to' => $end . ' 23:59:59'];
    }
}
