<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

use GlpiPlugin\Backupinfo\Source\BackupObject;
use GlpiPlugin\Backupinfo\Source\BackupSource;
use GlpiPlugin\Backupinfo\Source\PartialFetch;
use GlpiPlugin\Backupinfo\Source\ProbeableSource;

final class VinchinSource implements BackupSource, ProbeableSource, PartialFetch
{
    /** true when the last fetch() came from the job-history fallback (recent VM jobs only) */
    private bool $partial = false;

    /** @var list<string> */
    private array $scheduleErrors = [];

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
        $wanted = [];
        foreach ($objects as $o) {
            foreach (explode(',', mb_strtolower($o->jobName)) as $name) {
                if (trim($name) !== '') {
                    $wanted[trim($name)] = true;
                }
            }
            $wanted[mb_strtolower(trim($o->jobName))] = true;
        }

        $s = $this->schedules('', 100, 5000, null, $wanted);
        $this->scheduleErrors = $s['errors'];
        return array_map(static fn(BackupObject $o): BackupObject => $o->withSchedule(ScheduleMapper::forObject($o->jobName, $s['byJob'])), $objects);
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
                if ($hours === null) {
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
        $s = $this->schedules($search, $limit, $limit, $firstPageOnly, null);
        if ($s['listed']) {
            $out['raw'][self::JOBS_PATH]          = $s['jobs'];
            $out['raw'][self::JOBS_PATH . '/{ID}'] = $s['details'];
        }
        array_push($out['errors'], ...$s['errors']);
        $out['objects'] = array_map(static fn(BackupObject $o): BackupObject => $o->withSchedule(ScheduleMapper::forObject($o->jobName, $s['byJob'])), $out['objects']);

        return $out;
    }
}
