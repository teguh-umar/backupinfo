<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

use GlpiPlugin\Backupinfo\Source\BackupSource;
use GlpiPlugin\Backupinfo\Source\PartialFetch;
use GlpiPlugin\Backupinfo\Source\ProbeableSource;

final class VinchinSource implements BackupSource, ProbeableSource, PartialFetch
{
    /** true when the last fetch() came from the job-history fallback (recent VM jobs only) */
    private bool $partial = false;

    private const ITEMS_PATH   = '/backup_data/items/list';
    private const HISTORY_PATH = '/jobs/history';

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
            return ItemMapper::map($rows);
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
        return JobHistoryMapper::map($rows, $names, time());
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

        return $out;
    }
}
