<?php

namespace GlpiPlugin\Backupinfo;

use Computer;
use Config;
use CronTask;
use GlpiPlugin\Backupinfo\Fields\FieldsBridge;
use GlpiPlugin\Backupinfo\Matching\DbAssetLookup;
use GlpiPlugin\Backupinfo\Matching\Matcher;
use GlpiPlugin\Backupinfo\Source\Registry;
use GlpiPlugin\Backupinfo\Sync\DbObjectStore;
use GlpiPlugin\Backupinfo\Sync\SyncService;
use GLPIKey;
use Toolbox;

final class Cron extends \CommonGLPI
{
    private const CONNECTIONS = 'glpi_plugin_backupinfo_connections';

    public static function getTypeName($nb = 0)
    {
        return 'Backup Info';
    }

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'Sync'  => ['description' => __('Sync backup objects', 'backupinfo')],
            default => [],
        };
    }

    public static function cronSync(CronTask $task): int
    {
        global $DB;

        $now     = time();
        $service = new SyncService();
        $store   = new DbObjectStore();
        $matcher = new Matcher(new DbAssetLookup());
        $key     = new GLPIKey();
        $decrypt = static fn(string $v): string => $v === '' ? '' : (string) ($key->decrypt($v) ?? '');
        $vmNames = static function (): array {
            global $DB;
            $names = [];
            foreach ($DB->request([
                'SELECT' => ['name'],
                'FROM'   => Computer::getTable(),
                'WHERE'  => ['is_deleted' => 0, 'is_template' => 0],
            ]) as $row) {
                $names[] = (string) $row['name'];
            }
            return $names;
        };

        $affected = [];
        $ran      = 0;
        $fetched  = 0;

        foreach ($DB->request(['FROM' => self::CONNECTIONS, 'WHERE' => ['is_active' => 1]]) as $conn) {
            $ran++;
            $update = ['last_sync' => date('Y-m-d H:i:s', $now)];
            try {
                $source   = Registry::create($conn, $decrypt, $vmNames);
                $stats    = $service->run($conn, $source, $store, $matcher, $now);
                $affected = array_merge($affected, $stats['affected']);
                $fetched += $stats['fetched'];
                $update  += ['last_status' => 'ok', 'last_error' => ''];
                $task->log(sprintf(
                    '%s: fetched %d, matched %d, needs review %d, not found %d',
                    $conn['name'],
                    $stats['fetched'],
                    $stats['matched'],
                    $stats['review'],
                    $stats['unmatched']
                ));
            } catch (\Throwable $e) {
                // Kept visible on the connection; the next connection still runs.
                $line    = SyncService::errorLine($e);
                $update += ['last_status' => 'error', 'last_error' => $line];
                Toolbox::logInFile('backupinfo', 'Sync ' . $conn['name'] . ': ' . $line . "\n");
                $task->log($conn['name'] . ': ' . $line);
            }
            $DB->update(self::CONNECTIONS, $update, ['id' => (int) $conn['id']]);
        }

        try {
            $cfg     = Config::getConfigurationValues('plugin:backupinfo', ['rpo_hours']);
            $rpo     = (int) ($cfg['rpo_hours'] ?? 26);
            $touched = $service->applyToAssets(
                $store,
                FieldsBridge::fromConfig(),
                $rpo > 0 ? $rpo : 26,
                $now,
                array_values(array_unique($affected))
            );
            $task->log(sprintf('assets updated in Fields: %d', $touched));
        } catch (\Throwable $e) {
            $line = SyncService::errorLine($e);
            Toolbox::logInFile('backupinfo', 'applyToAssets: ' . $line . "\n");
            $task->log('Fields update failed: ' . $line);
        }

        $task->addVolume($fetched);
        return $ran > 0 ? 1 : 0;
    }
}
