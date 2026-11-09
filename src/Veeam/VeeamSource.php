<?php

namespace GlpiPlugin\Backupinfo\Veeam;

use GlpiPlugin\Backupinfo\Source\AuditSource;
use GlpiPlugin\Backupinfo\Source\AuditProbeableSource;
use GlpiPlugin\Backupinfo\Source\BackupObject;
use GlpiPlugin\Backupinfo\Source\BackupSource;
use GlpiPlugin\Backupinfo\Source\Event;
use GlpiPlugin\Backupinfo\Source\PartialFetch;
use GlpiPlugin\Backupinfo\Source\ProbeableSource;
use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Source\RunHistorySource;
use GlpiPlugin\Backupinfo\Source\Target;
use GlpiPlugin\Backupinfo\Source\Time;
use GlpiPlugin\Backupinfo\Probe;
use GlpiPlugin\Backupinfo\Veeam\ApiError;
use GlpiPlugin\Backupinfo\Veeam\Client;
use GlpiPlugin\Backupinfo\Veeam\EventMapper;
use GlpiPlugin\Backupinfo\Veeam\ObjectMapper;
use GlpiPlugin\Backupinfo\Veeam\RunMapper;
use GlpiPlugin\Backupinfo\Veeam\TargetMapper;

/**
 * Veeam Backup & Replication 13 source (0.5.0 preview). Read-only OAuth2 client.
 * Implements BackupSource, PartialFetch, RunHistorySource, AuditSource,
 * ProbeableSource and AuditProbeableSource.
 * Does NOT implement VerifyingSource (SureBackup over REST is unconfirmed).
 */
final class VeeamSource implements BackupSource, PartialFetch, RunHistorySource, AuditSource, ProbeableSource, AuditProbeableSource
{
    public const MAX_TASK_CALLS = 100;
    public const RUN_TYPES = ['BackupJob'];
    public const FORM_FIELDS = ['host', 'verify_tls', 'username', 'password', 'api_version'];
    public const DEFAULTS = ['auth_mode' => 'login', 'api_version' => '1.3-rev2'];
    /** Declared gaps per audit section. */
    public const GAPS = [
        'coverage' => 'the platform VM list (protected or not) is not read from Veeam in this version.',
        'restore' => 'SureBackup verifications are not imported; record restore tests by hand.',
        'activity' => 'Veeam REST has no job operation log, no system alarms (Veeam ONE only) and no login source IP. Four-eyes authorization events need the Security Administrator role and malware events the Incident API Operator role; without them these lists stay empty (see Probe).',
        'targets' => 'tape is not available from Veeam REST.',
    ];

    /** true when the last fetch() was truncated by maxRows */
    private bool $partial = false;
    /** Raw sessions keyed by id, filled by runs() for failureReasons(). */
    private array $sessionCache = [];

    public function __construct(private Client $client)
    {
    }

    public function lastFetchWasPartial(): bool
    {
        return $this->partial;
    }

    public function fetch(): array
    {
        $this->partial = false;
        $objects = [];
        try {
            $objects = $this->client->paged('/backupObjects', [], 5000, 5000);
            $this->partial = $this->client->lastTruncated;
        } finally {
            $this->client->logout();
        }

        $newest = [];
        try {
            $rps = $this->client->paged('/restorePoints', ['orderColumn' => 'CreationTime', 'orderAsc' => 'false'], 5000, 5000,
                fn(array $page): bool => $this->allNamesSeen($objects, $newest));
            $newest = ObjectMapper::newestPoints($rps);
        } catch (ApiError $e) {
            // not fatal: objects get lastBackup null
        }

        $backupNames = [];
        try {
            $backups = $this->client->paged('/backups', [], 5000, 5000);
            foreach ($backups as $b) {
                if (is_array($b) && ($bid = (string) ($b['backupId'] ?? '')) !== '') {
                    $backupNames[$bid] = (string) ($b['name'] ?? '');
                }
            }
        } catch (ApiError $e) {
            // not fatal: jobName stays ''
        }

        return ObjectMapper::map($objects, $newest, $backupNames);
    }

    private function allNamesSeen(array $objects, array &$newest): bool
    {
        foreach ($objects as $o) {
            if (!is_array($o) || ($n = trim((string) ($o['name'] ?? ''))) === '') {
                continue;
            }
            $key = mb_strtolower($n);
            if (!isset($newest[$key])) {
                return false;
            }
        }
        return true;
    }

    public function runs(callable $pageKnown, int $maxPages, int $pageSize = 100): array
    {
        $out = ['runs' => [], 'errors' => []];
        $stop = false;
        for ($page = 0; $page < $maxPages && !$stop; $page++) {
            $skip = $page * $pageSize;
            try {
                $pageRes = $this->client->paged('/sessions', ['typeFilter' => implode(',', self::RUN_TYPES)], $pageSize, $pageSize,
                    fn(array $batch): bool => $this->pageKnown($batch, $pageKnown));
                $mapped = [];
                foreach ($pageRes as $s) {
                    if (!is_array($s)) {
                        continue;
                    }
                    $this->sessionCache[(string) ($s['id'] ?? '')] = $s;
                    $run = RunMapper::map($s);
                    if ($run !== null) {
                        $mapped[] = $run;
                    }
                }
                $out['runs'] = array_merge($out['runs'], $mapped);
                // pageKnown decides: a page with only running sessions gives [] -> not known
                $ids = array_map(fn($s) => (string) ($s['id'] ?? ''), array_filter($pageRes, 'is_array'));
                if ($pageKnown($ids)) {
                    $stop = true;
                }
            } catch (ApiError $e) {
                $out['errors'][] = $e->getMessage();
                $stop = true;
            }
        }
        return $out;
    }

    private function pageKnown(array $ids, callable $pageKnown): bool
    {
        return $pageKnown($ids);
    }

    public function runItems(string $historyUuid): array
    {
        if (isset($this->sessionCache[$historyUuid])) {
            $tasks = $this->client->paged('/sessions/' . rawurlencode($historyUuid) . '/taskSessions', [], 50, 50);
        } else {
            try {
                $tasks = $this->client->paged('/sessions/' . rawurlencode($historyUuid) . '/taskSessions', [], 50, 50);
            } catch (ApiError $e) {
                return ['items' => [], 'error' => $e->getMessage()];
            }
        }
        $items = [];
        foreach ($tasks as $t) {
            if (!is_array($t)) {
                continue;
            }
            $item = RunMapper::item($t);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return ['items' => $items, 'error' => ''];
    }

    public function failureReasons(array $failedRuns): array
    {
        $reasons = [];
        $error = '';
        $count = 0;
        usort($failedRuns, fn(Run $a, Run $b) => strcmp($b->finish ?? $a->start ?? '', $a->finish ?? $b->start ?? ''));
        foreach ($failedRuns as $run) {
            if ($count >= self::MAX_TASK_CALLS) {
                break;
            }
            $uuid = $run->historyUuid;
            if (!isset($this->sessionCache[$uuid])) {
                try {
                    $tasks = $this->client->paged('/sessions/' . rawurlencode($uuid) . '/taskSessions', [], 50, 50);
                    foreach ($tasks as $t) {
                        if (is_array($t)) {
                            $this->sessionCache[(string) ($t['id'] ?? '')] = $t;
                        }
                    }
                } catch (ApiError $e) {
                    if ($error === '') {
                        $error = $e->getMessage();
                    }
                    break; // first refused call stops further reads
                }
            }
            $tasks = [];
            foreach ($this->sessionCache as $cached) {
                if (is_array($cached) && (string) ($cached['id'] ?? '') === $uuid) {
                    $tasks[] = $cached;
                }
            }
            $reasons[$uuid] = RunMapper::reason($tasks, $this->sessionCache[$uuid] ?? []);
            $count++;
        }
        return ['reasons' => $reasons, 'error' => $error];
    }

    public function copyRuns(string $from, string $to): array
    {
        $out = [];
        try {
            $jobs = $this->client->paged('/sessions', ['typeFilter' => 'BackupCopyJob'], 5000, 5000,
                fn(array $batch): bool => false);
            foreach ($jobs as $job) {
                if (!is_array($job)) {
                    continue;
                }
                $ct = Time::local($job['creationTime'] ?? null);
                if ($ct === null || $ct < Time::epoch($from) || $ct > Time::epoch($to)) {
                    continue;
                }
                try {
                    $tasks = $this->client->paged('/sessions/' . rawurlencode((string) ($job['id'] ?? '')) . '/taskSessions', [], 50, 50);
                } catch (ApiError $e) {
                    continue;
                }
                foreach ($tasks as $t) {
                    if (!is_array($t)) {
                        continue;
                    }
                    $item = RunMapper::item($t);
                    if ($item === null) {
                        continue;
                    }
                    $out[] = EventMapper::copy($job, $item);
                }
            }
        } catch (ApiError $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }
        return ['rows' => $out, 'error' => ''];
    }

    public function systemLogs(string $from, string $to): array
    {
        return $this->securityEvents('/authorization/events', 'authorization', $from, $to);
    }

    public function systemAlarms(string $from, string $to): array
    {
        return $this->securityEvents('/malwareDetection/events', 'malware', $from, $to);
    }

    private function securityEvents(string $path, string $kind, string $from, string $to): array
    {
        try {
            $rows = $this->client->paged($path, [], 5000, 5000);
        } catch (ApiError $e) {
            if ($e->status === 403) {
                return ['rows' => [], 'error' => ''];
            }
            return ['rows' => [], 'error' => $e->getMessage()];
        }
        $out = [];
        $fromS = Time::epoch($from) ?? 0;
        $toS = Time::epoch($to) ?? PHP_INT_MAX;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $t = Time::local($row['creationTime'] ?? $row['detectionTimeUtc'] ?? null);
            if ($t === null) {
                continue;
            }
            $ts = Time::epoch($t);
            if ($ts === null || $ts < $fromS || $ts > $toS) {
                continue;
            }
            if ($kind === 'authorization') {
                $out[] = EventMapper::authorization($row);
            } else {
                $out[] = EventMapper::malware($row);
            }
        }
        return ['rows' => $out, 'error' => ''];
    }

    public function jobLogs(string $from, string $to): array
    {
        return ['rows' => [], 'error' => ''];
    }

    public function platformVms(): array
    {
        return ['rows' => [], 'error' => ''];
    }

    public function targets(): array
    {
        try {
            $states = $this->client->paged('/backupInfrastructure/repositories/states', [], 5000, 5000);
        } catch (ApiError $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }
        return ['rows' => TargetMapper::map($states), 'error' => ''];
    }

    /**
     * Probe: one page per endpoint, at most $limit rows each. The first raw row of each endpoint
     * is a status line keyed _check (OK / refused (role?) / unexpected shape / error).
     *
     * @return array{raw: array<string, list<array>>, objects: list<BackupObject>, errors: list<string>}
     */
    public function probe(int $limit, string $search = ''): array
    {
        $raw   = [];
        $errors = [];
        $params = ['limit' => $limit];
        if ($search !== '') {
            $params['nameFilter'] = '*' . $search . '*';
        }
        foreach (['/backupObjects', '/restorePoints', '/backups'] as $path) {
            list($r, $err) = $this->probeEndpoint($path, $params);
            $raw[$path] = $r;
            if ($err !== '') {
                $errors[] = $err;
            }
        }
        $sessions = [];
        list($sRaw, $sErr) = $this->probeEndpoint('/sessions', ['typeFilter' => 'BackupJob', 'limit' => $limit]);
        $raw['/sessions'] = $sRaw;
        if ($sErr !== '') {
            $errors[] = $sErr;
        } else {
            $sessions = $sRaw;
            // Shape check: every session must carry result.result
            foreach ($sessions as $s) {
                if (is_array($s)) {
                    $miss = Shape::missing($s, ['result.result']);
                    if ($miss !== []) {
                        $raw['/sessions'][0]['_check'] = 'unexpected shape: missing ' . implode(', ', $miss);
                        break;
                    }
                }
            }
        }
        $objects = ObjectMapper::map($sessions, [], []);
        return ['raw' => $raw, 'objects' => $objects, 'errors' => $errors];
    }

    /**
     * Probe audit: one page per audit endpoint.
     *
     * @return array{raw: array<string, list<array>>, errors: list<string>}
     */
    public function probeAudit(int $limit, string $search = ''): array
    {
        $raw   = [];
        $errors = [];
        $firstId = '';
        // First id comes from /sessions?typeFilter=BackupJob (the first page already fetched in probe(),
        // but here we read it standalone as the audit probe).
        list($sRaw, $sErr) = $this->probeEndpoint('/sessions', ['typeFilter' => 'BackupJob', 'limit' => $limit]);
        $raw['/sessions'] = $sRaw;
        if ($sErr !== '') {
            $errors[] = $sErr;
        } elseif (isset($sRaw[0]['id'])) {
            $firstId = (string) $sRaw[0]['id'];
        }
        list($cRaw, $cErr) = $this->probeEndpoint('/sessions', ['typeFilter' => 'BackupCopyJob', 'limit' => $limit]);
        $raw['/sessions'] = $cRaw;
        if ($cErr !== '') {
            $errors[] = $cErr;
        }
        if ($firstId !== '') {
            list($tRaw, $tErr) = $this->probeEndpoint('/sessions/' . rawurlencode($firstId) . '/taskSessions', [], $limit);
            $raw['/sessions/{id}/taskSessions'] = $tRaw;
            if ($tErr !== '') {
                $errors[] = $tErr;
            }
        }
        foreach (['/authorization/events', '/malwareDetection/events', '/backupInfrastructure/repositories/states'] as $path) {
            list($r, $err) = $this->probeEndpoint($path, [], $limit);
            $raw[$path] = $r;
            if ($err !== '') {
                $errors[] = $err;
            }
        }
        return ['raw' => $raw, 'errors' => $errors];
    }

    /**
     * Probe one endpoint: returns (raw rows with _check prepended, error line or '').
     */
    private function probeEndpoint(string $path, array $params, int $limit = 5000): array
    {
        $params = $params + ['limit' => $limit];
        try {
            $rows = $this->client->paged($path, $params, $limit, $limit);
            if ($rows === []) {
                $rows[0] = ['_check' => 'OK'];
            } else {
                array_unshift($rows, ['_check' => 'OK']);
            }
            return [array_map([Probe::class, 'redact'], $rows), ''];
        } catch (ApiError $e) {
            $check = $e->status === 403
                ? 'refused (role?): ' . $e->getMessage()
                : 'error: ' . $e->getMessage();
            return [[['_check' => $check]], $e->getMessage()];
        }
    }
}
