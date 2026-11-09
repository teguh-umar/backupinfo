<?php

namespace GlpiPlugin\Backupinfo\Veeam;

use GlpiPlugin\Backupinfo\Source\Target;

/**
 * Veeam /backupInfrastructure/repositories/states -> Target rows.
 * Scale-out (ExtendableRepository) parents are skipped; their extents are their own rows.
 */
final class TargetMapper
{
    /** @return list<array> Target::make() rows */
    public static function map(array $repoStates): array
    {
        $out = [];
        foreach ($repoStates as $rs) {
            if (!is_array($rs)) {
                continue;
            }
            $type = (string) ($rs['type'] ?? '');
            if (strcasecmp($type, 'ExtendableRepository') === 0) {
                continue;
            }
            $out[] = Target::make([
                'kind'   => self::kind($type),
                'name'   => trim((string) ($rs['name'] ?? '')),
                'type'   => $type,
                'node'   => trim((string) ($rs['hostName'] ?? '')),
                'total'  => self::gb($rs['capacityGB'] ?? null),
                'used'   => self::gb($rs['usedSpaceGB'] ?? null),
                'free'   => self::gb($rs['freeGB'] ?? null),
                'online' => self::online($rs['isOnline'] ?? null),
                'jobs'   => '',
                'detail' => self::detail($rs),
            ]);
        }
        return $out;
    }

    private static function kind(string $type): string
    {
        $t = mb_strtolower($type);
        // disk types
        if (in_array($t, ['winlocal', 'linuxlocal', 'linuxhardened'], true) || str_contains($t, 'dedupe')) {
            return 'disk';
        }
        // nas types
        if (in_array($t, ['smb', 'nfs'], true)) {
            return 'nas';
        }
        // cloud types
        $cloud = ['amazons3', 'azure', 's3compatible', 'googlecloud', 'ibmcloud',
            'wasabicloud', 'veamdatacloudvault', 'veamvault', 'eleveneleven'];
        foreach ($cloud as $p) {
            if (str_starts_with($t, $p)) {
                return 'cloud';
            }
        }
        return '';
    }

    private static function gb($v): string
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return '';
        }
        return trim((string) $v) . ' GB';
    }

    private static function online($v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        return (bool) $v ? 'yes' : 'no';
    }

    private static function detail(array $rs): string
    {
        $path = trim((string) ($rs['path'] ?? ''));
        if ((bool) ($rs['isOutOfDate'] ?? false)) {
            $path .= ' · out of date';
        }
        return $path;
    }
}
