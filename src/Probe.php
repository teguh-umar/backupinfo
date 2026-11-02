<?php

namespace GlpiPlugin\Backupinfo;

use GlpiPlugin\Backupinfo\Matching\Matcher;
use GlpiPlugin\Backupinfo\Source\BackupSource;
use GlpiPlugin\Backupinfo\Source\ProbeableSource;
use GlpiPlugin\Backupinfo\Source\Registry;

/**
 * Read-only sample from one connection for the Probe page: raw rows, the plugin's mapping
 * and the match result. Never writes (no object store, no Fields, no connection status).
 */
final class Probe
{
    public const LIMIT = 5;

    private const SECRET_KEY = '/pass|secret|token|api[_-]?key|auth|cookie|session/i';

    /** Mask the value of every key that looks like a credential, at any depth. */
    public static function redact(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1) {
                $out[$key] = '***';
            } elseif (is_array($value)) {
                $out[$key] = self::redact($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $connectionRow
     * @param callable(string): string $decrypt
     * @param (callable(): list<string>)|null $vmNames Computer names (job-history mapping)
     * @return array{raw: array<string, list<array>>, objects: list<Source\BackupObject>, matches: list<array>, errors: list<string>}
     */
    public static function run(array $connectionRow, callable $decrypt, Matcher $m, int $limit = self::LIMIT, ?callable $vmNames = null): array
    {
        try {
            $source = Registry::create($connectionRow, $decrypt, $vmNames);
        } catch (\InvalidArgumentException $e) {
            return self::fromSource(null, $m, $limit, [$e->getMessage()]);
        }
        return self::fromSource($source, $m, $limit);
    }

    /** Testable core of run(): any source, no Registry. */
    public static function fromSource(?BackupSource $source, Matcher $m, int $limit = self::LIMIT, array $errors = []): array
    {
        $out = ['raw' => [], 'objects' => [], 'matches' => [], 'errors' => $errors];
        if ($source === null) {
            return $out;
        }
        if (!$source instanceof ProbeableSource) {
            $out['errors'][] = 'This source type has no probe yet';
            return $out;
        }

        $sample = $source->probe($limit);
        foreach ($sample['raw'] as $endpoint => $rows) {
            $out['raw'][$endpoint] = array_map([self::class, 'redact'], $rows);
        }
        $out['objects'] = $sample['objects'];
        $out['errors']  = array_merge($out['errors'], $sample['errors']);
        foreach ($sample['objects'] as $i => $object) {
            if ($object->kind === 'vm') {
                $out['matches'][$i] = $m->match($object->name, $object->ip, 'hybrid_name');
            }
        }
        return $out;
    }
}
