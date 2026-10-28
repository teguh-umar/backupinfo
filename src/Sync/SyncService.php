<?php

namespace GlpiPlugin\Backupinfo\Sync;

use GlpiPlugin\Backupinfo\Connection;
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
            // Vinchin sync is rewritten in v0.2 (Task 10); the rc1 client was removed.
            $result['message'] = 'Vinchin sync not available in this build';
        } elseif ($type === 'excel') {
            $import = new ExcelImport();
            // Excel import would need file upload path - handle separately
            $result['message'] = 'Excel import handled via upload form';
        }

        return $result;
    }
}
