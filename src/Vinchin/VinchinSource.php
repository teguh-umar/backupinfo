<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

use GlpiPlugin\Backupinfo\Source\AuditProbeableSource;
use GlpiPlugin\Backupinfo\Source\BackupObject;
use GlpiPlugin\Backupinfo\Source\BackupSource;
use GlpiPlugin\Backupinfo\Source\PartialFetch;
use GlpiPlugin\Backupinfo\Source\ProbeableSource;
use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Source\RunHistorySource;

final class VinchinSource implements BackupSource, ProbeableSource, AuditProbeableSource, PartialFetch, RunHistorySource
{
    /** true when the last fetch() came from the job-history fallback (recent VM jobs only) */
    private bool $partial = false;

    /** @var list<string> */
    private array $scheduleErrors = [];

    /** login mode: true once this instance holds a token */
    private bool $loggedIn = false;

    private const ITEMS_PATH   = '/backup_data/items/list';
    private const HISTORY_PATH = '/jobs/history';
    private const JOBS_PATH    = '/jobs';

    /** Body of the console's Backup Object menu with no filter (vinchin-openapi.yaml). */
    private const ITEMS_FILTER = [
        'search' => '',
        'sort' => 'item_name',
        'order' => 'desc',
        'module_type' => [],
        'storage_uuid' => [],
        'task_type' => [],
        'operation_status' => [],
        'status' => [],
        'sub_module_type' => [],
    ];

    /** @var callable(): list<string> */
    private $vmNames;

    /**
     * @param bool $useLogin true = username/password mode, login() runs before fetching
     * @param callable(): list<string> $vmNames Computer names used by the job-history fallback
     */
    public function __construct(private Client $client, private bool $useLogin, callable $vmNames)
    {
        $this->vmNames = $vmNames;
    }

    public function lastFetchWasPartial(): bool
    {
        return $this->partial;
    }

    public function fetch(): array
    {
        $this->partial = false;
        if ($this->useLogin) {
            $this->client->login();
            $this->loggedIn = true;
        }

        try {
            $rows = $this->client->postPaged(self::ITEMS_PATH, self::ITEMS_FILTER);
            return $this->withSchedules(ItemMapper::map($rows));
        } catch (\RuntimeException $itemsError) {
            // Backup Object refused: fall back to the job history below.
        }

        $names = array_values(($this->vmNames)());
        if ($names === []) {
            throw new \RuntimeException('Vinchin items list failed (' . $itemsError->getMessage() . ') and the job history fallback needs Computer names, but there are none', 0, $itemsError);
        }

        $params = ['search' => '', 'sort' => 'start_time', 'order' => 'desc'];
        try {
            $rows = $this->client->getPaged('/jobs/history', $params);
        } catch (\RuntimeException) {
            unset($params['sort']);
            try {
                $rows = $this->client->getPaged('/jobs/history', $params);
            } catch (\RuntimeException $historyError) {
                throw new \RuntimeException('Vinchin items list failed (' . $itemsError->getMessage() . '); job history also failed (' . $historyError->getMessage() . ')', 0, $itemsError);
            }
        }

        $this->partial = true;
        return $this->withSchedules(JobHistoryMapper::map($rows, $names, time()));
    }

    /** Errors of the last fetch()'s schedule lookup (objects then use the global RPO). */
    public function scheduleErrors(): array
    {
        return $this->scheduleErrors;
    }

    /**
     * Sets each object's schedule interval from its jobs' time_strategy. Never throws: a job
     * whose schedule cannot be read stays unknown, and the global RPO applies to it.
     *
     * @param list<BackupObject> $objects
     * @return list<BackupObject>
     */
    private function withSchedules(array $objects): array
    {
        $s = $this->schedules('', 100, 5000, null, self::wantedJobs($objects));
        $this->scheduleErrors = $s['errors'];
        return array_map(static fn(BackupObject $o): BackupObject => $o->withSchedule(ScheduleMapper::forObject($o->jobName, $s['byJob'])), $objects);
    }

    /**
     * Lowercased job names the objects name ("A, B" split, and the whole text).
     *
     * @param list<BackupObject> $objects
     * @return array<string, true>
     */
    private static function wantedJobs(array $objects): array
    {
        $wanted = [];
        foreach ($objects as $o) {
            foreach (explode(',', mb_strtolower($o->jobName)) as $name) {
                if (trim($name) !== '') {
                    $wanted[trim($name)] = true;
                }
            }
            if (trim($o->jobName) !== '') {
                $wanted[mb_strtolower(trim($o->jobName))] = true;
            }
        }
        return $wanted;
    }

    /**
     * GET /jobs, then GET /jobs/{ID} for each listed job ($wanted = lowercased names to look
     * up, null = every listed job). Never throws.
     *
     * @param array<string, true>|null $wanted
     * @return array{jobs: list<array>, details: list<array>, byJob: array<string, int>, errors: list<string>, listed: bool}
     */
    private function schedules(string $search, int $page, int $max, ?callable $stop, ?array $wanted): array
    {
        $out = ['jobs' => [], 'details' => [], 'byJob' => [], 'errors' => [], 'listed' => false];
        try {
            $out['jobs']   = $this->client->getPaged(self::JOBS_PATH, ['search' => $search], $page, $max, $stop);
            $out['listed'] = true;
        } catch (\RuntimeException $e) {
            $out['errors'][] = $e->getMessage();
            return $out;
        }

        foreach ($out['jobs'] as $job) {
            $uuid = is_array($job) ? (string) ($job['job_uuid'] ?? '') : '';
            $name = is_array($job) ? trim((string) ($job['job_name'] ?? '')) : '';
            if ($uuid === '' || ($wanted !== null && !isset($wanted[mb_strtolower($name)]))) {
                continue;
            }
            try {
                $data = $this->client->get(self::JOBS_PATH . '/' . rawurlencode($uuid));
                $data = is_array($data) ? $data : [];
                $out['details'][] = ['job_uuid' => $uuid, 'job_name' => $name] + $data;
                $hours = ScheduleMapper::intervalHours($data);
                $kind  = (string) ($data['time_strategy_backup_type'] ?? 'strategy');
                if ($hours === null && $kind !== 'strategy') {
                    // One-time or manual job: no schedule by design, the global RPO applies.
                    $out['errors'][] = sprintf('job %s: %s job, no schedule (global RPO used)', $name, $kind);
                } elseif ($hours === null) {
                    // Reported, never guessed: this job's objects use the global RPO.
                    $out['errors'][] = sprintf('job %s: schedule not understood (time_strategy %s)', $name, json_encode($data['time_strategy'] ?? null));
                } elseif ($name !== '') {
                    $out['byJob'][mb_strtolower($name)] = $hours;
                }
            } catch (\RuntimeException $e) {
                $out['errors'][] = $e->getMessage();
            }
        }
        return $out;
    }

    public function probe(int $limit, string $search = ''): array
    {
        $out = ['raw' => [], 'objects' => [], 'errors' => []];

        if ($this->useLogin) {
            try {
                $this->client->login();
            } catch (\RuntimeException $e) {
                $out['errors'][] = 'login: ' . $e->getMessage();
                return $out;
            }
        }

        // A sample is one page: stop after the first request, whatever 'total' says
        $firstPageOnly = static fn(): bool => true;
        try {
            $rows = $this->client->postPaged(self::ITEMS_PATH, ['search' => $search] + self::ITEMS_FILTER, $limit, $limit, $firstPageOnly);
            $out['raw'][self::ITEMS_PATH] = $rows;
            array_push($out['objects'], ...ItemMapper::map($rows));
        } catch (\RuntimeException $e) {
            $out['errors'][] = $e->getMessage();
        }

        // Newest first, as the fetch() fallback asks; without sort Vinchin returns the oldest
        // rows (VM sample 2026-11-02). Some versions refuse sort: retry once without it.
        $params = ['search' => $search, 'sort' => 'start_time', 'order' => 'desc'];
        try {
            try {
                $rows = $this->client->getPaged(self::HISTORY_PATH, $params, $limit, $limit, $firstPageOnly);
            } catch (\RuntimeException) {
                unset($params['sort']);
                $rows = $this->client->getPaged(self::HISTORY_PATH, $params, $limit, $limit, $firstPageOnly);
            }
            $out['raw'][self::HISTORY_PATH] = $rows;
            array_push($out['objects'], ...JobHistoryMapper::map($rows, array_values(($this->vmNames)()), time()));
        } catch (\RuntimeException $e) {
            $out['errors'][] = $e->getMessage();
        }

        // Job schedules: the job list, then one detail call per job for its time_strategy
        // (type 1 daily, 2 weekly, 3 monthly; frequency; days) and next_time. Job names do not
        // follow the real schedule (user, 2026-11-02); the RPO per object comes from here.
        // With sampled objects: the whole job list, details only for their jobs, as fetch()
        // does; one page of the list showed every row as "global" (VM 2026-11-03). Without
        // objects (e.g. a job that has no backup yet): one page of jobs and their details.
        $wanted = self::wantedJobs($out['objects']);
        $s = $wanted === []
            ? $this->schedules($search, $limit, $limit, $firstPageOnly, null)
            : $this->schedules('', 100, 5000, null, $wanted); // a VM name need not be in its job name
        if ($s['listed']) {
            $out['raw'][self::JOBS_PATH] = $wanted === [] ? $s['jobs'] : array_values(array_filter(
                $s['jobs'],
                static fn($job): bool => is_array($job) && isset($wanted[mb_strtolower(trim((string) ($job['job_name'] ?? '')))])
            ));
            $out['raw'][self::JOBS_PATH . '/{ID}'] = $s['details'];
        }
        array_push($out['errors'], ...$s['errors']);
        $out['objects'] = array_map(static fn(BackupObject $o): BackupObject => $o->withSchedule(ScheduleMapper::forObject($o->jobName, $s['byJob'])), $out['objects']);

        return $out;
    }

    /**
     * Audit endpoints of the Vinchin v9 API, one page each (stage 1, user 2026-11-03). Sort
     * fields are required by these list calls; where the doc gives none, known names are
     * tried in turn. verify/virus history need a restore point (timepoint_uuid of a sampled
     * item) and copy history a copy or replica job: without one they are skipped, said so.
     */
    public function probeAudit(int $limit, string $search = ''): array
    {
        $out = ['raw' => [], 'errors' => []];

        if ($this->useLogin) {
            try {
                $this->client->login();
            } catch (\RuntimeException $e) {
                $out['errors'][] = 'login: ' . $e->getMessage();
                return $out;
            }
        }

        $firstPageOnly = static fn(): bool => true;
        $sample = function (string $label, string $path, array $params, array $sorts = [null]) use (&$out, $limit, $firstPageOnly): void {
            $last = null;
            foreach ($sorts as $sort) {
                try {
                    $out['raw'][$label] = $this->client->getPaged($path, ($sort === null ? [] : ['sort' => $sort]) + $params, $limit, $limit, $firstPageOnly);
                    return;
                } catch (\RuntimeException $e) {
                    $last = $e;
                }
            }
            $out['errors'][] = $last->getMessage();
        };
        $desc = ['search' => $search, 'order' => 'desc'];

        $sample('failed runs (' . self::HISTORY_PATH . ', job_status 4)', self::HISTORY_PATH, $desc + ['job_status' => 4], ['start_time', null]);
        $sample('/alarm/job', '/alarm/job', $desc, ['alarm_time']);
        $sample('/alarm/system', '/alarm/system', $desc, ['alarm_time']);
        $sample('/logs/job', '/logs/job', $desc, ['op_time']);
        $sample('/logs/system', '/logs/system', $desc, ['create_time', 'op_time']);
        $sample('/backupmanager/report/vm', '/backupmanager/report/vm', []);
        $sample('/industry/report', '/industry/report', ['search' => $search]);

        // Verification and virus scan are per restore point: the newest one of a sampled item.
        $timepoint = '';
        try {
            foreach ($this->client->postPaged(self::ITEMS_PATH, ['search' => $search] + self::ITEMS_FILTER, $limit, $limit, $firstPageOnly) as $row) {
                $timepoint = is_array($row) ? trim((string) ($row['timepoint_uuid'] ?? '')) : '';
                if ($timepoint !== '') {
                    break;
                }
            }
        } catch (\RuntimeException $e) {
            $out['errors'][] = $e->getMessage();
        }
        foreach (['/backup_data/verify_history', '/backup_data/virus_history'] as $path) {
            if ($timepoint === '') {
                $out['errors'][] = $path . ': skipped, no restore point (timepoint_uuid) in the sample';
                continue;
            }
            try {
                $data = $this->client->get($path, ['timepoint_uuid' => $timepoint]);
                $out['raw'][$path] = is_array($data['rows'] ?? null) ? array_values($data['rows']) : (is_array($data) ? [$data] : []);
            } catch (\RuntimeException $e) {
                $out['errors'][] = $e->getMessage();
            }
        }

        // Copy history (offsite copy, 3-2-1) is per copy or replica job.
        try {
            $copyJob = '';
            foreach ($this->client->getPaged(self::JOBS_PATH, ['search' => ''], 100, 100, $firstPageOnly) as $job) {
                if (is_array($job) && preg_match('/copy|replica/i', (string) ($job['job_type'] ?? '')) === 1) {
                    $copyJob = (string) ($job['job_uuid'] ?? '');
                    break;
                }
            }
            if ($copyJob === '') {
                $out['errors'][] = '/copy/job/history: skipped, no copy or replica job in the job list';
            } else {
                $sample('/copy/job/history', '/copy/job/history', ['task_uuid' => $copyJob]);
            }
        } catch (\RuntimeException $e) {
            $out['errors'][] = $e->getMessage();
        }

        return $out;
    }

    public function runs(callable $pageKnown, int $maxPages, int $pageSize = 100): array
    {
        $out = ['runs' => [], 'errors' => []];
        if (($error = $this->ensureLogin()) !== '') {
            $out['errors'][] = $error;
            return $out;
        }

        $stop = static function (array $batch) use ($pageKnown): bool {
            $uuids = [];
            foreach ($batch as $row) {
                $uuid = is_array($row) ? trim((string) ($row['history_uuid'] ?? '')) : '';
                if ($uuid !== '') {
                    $uuids[] = $uuid;
                }
            }
            return $pageKnown($uuids);
        };
        $max  = max(1, $maxPages) * $pageSize;
        $rows = null;
        foreach ([['sort' => 'start_time', 'order' => 'desc'], ['order' => 'desc']] as $params) {
            try {
                $rows = $this->client->getPaged(self::HISTORY_PATH, ['search' => ''] + $params, $pageSize, $max, $stop);
                break;
            } catch (\RuntimeException $e) {
                $last = $e->getMessage();
            }
        }
        if ($rows === null) {
            $out['errors'][] = $last;
            return $out;
        }

        $seen = [];
        foreach ($rows as $row) {
            $run = RunMapper::map($row);
            if ($run !== null && !isset($seen[$run->historyUuid])) {
                $seen[$run->historyUuid] = true;
                $out['runs'][] = $run;
            }
        }
        return $out;
    }

    /**
     * GET /jobs/history/{uuid}. The doc gives data.list[] with module-specific fields (vm_name for VMs,
     * display_name for OS, db_name for databases), some under `info`. Not sampled on the VM yet, so any
     * other shape is an error line, never a throw.
     */
    public function runItems(string $historyUuid): array
    {
        $path = self::HISTORY_PATH . '/' . rawurlencode($historyUuid);
        if (($error = $this->ensureLogin()) !== '') {
            return ['items' => [], 'error' => $error];
        }
        try {
            $data = $this->client->get($path);
        } catch (\RuntimeException $e) {
            return ['items' => [], 'error' => $e->getMessage()];
        }
        $list = is_array($data) ? ($data['list'] ?? ($data['rows'] ?? (array_is_list($data) ? $data : null))) : null;
        if (!is_array($list)) {
            return ['items' => [], 'error' => $path . ': unexpected answer (no list)'];
        }

        $items = [];
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $f    = (is_array($entry['info'] ?? null) ? $entry['info'] : []) + $entry;
            $name = '';
            foreach (['vm_name', 'display_name', 'db_name', 'item_name', 'agent_name'] as $key) {
                if (is_scalar($f[$key] ?? null) && trim((string) $f[$key]) !== '') {
                    $name = trim((string) $f[$key]);
                    break;
                }
            }
            if ($name === '') {
                continue;
            }
            $status  = (string) ($f['task_status'] ?? ($f['vm_status'] ?? ($f['item_status'] ?? '')));
            $items[] = [
                'vm_name' => mb_substr($name, 0, 255),
                'status'  => RunMapper::status($status),
                'error'   => mb_substr(trim((string) ($f['error_code_des'] ?? ($f['error_code'] ?? ''))), 0, 255),
            ];
        }
        return ['items' => $items, 'error' => ''];
    }

    public function jobAlarms(string $sinceTime, int $max = 2000): array
    {
        if (($error = $this->ensureLogin()) !== '') {
            return ['rows' => [], 'error' => $error];
        }
        $since = ItemMapper::parseTime($sinceTime) ?? 0;
        $older = static function (mixed $row) use ($since): bool {
            $t = is_array($row) ? ItemMapper::parseTime($row['alarm_time'] ?? null) : null;
            return $t !== null && $t < $since;
        };
        try {
            $rows = $this->client->getPaged('/alarm/job', ['search' => '', 'sort' => 'alarm_time', 'order' => 'desc'], 100, $max,
                static fn(array $batch): bool => $batch !== [] && $older(end($batch)));
        } catch (\RuntimeException $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }
        return ['rows' => array_values(array_filter($rows, static fn($row): bool => is_array($row) && !$older($row))), 'error' => ''];
    }

    /** Login mode: one login per instance before the first read. Returns an error line, or ''. */
    private function ensureLogin(): string
    {
        if (!$this->useLogin || $this->loggedIn) {
            return '';
        }
        try {
            $this->client->login();
            $this->loggedIn = true;
            return '';
        } catch (\RuntimeException $e) {
            return 'login: ' . $e->getMessage();
        }
    }
}
