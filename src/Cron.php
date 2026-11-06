<?php

namespace GlpiPlugin\Backupinfo;

use Computer;
use Config;
use CronTask;
use GlpiPlugin\Backupinfo\Fields\FieldsBridge;
use GlpiPlugin\Backupinfo\Matching\DbAssetLookup;
use GlpiPlugin\Backupinfo\Matching\Matcher;
use GlpiPlugin\Backupinfo\Source\AuditSource;
use GlpiPlugin\Backupinfo\Source\Registry;
use GlpiPlugin\Backupinfo\Source\RunHistorySource;
use GlpiPlugin\Backupinfo\Sync\DbObjectStore;
use GlpiPlugin\Backupinfo\Sync\DbEventStore;
use GlpiPlugin\Backupinfo\Sync\DbRunStore;
use GlpiPlugin\Backupinfo\Sync\EventSync;
use GlpiPlugin\Backupinfo\Sync\DbSnapshotStore;
use GlpiPlugin\Backupinfo\Sync\SnapshotSync;
use GlpiPlugin\Backupinfo\Tickets\FindingService;
use GlpiPlugin\Backupinfo\Tickets\TicketService;
use GlpiPlugin\Backupinfo\Sync\RunSync;
use GlpiPlugin\Backupinfo\Sync\SyncService;
use GlpiPlugin\Backupinfo\Sync\VerifyImport;
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
        self::storeEvents($out['results'], $sources, $now, $log);
        self::storeSnapshots($out['results'], $sources, $now, $log);
        self::raiseTickets($now, $log);
        self::raiseFindings($now, $log);
        self::importVerifications($out['results'], $sources, $log);

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
     * Audit evidence (0.3.0-rc5): new copy runs, job/system logs and system alarms of every
     * connection whose object sync succeeded, then the retention purge (same months as runs).
     * Never fails the sync.
     *
     * @param list<array{id:int,name:string,status:string}> $results
     * @param array<int, mixed> $sources connection id => source
     */
    private static function storeEvents(array $results, array $sources, int $now, callable $log): void
    {
        $store = new DbEventStore();
        foreach ($results as $r) {
            $source = $sources[$r['id']] ?? null;
            if ($r['status'] !== 'ok' || !($source instanceof AuditSource)) {
                continue;
            }
            try {
                $res = EventSync::run((int) $r['id'], $source, $store, $now);
                $log(sprintf('%s: events stored %s', $r['name'], implode(', ', array_map(
                    static fn(string $k, int $n): string => "$k $n",
                    array_keys($res['new']),
                    $res['new']
                ))));
                if ($res['errors'] !== []) {
                    Toolbox::logInFile('backupinfo', sprintf("Events %s: %s\n", $r['name'], implode('; ', $res['errors'])));
                    $log(sprintf('%s: %d event read error(s), see backupinfo.log', $r['name'], count($res['errors'])));
                }
            } catch (\Throwable $e) {
                $line = sprintf('Events %s: %s', $r['name'], SyncService::errorLine($e));
                Toolbox::logInFile('backupinfo', $line . "\n");
                $log($line);
            }
        }
        try {
            $cfg = Config::getConfigurationValues(SuiteConfig::CONTEXT, ['run_keep_months']);
            $store->purgeOlderThan((int) ($cfg['run_keep_months'] ?? 36), $now);
        } catch (\Throwable $e) {
            Toolbox::logInFile('backupinfo', 'Events purge: ' . SyncService::errorLine($e) . "\n");
        }
    }

    /** 0.4.0: Risk Register findings (off until enabled, only with riskregister). Never fails the sync. */
    private static function raiseFindings(int $now, callable $log): void
    {
        try {
            $res = FindingService::run($now);
            if ($res['written'] > 0 || $res['cleared'] > 0) {
                $log(sprintf('findings: %d written, %d cleared', $res['written'], $res['cleared']));
            }
            if ($res['errors'] !== []) {
                Toolbox::logInFile('backupinfo', 'Findings: ' . implode('; ', $res['errors']) . "\n");
                $log(sprintf('findings: %d error(s), see backupinfo.log', count($res['errors'])));
            }
        } catch (\Throwable $e) {
            $line = 'Findings: ' . SyncService::errorLine($e);
            Toolbox::logInFile('backupinfo', $line . "\n");
            $log($line);
        }
    }

    /**
     * 0.4.0: Incidents for new failed runs and Problems for repeated failures (off until enabled in
     * Setup). Never fails the sync.
     */
    private static function raiseTickets(int $now, callable $log): void
    {
        try {
            $res = TicketService::run(false, $now);
            if ($res['incidents'] !== [] || $res['problems'] !== [] || $res['links'] > 0 || $res['followups'] > 0) {
                $log(sprintf('tickets: %d incident(s), %d problem(s), %d link(s), %d follow-up(s)', count($res['incidents']), count($res['problems']), $res['links'], $res['followups']));
            }
            if ($res['errors'] !== []) {
                Toolbox::logInFile('backupinfo', 'Tickets: ' . implode('; ', $res['errors']) . "\n");
                $log(sprintf('tickets: %d error(s), see backupinfo.log', count($res['errors'])));
            }
        } catch (\Throwable $e) {
            $line = 'Tickets: ' . SyncService::errorLine($e);
            Toolbox::logInFile('backupinfo', $line . "\n");
            $log($line);
        }
    }

    /**
     * Audit evidence (0.3.0-rc7): today's backup targets and platform VMs of every connection whose
     * object sync succeeded (one snapshot a day), then the retention purge. Never fails the sync.
     *
     * @param list<array{id:int,name:string,status:string}> $results
     * @param array<int, mixed> $sources connection id => source
     */
    private static function storeSnapshots(array $results, array $sources, int $now, callable $log): void
    {
        $store = new DbSnapshotStore();
        foreach ($results as $r) {
            $source = $sources[$r['id']] ?? null;
            if ($r['status'] !== 'ok' || !($source instanceof AuditSource)) {
                continue;
            }
            try {
                $res = SnapshotSync::run((int) $r['id'], $source, $store, $now);
                $log(sprintf('%s: snapshot stored %s', $r['name'], implode(', ', array_map(
                    static fn(string $k, int $n): string => "$k $n",
                    array_keys($res['stored']),
                    $res['stored']
                ))));
                if ($res['errors'] !== []) {
                    Toolbox::logInFile('backupinfo', sprintf("Snapshot %s: %s\n", $r['name'], implode('; ', $res['errors'])));
                    $log(sprintf('%s: %d snapshot read error(s), see backupinfo.log', $r['name'], count($res['errors'])));
                }
            } catch (\Throwable $e) {
                $line = sprintf('Snapshot %s: %s', $r['name'], SyncService::errorLine($e));
                Toolbox::logInFile('backupinfo', $line . "\n");
                $log($line);
            }
        }
        try {
            $cfg = Config::getConfigurationValues(SuiteConfig::CONTEXT, ['run_keep_months']);
            $store->purgeOlderThan((int) ($cfg['run_keep_months'] ?? 36), $now);
        } catch (\Throwable $e) {
            Toolbox::logInFile('backupinfo', 'Snapshot purge: ' . SyncService::errorLine($e) . "\n");
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
                $byConn ??= RunResolve::lookups((new DbObjectStore())->forLookups());
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
     * Vinchin verifications of the in-scope objects' newest restore points become restore-test
     * records (option C). At most VerifyImport::PER_RUN reads per run, round-robin over the objects
     * (cursor in the plugin config). Never fails the sync.
     *
     * @param list<array{id:int,name:string,status:string}> $results
     * @param array<int, mixed> $sources
     */
    private static function importVerifications(array $results, array $sources, callable $log): void
    {
        try {
            $ok = [];
            foreach ($results as $r) {
                $source = $sources[$r['id']] ?? null;
                if ($r['status'] === 'ok' && is_object($source) && method_exists($source, 'verifications')) {
                    $ok[(int) $r['id']] = $source;
                }
            }
            if ($ok === []) {
                return;
            }

            $inScope = array_flip(Policy::fromConfig()->mandatoryIds());
            $targets = [];
            foreach ((new DbObjectStore())->matched() as $o) {
                $tp = trim((string) ($o['timepoint_uuid'] ?? ''));
                if ($tp !== '' && isset($ok[(int) $o['connections_id']], $inScope[(int) $o['items_id']])) {
                    $targets[$o['connections_id'] . '|' . $tp] = [(int) $o['connections_id'], $tp, (int) $o['items_id']];
                }
            }
            ksort($targets);

            $cfg = Config::getConfigurationValues(SuiteConfig::CONTEXT, ['verify_cursor']);
            [$batch, $next] = VerifyImport::batch(array_keys($targets), (int) ($cfg['verify_cursor'] ?? 0), VerifyImport::PER_RUN);
            $added = 0;
            $errors = 0;
            foreach ($batch as $key) {
                [$conn, $tp, $computerId] = $targets[$key];
                $res = $ok[$conn]->verifications($tp);
                if ($res['error'] !== '') {
                    $errors++;
                    continue;
                }
                foreach ($res['rows'] as $row) {
                    $input = VerifyImport::toInput($row, $computerId);
                    if ($input !== null && RestoreTest::importVinchin($input)) {
                        $added++;
                    }
                }
            }
            Config::setConfigurationValues(SuiteConfig::CONTEXT, ['verify_cursor' => $next]);
            if ($batch !== []) {
                $log(sprintf('verifications: %d restore point(s) read, %d imported, %d read error(s)', count($batch), $added, $errors));
            }
        } catch (\Throwable $e) {
            Toolbox::logInFile('backupinfo', 'Verifications: ' . SyncService::errorLine($e) . "\n");
            $log('verifications: ' . SyncService::errorLine($e));
        }
    }
}
