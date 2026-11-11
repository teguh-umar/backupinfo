<?php

namespace GlpiPlugin\Backupinfo;

use GlpiPlugin\Backupinfo\Matching\Matcher;
use GlpiPlugin\Backupinfo\Source\AuditProbeableSource;
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
    public static function run(array $connectionRow, callable $decrypt, Matcher $m, int $limit = self::LIMIT, ?callable $vmNames = null, string $search = ''): array
    {
        try {
            $source = Registry::create($connectionRow, $decrypt, $vmNames);
        } catch (\InvalidArgumentException $e) {
            return self::fromSource(null, $m, $limit, [$e->getMessage()]);
        }
        return self::fromSource($source, $m, $limit, [], $search);
    }

    /**
     * Read-only sample of the audit endpoints (failed runs, alarms, logs, verification,
     * copies) from one connection, credentials masked.
     *
     * @return array{raw: array<string, list<array>>, errors: list<string>}
     */
    public static function runAudit(array $connectionRow, callable $decrypt, int $limit = self::LIMIT, string $search = ''): array
    {
        try {
            $source = Registry::create($connectionRow, $decrypt, static fn(): array => []);
        } catch (\InvalidArgumentException $e) {
            return ['raw' => [], 'errors' => [$e->getMessage()]];
        }
        return self::fromAuditSource($source, $limit, $search);
    }

    /** Testable core of runAudit(). */
    public static function fromAuditSource(BackupSource $source, int $limit = self::LIMIT, string $search = ''): array
    {
        if (!$source instanceof AuditProbeableSource) {
            return ['raw' => [], 'errors' => ['This source type has no audit probe yet']];
        }
        try {
            $sample = $source->probeAudit($limit, $search);
        } catch (\Throwable $e) {
            return ['raw' => [], 'errors' => ['Probe failed: ' . $e->getMessage()]];
        }
        $raw    = [];
        foreach ($sample['raw'] as $label => $rows) {
            $raw[$label] = array_map([self::class, 'redact'], $rows);
        }
        return ['raw' => $raw, 'errors' => $sample['errors']];
    }

    /** Search keyword from the form: trimmed, at most 100 characters. */
    public static function cleanSearch(mixed $raw): string
    {
        return mb_substr(trim(is_scalar($raw) ? (string) $raw : ''), 0, 100);
    }

    /** Testable core of run(): any source, no Registry. */
    public static function fromSource(?BackupSource $source, Matcher $m, int $limit = self::LIMIT, array $errors = [], string $search = ''): array
    {
        $out = ['raw' => [], 'objects' => [], 'matches' => [], 'errors' => $errors];
        if ($source === null) {
            return $out;
        }
        if (!$source instanceof ProbeableSource) {
            $out['errors'][] = 'This source type has no probe yet';
            return $out;
        }

        try {
            $sample = $source->probe($limit, $search);
        } catch (\Throwable $e) {
            // 0.5.0-rc4: any error is a line on the page, never GLPI's error page. Sources put
            // method and path in messages, never credentials.
            $out['errors'][] = 'Probe failed: ' . $e->getMessage();
            return $out;
        }
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
