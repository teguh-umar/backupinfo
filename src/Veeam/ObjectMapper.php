<?php

namespace GlpiPlugin\Backupinfo\Veeam;

use GlpiPlugin\Backupinfo\Source\BackupObject;
use GlpiPlugin\Backupinfo\Source\Time;

/**
 * Veeam /backupObjects -> BackupObject. newestPoints() keeps the newest
 * parseable restore point per object name; map() builds BackupObject rows.
 */
final class ObjectMapper
{
    /**
     * @return array<string, array{time: int, backupId: string}> lower(name) => newest parseable point
     */
    public static function newestPoints(iterable $restorePoints): array
    {
        $out = [];
        foreach ($restorePoints as $rp) {
            if (!is_array($rp)) {
                continue;
            }
            $name = trim((string) ($rp['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $time = Time::epoch($rp['creationTime'] ?? null);
            if ($time === null) {
                continue;
            }
            $key = mb_strtolower($name);
            if (!isset($out[$key]) || $out[$key]['time'] < $time) {
                $out[$key] = ['time' => $time, 'backupId' => (string) ($rp['backupId'] ?? '')];
            }
        }
        return $out;
    }

    /** @return list<BackupObject> */
    public static function map(array $objects, array $newest, array $backupNames): array
    {
        $out = [];
        foreach ($objects as $obj) {
            if (!is_array($obj)) {
                continue;
            }
            $id   = trim((string) ($obj['id'] ?? ''));
            $name = trim((string) ($obj['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $type   = (string) ($obj['type'] ?? '');
            $kind   = self::kind($type);
            $key    = mb_strtolower($name);
            $np     = $newest[$key] ?? null;
            $rpc    = (int) ($obj['restorePointsCount'] ?? 0);
            $last   = null;
            if ($np !== null && $rpc > 0) {
                $last = $np['time'];
            }
            $jobName = '';
            if ($np !== null && isset($backupNames[$np['backupId']])) {
                $jobName = $backupNames[$np['backupId']];
            }
            $out[] = new BackupObject(
                'veeam:' . $id,
                $name,
                '',
                $kind,
                $jobName,
                $last,
                $rpc,
                '',
                false,
                false,
                null,
                null,
            );
        }
        return $out;
    }

    private static function kind(string $type): string
    {
        $t = mb_strtolower($type);
        if ($t === 'vm') {
            return 'vm';
        }
        if ($t === 'computer' || str_contains($t, 'agent')) {
            return 'agent';
        }
        return 'other';
    }
}
