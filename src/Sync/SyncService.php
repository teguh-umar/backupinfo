<?php

namespace GlpiPlugin\Backupinfo\Sync;

use GlpiPlugin\Backupinfo\Connection;
use GlpiPlugin\Backupinfo\Api\VinchinClient;
use GlpiPlugin\Backupinfo\Import\ExcelImport;

class SyncService
{
    public function sync(Connection $connection): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'stats' => []
        ];

        if (!$connection->fields['is_active']) {
            $result['message'] = 'Connection not active';
            return $result;
        }

        $type = $connection->fields['type'];

        if ($type === 'vinchin') {
            try {
                $client = new VinchinClient($connection->fields);
                $assets = $client->getProtectedAssets();
                $jobs = $client->getBackupJobs(['limit' => 1000]);
                $storage = $client->getStorage();

                $result['stats'] = [
                    'assets' => is_array($assets) ? count($assets) : 0,
                    'jobs' => is_array($jobs) ? count($jobs) : 0,
                    'storage' => is_array($storage) ? count($storage) : 0,
                ];
                $result['success'] = true;
                $result['message'] = 'Sync completed';
            } catch (\Throwable $e) {
                $result['message'] = $e->getMessage();
            }
        } elseif ($type === 'excel') {
            $import = new ExcelImport();
            // Excel import would need file upload path - handle separately
            $result['message'] = 'Excel import handled via upload form';
        }

        return $result;
    }
}
