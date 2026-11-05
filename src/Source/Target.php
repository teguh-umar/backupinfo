<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * A backup target in the common shape every brand returns (0.3.0-rc6): where backups are written.
 * kind: disk | nas | cloud | tape | tape library. Capacities are the source's own text ("43.66TB").
 * online: yes | no | '' (unknown). jobs: the jobs writing to it, when the source says. Pure.
 */
final class Target
{
    private const TEXT = ['kind' => 32, 'name' => 255, 'type' => 128, 'node' => 255, 'total' => 32, 'used' => 32,
        'free' => 32, 'online' => 3, 'jobs' => 1000, 'detail' => 255];

    /** @return array<string, string> */
    public static function make(array $fields): array
    {
        $out = [];
        foreach (self::TEXT as $key => $max) {
            $out[$key] = mb_substr(trim((string) ($fields[$key] ?? '')), 0, $max);
        }
        return $out;
    }
}
