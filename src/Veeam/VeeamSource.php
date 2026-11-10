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

    /** Probe shape check: endpoint => dot paths the mapper reads on the first data row. */
    public const PROBE_REQUIRED = [
        '/backupObjects' => ['id', 'name', 'restorePointsCount'],
        '/restorePoints' => ['name', 'creationTime', 'backupId'],
        '/backups' => ['id', 'name'],
        '/sessions' => ['id', 'name', 'state', 'creationTime', 'result.result'],
        '/sessions/{id}/taskSessions' => ['name', 'result.result'],
        '/authorization/events' => ['id', 'creationTime', 'state'],
        '/malwareDetection/events' => ['id', 'detectionTimeUtc', 'severity'],
        '/backupInfrastructure/repositories/states' => ['name', 'type', 'capacityGB', 'freeGB', 'usedSpaceGB', 'isOnline'],
    ];

    /** true when the last fetch() was truncated by maxRows */
    private bool $partial = false;
    /** Raw sessions keyed by session id, filled by runs(). */
    private array $sessions = [];
    /** Raw task sessions keyed by session id, filled by failureReasons()/runItems(). */
    private array $tasks = [];

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
        $newest = [];
        $backupNames = [];
        try {
            // 1. Backup objects.
            $objects = $this->client->paged('/backupObjects', [], 200, 5000);
            $this->partial = $this->client->lastTruncated;
            // 2. Newest restore point per object name. The stop callback folds each page into
            // $newest so paging can stop as soon as every object name has a point. $rps is folded
            // again after paged() returns because paged() may short-circuit on skip >= total
            // before invoking the stop callback on the final page.
            $stop = function (array $page) use (&$newest): bool {
                foreach ($page as $rp) {
                    if (!is_array($rp)) {
                        continue;
                    }
                    $n = trim((string) ($rp['name'] ?? ''));
                    if ($n === '') {
                        continue;
                    }
                    $t = Time::epoch($rp['creationTime'] ?? null);
                    if ($t === null) {
                        continue;
                    }
                    $key = mb_strtolower($n);
                    if (!isset($newest[$key]) || $newest[$key]['time'] < $t) {
                        $newest[$key] = ['time' => $t, 'backupId' => (string) ($rp['backupId'] ?? '')];
                    }
                }
                return self::allNamesSeen($objects, $newest);
            };
            try {
                $rps = $this->client->paged('/restorePoints', ['orderColumn' => 'CreationTime', 'orderAsc' => 'false'], 200, 5000, $stop);
                foreach ($rps as $rp) {
                    if (!is_array($rp)) {
                        continue;
                    }
                    $n = trim((string) ($rp['name'] ?? ''));
                    if ($n === '') {
                        continue;
                    }
                    $t = Time::epoch($rp['creationTime'] ?? null);
                    if ($t === null) {
                        continue;
                    }
                    $key = mb_strtolower($n);
                    if (!isset($newest[$key]) || $newest[$key]['time'] < $t) {
                        $newest[$key] = ['time' => $t, 'backupId' => (string) ($rp['backupId'] ?? '')];
                    }
                }
            } catch (ApiError $e) {
                // not fatal: objects get lastBackup null
            }
            // 3. Backup jobs -> job names keyed by backup id.
            try {
                $backups = $this->client->paged('/backups', [], 200, 5000);
                foreach ($backups as $b) {
                    if (is_array($b) && ($bid = (string) ($b['id'] ?? '')) !== '') {
                        $backupNames[$bid] = (string) ($b['name'] ?? '');
                    }
                }
            } catch (ApiError $e) {
                // not fatal: jobName stays ''
            }
        } finally {
            $this->client->logout();
        }
        return ObjectMapper::map($objects, $newest, $backupNames);
    }

    private function allNamesSeen(array $objects, array $newest): bool
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
        $skip = 0;
        for ($page = 0; $page < $maxPages; $page++) {
            try {
                $res = $this->client->get('/sessions', [
                    'typeFilter' => implode(',', self::RUN_TYPES),
                    'orderColumn' => 'CreationTime',
                    'orderAsc' => 'false',
                    'skip' => $skip,
                    'limit' => $pageSize,
                ]);
            } catch (ApiError $e) {
                $out['errors'][] = $e->getMessage();
                break;
            }
            $data = is_array($res['data'] ?? null) ? $res['data'] : [];
            $pagination = is_array($res['pagination'] ?? null) ? $res['pagination'] : [];
            $total = (int) ($pagination['total'] ?? 0);
            $mappedIds = [];
            foreach ($data as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $this->sessions[(string) ($s['id'] ?? '')] = $s;
                $run = RunMapper::map($s);
                if ($run !== null) {
                    $out['runs'][] = $run;
                    $mappedIds[] = $run->historyUuid;
                }
            }
            $skip += count($data);
            if ($pageKnown($mappedIds) || $data === [] || $skip >= $total) {
                break;
            }
        }
        return $out;
    }

    public function runItems(string $historyUuid): array
    {
        if (isset($this->tasks[$historyUuid])) {
            $tasks = $this->tasks[$historyUuid];
        } else {
            try {
                $rows = $this->client->paged('/sessions/' . rawurlencode($historyUuid) . '/taskSessions', [], 50, 50);
            } catch (ApiError $e) {
                return ['items' => [], 'error' => $e->getMessage()];
            }
            $tasks = [];
            foreach ($rows as $t) {
                if (is_array($t)) {
                    $this->tasks[$historyUuid][] = $t;
                    $tasks[] = $t;
                }
            }
        }
        $items = [];
        foreach ($tasks as $t) {
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
        $readStopped = false;
        usort($failedRuns, fn(Run $a, Run $b): int => self::compareStart($a, $b));
        foreach ($failedRuns as $run) {
            $uuid = $run->historyUuid;
            if (!isset($this->tasks[$uuid]) && !$readStopped && $count < self::MAX_TASK_CALLS) {
                try {
                    $rows = $this->client->paged('/sessions/' . rawurlencode($uuid) . '/taskSessions', [], 50, 50);
                } catch (ApiError $e) {
                    if ($error === '') {
                        $error = $e->getMessage();
                    }
                    $readStopped = true; // first refused call stops further reads
                }
                if (!$readStopped) {
                    foreach ($rows as $t) {
                        if (is_array($t)) {
                            $this->tasks[$uuid][] = $t;
                        }
                    }
                    $count++;
                }
            }
            $reasons[$uuid] = RunMapper::reason($this->tasks[$uuid] ?? [], $this->sessions[$uuid] ?? []);
        }
        return ['reasons' => $reasons, 'error' => $error];
    }

    /** Newest start first; a null start sorts last (unsure is never newest). */
    private static function compareStart(Run $a, Run $b): int
    {
        $sa = $a->start ?? null;
        $sb = $b->start ?? null;
        if ($sa === null && $sb === null) {
            return 0;
        }
        if ($sa === null) {
            return 1;
        }
        if ($sb === null) {
            return -1;
        }
        return strcmp($sb, $sa);
    }

    /**
     * $from as ISO 8601 with offset, for the createdAfterFilter query param.
     * Falls back to $from as-is when it cannot be parsed.
     */
    private static function isoAfter(string $from): string
    {
        try {
            $dt = new \DateTimeImmutable($from, new \DateTimeZone(date_default_timezone_get()));
            return $dt->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return $from;
        }
    }

    public function copyRuns(string $from, string $to): array
    {
        $out = [];
        $taskCalls = 0;
        $taskCallCap = 50;
        $fromEpoch = Time::epoch($from) ?? 0;
        $toEpoch = Time::epoch($to) ?? PHP_INT_MAX;
        $fromIso = self::isoAfter($from);
        // Newest first, ordinary page size, stop once a page reaches before $from.
        try {
            $jobs = $this->client->paged('/sessions', [
                'typeFilter' => 'BackupCopyJob',
                'orderColumn' => 'CreationTime',
                'orderAsc' => 'false',
                'createdAfterFilter' => $fromIso,
            ], 200, 5000,
                function (array $batch) use ($fromEpoch): bool {
                    $oldest = null;
                    foreach ($batch as $job) {
                        if (!is_array($job)) {
                            continue;
                        }
                        $t = Time::epoch($job['creationTime'] ?? null);
                        if ($t === null) {
                            continue;
                        }
                        if ($oldest === null || $t < $oldest) {
                            $oldest = $t;
                        }
                    }
                    return $oldest !== null && $oldest < $fromEpoch;
                });
            foreach ($jobs as $job) {
                if (!is_array($job)) {
                    continue;
                }
                $ct = Time::epoch($job['creationTime'] ?? null);
                if ($ct === null || $ct < $fromEpoch || $ct > $toEpoch) {
                    continue; // outside the window: skip, do not read its task sessions
                }
                if ($taskCalls >= $taskCallCap) {
                    break; // cap the per-sync task-session reads
                }
                try {
                    $tasks = $this->client->paged('/sessions/' . rawurlencode((string) ($job['id'] ?? '')) . '/taskSessions', [], 50, 50);
                } catch (ApiError $e) {
                    continue;
                }
                $taskCalls++;
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
            list($r, $err) = $this->probeEndpoint($path, $params, $limit, self::PROBE_REQUIRED[$path]);
            $raw[$path] = $r;
            if ($err !== '') {
                $errors[] = $err;
            }
        }
        // Objects come from /backupObjects (with /restorePoints and /backups), like fetch(),
        // stripping the _check status rows first.
        $objects = ObjectMapper::map(
            array_filter($raw['/backupObjects'] ?? [], fn($r) => is_array($r) && !isset($r['_check'])),
            ObjectMapper::newestPoints(array_filter($raw['/restorePoints'] ?? [], fn($r) => is_array($r))),
            array_column(array_filter($raw['/backups'] ?? [], fn($r) => is_array($r)), 'name', 'id')
        );
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
        $params = ['limit' => $limit];
        if ($search !== '') {
            $params['nameFilter'] = '*' . $search . '*';
        }
        // Sessions: separate keys for backup jobs and copy jobs.
        list($sRaw, $sErr) = $this->probeEndpoint('/sessions', ['typeFilter' => 'BackupJob'] + $params, $limit, self::PROBE_REQUIRED['/sessions']);
        $raw['/sessions (BackupJob)'] = $sRaw;
        if ($sErr !== '') {
            $errors[] = $sErr;
        }
        list($cRaw, $cErr) = $this->probeEndpoint('/sessions', ['typeFilter' => 'BackupCopyJob'] + $params, $limit, self::PROBE_REQUIRED['/sessions']);
        $raw['/sessions (BackupCopyJob)'] = $cRaw;
        if ($cErr !== '') {
            $errors[] = $cErr;
        }
        // First session id: the first row that carries an id (not the _check status row).
        $firstId = '';
        foreach (($raw['/sessions (BackupJob)'] ?? []) as $row) {
            if (is_array($row) && isset($row['id']) && $row['id'] !== '') {
                $firstId = (string) $row['id'];
                break;
            }
        }
        if ($firstId !== '') {
            list($tRaw, $tErr) = $this->probeEndpoint('/sessions/' . rawurlencode($firstId) . '/taskSessions', [], $limit, self::PROBE_REQUIRED['/sessions/{id}/taskSessions']);
            $raw['/sessions/{id}/taskSessions'] = $tRaw;
            if ($tErr !== '') {
                $errors[] = $tErr;
            }
        }
        foreach (['/authorization/events', '/malwareDetection/events', '/backupInfrastructure/repositories/states'] as $path) {
            list($r, $err) = $this->probeEndpoint($path, [], $limit, self::PROBE_REQUIRED[$path]);
            $raw[$path] = $r;
            if ($err !== '') {
                $errors[] = $err;
            }
        }
        return ['raw' => $raw, 'errors' => $errors];
    }

    /**
     * Probe one endpoint: returns (raw rows with _check prepended, error line or '').
     * When $required (dot paths) is given, the first data row is checked and a missing
     * path yields an 'unexpected shape: missing ...' _check line.
     */
    private function probeEndpoint(string $path, array $params, int $limit = 5000, ?array $required = null): array
    {
        $params = $params + ['limit' => $limit];
        try {
            $rows = $this->client->paged($path, $params, $limit, $limit);
            if ($rows === []) {
                $rows[0] = ['_check' => 'OK'];
            } else {
                array_unshift($rows, ['_check' => 'OK']);
                if ($required !== null && isset($rows[1])) {
                    $miss = Shape::missing($rows[1], $required);
                    if ($miss !== []) {
                        $rows[0]['_check'] = 'unexpected shape: missing ' . implode(', ', $miss);
                    }
                }
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
