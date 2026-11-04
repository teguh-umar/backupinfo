<?php

namespace GlpiPlugin\Backupinfo;

use Computer;
use Config;
use CronTask;
use GlpiPlugin\Backupinfo\Fields\FieldsBridge;
use GlpiPlugin\Backupinfo\Matching\DbAssetLookup;
use GlpiPlugin\Backupinfo\Matching\Matcher;
use GlpiPlugin\Backupinfo\Source\Registry;
use GlpiPlugin\Backupinfo\Source\RunHistorySource;
use GlpiPlugin\Backupinfo\Sync\DbObjectStore;
use GlpiPlugin\Backupinfo\Sync\DbRunStore;
use GlpiPlugin\Backupinfo\Sync\RunSync;
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
        $run = self::runSync(static function (string $line) use ($task): void {
            $task->log($line);
        });
        $task->addVolume($run['fetched']);
        return SyncService::cronResult($run['results'], $run['touched']);
    }

    /**
     * One full sync of every active connection, then the Fields update. Shared by the
     * cron and Setup's "Sync now" so both run exactly the same code.
     *
     * @param callable(string): void $log receives one line per connection / step (no secrets)
     * @return array{results: list<array{id:int,name:string,status:string,error:string,stats:array}>, fetched: int, touched: int, fields_error: string}
     */
    /** @return callable(string): string decrypts a stored connection secret ('' stays '') */
    public static function decryptor(): callable
    {
        $key = new GLPIKey();
        return static fn(string $v): string => $v === '' ? '' : (string) ($key->decrypt($v) ?? '');
    }

    /** @return list<string> non-deleted, non-template Computer names (job-history mapping) */
    public static function computerNames(): array
    {
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
    }

    public static function runSync(callable $log): array
    {
        global $DB;

        $now     = time();
        $service = new SyncService();
        $store   = new DbObjectStore();
        $matcher = new Matcher(new DbAssetLookup());
        $decrypt = self::decryptor();
        $vmNames = [self::class, 'computerNames'];

        $connections = [];
        foreach ($DB->request(['FROM' => self::CONNECTIONS, 'WHERE' => ['is_active' => 1]]) as $conn) {
            $connections[] = $conn;
        }

        $sources = [];
        $out = $service->runAll(
            $connections,
            static function (array $conn) use ($decrypt, $vmNames, &$sources) {
                return $sources[(int) $conn['id']] = Registry::create($conn, $decrypt, $vmNames);
            },
            $store,
            $matcher,
            $now
        );

        $fetched = 0;
        foreach ($out['results'] as $r) {
            $update = ['last_sync' => date('Y-m-d H:i:s', $now), 'last_status' => $r['status'], 'last_error' => $r['error']];
            $DB->update(self::CONNECTIONS, $update, ['id' => $r['id']]);
            if ($r['status'] === 'ok') {
                // Unread job schedules do not fail the sync: those objects use the global RPO.
                $source         = $sources[$r['id']] ?? null;
                $scheduleErrors = is_object($source) && method_exists($source, 'scheduleErrors') ? $source->scheduleErrors() : [];
                if ($scheduleErrors !== []) {
                    Toolbox::logInFile('backupinfo', sprintf("Sync %s: %d job schedule(s) not read, global RPO used: %s\n", $r['name'], count($scheduleErrors), $scheduleErrors[0]));
                    $log(sprintf('%s: %d job schedule(s) not read (global RPO used)', $r['name'], count($scheduleErrors)));
                }
                $fetched += $r['stats']['fetched'];
                $log(sprintf(
                    '%s: fetched %d, matched %d, needs review %d, not found %d',
                    $r['name'],
                    $r['stats']['fetched'],
                    $r['stats']['matched'],
                    $r['stats']['review'],
                    $r['stats']['unmatched']
                ));
            } else {
                // Kept visible on the connection; the other connections still ran.
                Toolbox::logInFile('backupinfo', 'Sync ' . $r['name'] . ': ' . $r['error'] . "\n");
                $log($r['name'] . ': ' . $r['error']);
            }
        }

        self::storeRuns($out['results'], $sources, $now, $log);

        $fieldsError = '';
        $touched     = self::applyFields($out['affected'], $now, $fieldsError);
        $log($fieldsError === '' ? sprintf('assets updated in Fields: %d', $touched) : 'Fields update failed: ' . $fieldsError);

        return ['results' => $out['results'], 'fetched' => $fetched, 'touched' => $touched, 'fields_error' => $fieldsError];
    }

    /**
     * Writes Backup Status / Media to every matched Computer plus $extraItemIds (those that
     * just lost their objects). Never throws; a failure is logged and returned in $error.
     *
     * @param list<int> $extraItemIds
     */
    public static function applyFields(array $extraItemIds, ?int $now = null, string &$error = ''): int
    {
        $error = '';
        try {
            $bridge = FieldsBridge::fromConfig();
            if (!$bridge->canWrite()) {
                // Said out loud: a mapping problem used to show only "0 updated" (VM 2026-11-03).
                $error = 'Fields not updated: ' . implode(' ', $bridge->problems());
                Toolbox::logInFile('backupinfo', 'applyToAssets: ' . $error . "\n");
                return 0;
            }
            return (new SyncService())->applyToAssets(
                new DbObjectStore(),
                $bridge,
                Rpo::fromConfig(),
                $now ?? time(),
                $extraItemIds
            );
        } catch (\Throwable $e) {
            $error = SyncService::errorLine($e);
            Toolbox::logInFile('backupinfo', 'applyToAssets: ' . $error . "\n");
            return 0;
        }
    }

    /**
     * Audit evidence (0.3.0): new backup runs of every connection whose object sync succeeded, then
     * the retention purge. Never fails the sync: an error is logged against the connection's runs only.
     *
     * @param list<array{id:int,name:string,status:string}> $results
     * @param array<int, mixed> $sources connection id => source
     */
    private static function storeRuns(array $results, array $sources, int $now, callable $log): void
    {
        $cfg   = Config::getConfigurationValues(SuiteConfig::CONTEXT, ['run_pages_max', 'run_keep_months']);
        $pages = max(1, (int) ($cfg['run_pages_max'] ?? 20));
        $store = new DbRunStore();
        $byConn = null;

        foreach ($results as $r) {
            $source = $sources[$r['id']] ?? null;
            if ($r['status'] !== 'ok' || !($source instanceof RunHistorySource)) {
                continue;
            }
            try {
                $byConn ??= self::objectLookups();
                $names = $byConn[$r['id']]['names'] ?? [];
                $jobs  = $byConn[$r['id']]['jobs'] ?? [];
                $res   = (new RunSync())->run(
                    (int) $r['id'],
                    $source,
                    $store,
                    static fn(string $vm): int => $names[mb_strtolower(trim($vm))] ?? 0,
                    static fn(string $job): array => $jobs[mb_strtolower(trim($job))] ?? [],
                    $pages
                );
                $log(sprintf('%s: runs stored %d (failed %d)', $r['name'], $res['new'], $res['failed']));
                if ($res['errors'] !== []) {
                    Toolbox::logInFile('backupinfo', sprintf("Runs %s: %d error(s), first: %s\n", $r['name'], count($res['errors']), $res['errors'][0]));
                    $log(sprintf('%s: %d run read error(s), see backupinfo.log', $r['name'], count($res['errors'])));
                }
            } catch (\Throwable $e) {
                $line = sprintf('Runs %s: %s', $r['name'], SyncService::errorLine($e));
                Toolbox::logInFile('backupinfo', $line . "\n");
                $log($line);
            }
        }

        try {
            $purged = $store->purgeOlderThan((int) ($cfg['run_keep_months'] ?? 36), $now);
            if ($purged > 0) {
                $log(sprintf('runs purged (retention): %d', $purged));
            }
        } catch (\Throwable $e) {
            Toolbox::logInFile('backupinfo', 'Runs purge: ' . SyncService::errorLine($e) . "\n");
        }
    }

    /**
     * Matched objects per connection: object name -> Computer, and job name -> its Computers.
     *
     * @return array<int, array{names: array<string, int>, jobs: array<string, list<array{computers_id:int, vm_name:string}>>}>
     */
    private static function objectLookups(): array
    {
        $out = [];
        foreach ((new DbObjectStore())->matched() as $o) {
            $conn = (int) $o['connections_id'];
            $name = trim((string) $o['name']);
            $id   = (int) $o['items_id'];
            $out[$conn]['names'][mb_strtolower($name)] = $id;
            // job_name holds ", "-joined names: split, never LIKE
            foreach (explode(',', (string) $o['job_name']) as $job) {
                if (trim($job) !== '') {
                    $out[$conn]['jobs'][mb_strtolower(trim($job))][] = ['computers_id' => $id, 'vm_name' => $name];
                }
            }
        }
        return $out;
    }
}
