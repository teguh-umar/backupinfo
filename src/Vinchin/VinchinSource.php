<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

use GlpiPlugin\Backupinfo\Source\AuditProbeableSource;
use GlpiPlugin\Backupinfo\Source\AuditSource;
use GlpiPlugin\Backupinfo\Source\Event;
use GlpiPlugin\Backupinfo\Source\Target;
use GlpiPlugin\Backupinfo\Source\BackupObject;
use GlpiPlugin\Backupinfo\Source\BackupSource;
use GlpiPlugin\Backupinfo\Source\PartialFetch;
use GlpiPlugin\Backupinfo\Source\ProbeableSource;
use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Source\RunHistorySource;
use GlpiPlugin\Backupinfo\Text;

final class VinchinSource implements BackupSource, ProbeableSource, AuditProbeableSource, PartialFetch, RunHistorySource, AuditSource
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

        // Per-VM result of one run (0.3.0 run store reads it for failed runs): the first failed run.
        $failedLabel = 'failed runs (' . self::HISTORY_PATH . ', job_status 4)';
        // A VM job's run first: a file-backup run answered "code -1" on the VM (2026-11-04).
        $firstFailed = '';
        foreach ($out['raw'][$failedLabel] ?? [] as $run) {
            $uuid = is_array($run) ? trim((string) ($run['history_uuid'] ?? '')) : '';
            if ($uuid !== '' && ($firstFailed === '' || (int) ($run['module_type_value'] ?? 0) === 2)) {
                $firstFailed = $uuid;
                if ((int) ($run['module_type_value'] ?? 0) === 2) {
                    break;
                }
            }
        }
        if ($firstFailed === '') {
            $out['errors'][] = self::HISTORY_PATH . '/{uuid}: skipped, no failed run in the sample';
        } else {
            try {
                $data = $this->client->get(self::HISTORY_PATH . '/' . rawurlencode($firstFailed));
                $list = is_array($data) ? ($data['list'] ?? ($data['rows'] ?? $data)) : [];
                $out['raw'][self::HISTORY_PATH . '/{uuid}'] = is_array($list) && array_is_list($list) ? $list : [$list];
            } catch (\RuntimeException $e) {
                $out['errors'][] = $e->getMessage();
            }
        }
        $sample('/alarm/job', '/alarm/job', $desc, ['alarm_time']);
        $sample('/alarm/system', '/alarm/system', $desc, ['alarm_time']);
        $sample('/logs/job', '/logs/job', $desc, ['op_time']);
        $sample('/logs/system', '/logs/system', $desc, ['create_time', 'op_time']);
        $sample('/backupmanager/report/vm', '/backupmanager/report/vm', []);
        $sample('/industry/report', '/industry/report', ['search' => $search]);
        // Backup targets (0.3.0-rc5 probe): disk/NAS storages and tape.
        $sample('/backupmanager/report/storage', '/backupmanager/report/storage', ['order' => 'desc'], ['storage_name', null]);
        $sample('/storages', '/storages', []);
        $sample('/tapes/job', '/tapes/job', []);
        $sample('/tapes', '/tapes', []);

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

    /**
     * Verification history of one restore point (GET /backup_data/verify_history).
     *
     * @return array{rows: list<array<string, mixed>>, error: string}
     */
    public function verifications(string $timepointUuid): array
    {
        if (($error = $this->ensureLogin()) !== '') {
            return ['rows' => [], 'error' => $error];
        }
        try {
            $data = $this->client->get('/backup_data/verify_history', ['timepoint_uuid' => $timepointUuid]);
        } catch (\RuntimeException $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }
        $rows = is_array($data) ? ($data['rows'] ?? ($data['list'] ?? [])) : [];
        return ['rows' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [], 'error' => ''];
    }

    // --- AuditSource: live reads for the audit evidence page (0.3.0) -------------------------

    /** Keyword-free (the endpoint ignores it); the hypervisor address is not kept. */
    public function platformVms(): array
    {
        if (($error = $this->ensureLogin()) !== '') {
            return ['rows' => [], 'error' => $error];
        }
        try {
            $rows = $this->client->getPaged('/backupmanager/report/vm', [], 100, 5000);
        } catch (\RuntimeException $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['vm_name'] ?? '')) === '') {
                continue;
            }
            $last  = trim((string) ($row['last_backup_time'] ?? ''));
            $out[] = [
                'vm_name'          => trim((string) $row['vm_name']),
                'last_backup_time' => RunMapper::time($last) ?? '',
                'backup_status'    => (int) ($row['backup_status'] ?? 0),
                'backup_count'     => (int) ($row['backup_count'] ?? 0),
                'backup_task'      => trim((string) ($row['backup_task'] ?? '')) === '--' ? '' : trim((string) ($row['backup_task'] ?? '')),
            ];
        }
        return ['rows' => $out, 'error' => ''];
    }

    public function copyRuns(string $from, string $to): array
    {
        if (($error = $this->ensureLogin()) !== '') {
            return ['rows' => [], 'error' => $error];
        }
        try {
            $jobs = $this->client->getPaged(self::JOBS_PATH, ['search' => ''], 100, 5000);
        } catch (\RuntimeException $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }

        $out = [];
        foreach ($jobs as $job) {
            if (!is_array($job) || preg_match('/copy|replica|replicat/i', (string) ($job['job_type'] ?? '')) !== 1) {
                continue;
            }
            $uuid = trim((string) ($job['job_uuid'] ?? ''));
            if ($uuid === '') {
                continue;
            }
            $runs = $this->periodList('/copy/job/history', ['task_uuid' => $uuid], 'start_time', $from, $to, [null], 1000);
            if ($runs['error'] !== '') {
                return ['rows' => [], 'error' => $runs['error']];
            }
            foreach ($runs['rows'] as $run) {
                $items = $run['details']['info'] ?? [];
                foreach (is_array($items) ? $items : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $out[] = Event::make('copy', [
                        'job'            => $job['job_name'] ?? '',
                        'time'           => RunMapper::time($run['start_time'] ?? null) ?? '',
                        'end_time'       => RunMapper::time($run['end_time'] ?? null) ?? '',
                        'status'         => $run['status'] ?? '',
                        // "VM-A(Some Platform)": the platform suffix is not part of the VM name
                        'vm'             => preg_replace('/\s*\([^()]*\)\s*$/', '', (string) ($item['item_name'] ?? '')),
                        'item_status'    => $item['item_status'] ?? '',
                        'restore_points' => (int) ($item['timepoint_count'] ?? 0),
                        'size'           => $item['valid_size'] ?? '',
                        'error'          => $item['error_code'] ?? '',
                    ]);
                }
            }
        }
        return ['rows' => $out, 'error' => ''];
    }

    public function jobLogs(string $from, string $to): array
    {
        return self::events('joblog', $this->periodList('/logs/job', ['search' => ''], 'op_time', $from, $to, ['op_time'], 2000, 'log_description'));
    }

    /** No keyword: with a VM name the endpoint returned nothing (VM, 2026-11-03). */
    public function systemLogs(string $from, string $to): array
    {
        return self::events('syslog', $this->periodList('/logs/system', [], 'op_time', $from, $to, ['create_time', 'op_time'], 2000, 'description'));
    }

    public function systemAlarms(string $from, string $to): array
    {
        return self::events('sysalarm', $this->periodList('/alarm/system', [], 'alarm_time', $from, $to, ['alarm_time'], 2000, 'description'));
    }

    /** @param array{rows: list<array<string, mixed>>, error: string} $res */
    private static function events(string $kind, array $res): array
    {
        return ['rows' => array_map(static fn(array $row): array => EventMapper::map($kind, $row), $res['rows']), 'error' => $res['error']];
    }

    /**
     * Disk, NAS and tape storages (report/storage: storage_type 10 is a tape group, 6 NFS, seen on
     * the VM 2026-11-05), each tape group with the copy jobs writing to it, then the tape libraries.
     */
    public function targets(): array
    {
        if (($error = $this->ensureLogin()) !== '') {
            return ['rows' => [], 'error' => $error];
        }
        try {
            $storages = $this->client->getPaged('/backupmanager/report/storage', ['sort' => 'storage_name', 'order' => 'desc'], 100, 1000);
        } catch (\RuntimeException $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }
        // Tape jobs and libraries are optional: a key without the tape module still lists storages.
        $jobsByGroup = [];
        $libraries   = [];
        try {
            foreach ($this->client->getPaged('/tapes/job', [], 100, 1000) as $job) {
                if (is_array($job) && trim((string) ($job['group_name'] ?? '')) !== '') {
                    $jobsByGroup[mb_strtolower(trim((string) $job['group_name']))][] = trim((string) ($job['job_name'] ?? ''));
                }
            }
            $libraries = $this->client->getPaged('/tapes', [], 100, 200);
        } catch (\RuntimeException $e) {
            // listed without tape detail
        }

        $out = [];
        foreach ($storages as $st) {
            if (!is_array($st)) {
                continue;
            }
            $name = trim((string) ($st['storage_nickname'] ?? ($st['storage_name'] ?? '')));
            $type = (int) ($st['storage_type'] ?? 0);
            $kind = match ($type) { 10 => 'tape', 6 => 'nas', default => 'disk' };
            $out[] = Target::make([
                'kind'   => $kind,
                'name'   => $name,
                'type'   => match ($type) { 10 => 'Tape group', 6 => 'NFS share', default => 'Storage type ' . $type },
                'node'   => $st['node_name'] ?? '',
                'total'  => $st['total'] ?? '',
                'used'   => $st['used'] ?? '',
                'free'   => $st['rest'] ?? '',
                'online' => isset($st['storage_status']) ? ((int) $st['storage_status'] === 1 ? 'yes' : 'no') : '',
                'jobs'   => implode(', ', array_unique($jobsByGroup[mb_strtolower($name)] ?? [])),
            ]);
        }
        foreach ($libraries as $lib) {
            if (!is_array($lib)) {
                continue;
            }
            $out[] = Target::make([
                'kind'   => 'tape library',
                'name'   => $lib['name'] ?? ($lib['lib_name'] ?? ''),
                'type'   => trim((string) ($lib['vendor'] ?? '') . ' ' . (string) ($lib['lib_name'] ?? '')),
                'detail' => isset($lib['slot_num']) ? ((int) $lib['slot_num'] . ' slots') : '',
            ]);
        }
        return ['rows' => $out, 'error' => ''];
    }

    /**
     * Newest-first list cut to [$from, $to]: paging stops at the first page that ends before $from.
     * Sort fields are tried in turn (null = no sort). $textKey is made plain text.
     *
     * @param list<?string> $sorts
     * @return array{rows: list<array<string, mixed>>, error: string}
     */
    private function periodList(string $path, array $params, string $timeKey, string $from, string $to, array $sorts, int $max, string $textKey = ''): array
    {
        if (($error = $this->ensureLogin()) !== '') {
            return ['rows' => [], 'error' => $error];
        }
        $fromS = ItemMapper::parseTime($from) ?? 0;
        $toS   = ItemMapper::parseTime($to) ?? PHP_INT_MAX;
        $time  = static fn($row): ?int => is_array($row) ? ItemMapper::parseTime($row[$timeKey] ?? ($row['create_time'] ?? null)) : null;
        $stop  = static function (array $batch) use ($time, $fromS): bool {
            $t = $batch === [] ? null : $time(end($batch));
            return $t !== null && $t < $fromS;
        };

        $rows = null;
        $last = '';
        foreach ($sorts as $sort) {
            try {
                // Without a sort the order is not known, so no early stop: only $max bounds the read.
                $rows = $this->client->getPaged($path, ($sort === null ? [] : ['sort' => $sort, 'order' => 'desc']) + $params, 100, $max, $sort === null ? null : $stop);
                break;
            } catch (\RuntimeException $e) {
                $last = $e->getMessage();
            }
        }
        if ($rows === null) {
            return ['rows' => [], 'error' => $last];
        }

        $out = [];
        foreach ($rows as $row) {
            $t = $time($row);
            if ($t === null || $t < $fromS || $t > $toS) {
                continue;
            }
            if ($textKey !== '' && isset($row[$textKey])) {
                $row[$textKey] = Text::plain((string) $row[$textKey], 500);
            }
            $out[] = $row;
        }
        return ['rows' => $out, 'error' => ''];
    }
}
