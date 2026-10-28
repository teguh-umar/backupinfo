<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

use GlpiPlugin\Backupinfo\Source\BackupObject;

/** Maps /backup_data/items/list rows to BackupObject (port of the Orchestrator's vinchin_item_rows). */
final class ItemMapper
{
    /** Vinchin renders local-time strings in the server's zone. */
    private const SERVER_TZ = 'Asia/Jakarta';

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<BackupObject>
     */
    public static function map(array $rows): array
    {
        /** @var array<string, array<string,mixed>> $vms */
        $vms = [];
        $others = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = self::s($row['item_name'] ?? '') !== '' ? self::s($row['item_name']) : self::s($row['title'] ?? '');
            if (self::isVm($row)) {
                [$name, $ip] = self::splitName($raw);
                if ($name === '') {
                    continue;
                }
                if ($ip === '') {
                    $ip = self::s($row['ip'] ?? '');
                }
                $time = self::parseTime($row['last_time'] ?? null);
                $key = strtolower($name);
                if (!isset($vms[$key])) {
                    $vms[$key] = ['name' => $name, 'ip' => $ip, 'time' => $time, 'points' => 0, 'jobs' => [],
                        'size' => '', 'abnormal' => false, 'deleted' => false];
                }
                $vm = &$vms[$key];
                if ($vm['ip'] === '') {
                    $vm['ip'] = $ip;
                }
                if ($time !== null && ($vm['time'] === null || $time >= $vm['time'])) {
                    $vm['time'] = $time;
                    if (self::s($row['data_size'] ?? '') !== '') {
                        $vm['size'] = self::s($row['data_size']);
                    }
                } elseif ($vm['size'] === '') {
                    $vm['size'] = self::s($row['data_size'] ?? '');
                }
                $vm['points'] += (int) ($row['point_num'] ?? 0);
                $job = self::s($row['task_name'] ?? '');
                if ($job !== '' && !in_array($job, $vm['jobs'], true)) {
                    $vm['jobs'][] = $job;
                }
                $vm['abnormal'] = $vm['abnormal'] || self::flag($row['abnormal_chain_flag'] ?? false);
                $vm['deleted'] = $vm['deleted'] || self::flag($row['src_data_deleted_flag'] ?? false);
                unset($vm);
                continue;
            }

            $uuid = self::s($row['item_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            [$base, $ip] = self::splitName($raw);
            if ($ip === '') {
                $ip = self::s($row['ip'] ?? '');
            }
            $where = self::s($row['instance_name'] ?? '');
            $others[] = new BackupObject(
                $uuid,
                $where !== '' ? $base . ' @ ' . $where : $base,
                $ip,
                self::kind($row),
                self::s($row['task_name'] ?? ''),
                self::parseTime($row['last_time'] ?? null),
                (int) ($row['point_num'] ?? 0),
                self::s($row['data_size'] ?? ''),
                self::flag($row['abnormal_chain_flag'] ?? false),
                self::flag($row['src_data_deleted_flag'] ?? false)
            );
        }

        $out = [];
        foreach ($vms as $vm) {
            $out[] = new BackupObject($vm['name'], $vm['name'], $vm['ip'], 'vm', implode(', ', $vm['jobs']),
                $vm['time'], $vm['points'], $vm['size'], $vm['abnormal'], $vm['deleted']);
        }
        return array_merge($out, $others);
    }

    /**
     * Epoch seconds (UTC) from epoch seconds, epoch milliseconds, or a Vinchin local-time string.
     */
    public static function parseTime(mixed $v): ?int
    {
        if ($v === null || is_bool($v) || is_array($v)) {
            return null;
        }
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '') {
                return null;
            }
            if (!ctype_digit($v)) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
                    return null;
                }
                try {
                    $dt = new \DateTimeImmutable($v, new \DateTimeZone(self::SERVER_TZ));
                } catch (\Exception) {
                    return null;
                }
                return $dt->getTimestamp();
            }
            $v = (int) $v;
        }
        if (is_float($v)) {
            $v = (int) $v;
        }
        if (!is_int($v) || $v <= 0) {
            return null;
        }
        return $v >= 100000000000 ? intdiv($v, 1000) : $v;
    }

    /** @return array{0:string,1:string} name without the trailing "(ip)", and that ip */
    private static function splitName(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/^(.*?)\s*\(\s*(\d{1,3}(?:\.\d{1,3}){3})\s*\)\s*$/', $raw, $m)) {
            return [trim($m[1]), $m[2]];
        }
        return [$raw, ''];
    }

    private static function isVm(array $row): bool
    {
        return strcasecmp(self::s($row['module_type_des'] ?? ''), 'Virtualization') === 0;
    }

    private static function kind(array $row): string
    {
        if (self::isVm($row)) {
            return 'vm';
        }
        $hay = strtolower(self::s($row['module_type_des'] ?? '') . ' ' . self::s($row['sub_type_des'] ?? '') . ' ' . self::s($row['task_type_des'] ?? ''));
        if (preg_match('/database|\bdb\b|sql|oracle|mysql|postgres|mariadb|mongo/', $hay)) {
            return 'database';
        }
        if (preg_match('/\bfile|nas\b|cifs|nfs\b/', $hay)) {
            return 'file';
        }
        if (preg_match('/agent|physical|host/', $hay)) {
            return 'agent';
        }
        return 'other';
    }

    private static function flag(mixed $v): bool
    {
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'yes'], true);
        }
        return (bool) $v;
    }

    private static function s(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
