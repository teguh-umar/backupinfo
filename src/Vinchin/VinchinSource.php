<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

use GlpiPlugin\Backupinfo\Source\BackupSource;

final class VinchinSource implements BackupSource
{
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

    public function fetch(): array
    {
        if ($this->useLogin) {
            $this->client->login();
        }

        try {
            $rows = $this->client->postPaged('/backup_data/items/list', [
                'search' => '',
                'sort' => 'item_name',
                'order' => 'desc',
                'module_type' => [],
                'storage_uuid' => [],
                'task_type' => [],
                'operation_status' => [],
                'status' => [],
                'sub_module_type' => [],
            ]);
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

        return JobHistoryMapper::map($rows, $names, time());
    }
}
