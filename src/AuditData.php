<?php

namespace GlpiPlugin\Backupinfo;

use GlpiPlugin\Backupinfo\Source\AuditSource;
use GlpiPlugin\Backupinfo\Source\Registry;
use GlpiPlugin\Backupinfo\Sync\DbObjectStore;
use GlpiPlugin\Backupinfo\Sync\DbEventStore;
use GlpiPlugin\Backupinfo\Sync\DbRunStore;
use GlpiPlugin\Backupinfo\Sync\DbSnapshotStore;
use Session;

/**
 * Data for the audit evidence page (needs GLPI). In scope = the mandatory ("Wajib Backup") Computers
 * in the user's active entities. Every section reads GLPI tables (0.3.0-rc7): what the backup system only shows "as of now"
 * (targets, platform VMs) comes from the daily snapshots the sync stores.
 */
final class AuditData
{
    /** @var array<int, array{name: string, source: AuditSource}>|null */
    private ?array $sources = null;

    /** @var array<int, string> */
    public readonly array $computers;

    private bool $showOwn = false;

    /** Mandatory Computers before the entity filter (to say why the page is empty). */
    public readonly int $mandatoryTotal;

    /** @var array<int, list<array<string, mixed>>> */
    private ?array $objects = null;

    /** @var list<array<string, mixed>>|null runs x Computers of the period (population and failures) */
    private ?array $runs = null;

    /** @param array{key: string, label: string, from: string, to: string} $period */
    public function __construct(public readonly array $period)
    {
        $ids                  = Policy::fromConfig()->mandatoryIds();
        $this->mandatoryTotal = count($ids);
        $this->computers      = self::inScope($ids);
    }

    /**
     * One section: the builder's table plus the live read errors (one line each, no secret).
     *
     * @return array{columns: list<string>, rows: list<list<scalar|null>>, note: string, errors: list<string>}
     */
    public function section(string $key): array
    {
        $errors = [];
        $from   = $this->period['from'];
        $to     = $this->period['to'];
        $ids    = array_keys($this->computers);

        $table = match ($key) {
            'policy'     => AuditPage::policy($this->computers, $this->objects(), Rpo::fromConfig()),
            'coverage'   => AuditPage::coverage($this->computers, $this->objects(), Rpo::fromConfig(), ($this->computers === [] ? [] : $this->snapshotRows('platformvm')), time()),
            'population' => AuditPage::population($this->computers, $this->runs ??= self::runRows($ids, $from, $to)),
            'failures'   => AuditPage::failures($this->computers, $this->runs ??= self::runRows($ids, $from, $to)),
            'restore'    => AuditPage::restore($this->computers, self::tests($ids, substr($to, 0, 10)), RestoreInterval::overrides($ids),
                RestoreInterval::globalMonths(), substr($from, 0, 10), substr($to, 0, 10)),
            'copies'     => AuditPage::copies($this->computers, $this->objects(), ($this->computers === [] ? [] : $this->events('copy', $from, $to))),
            'activity'   => $this->activity($from, $to, $errors),
            'targets'    => AuditPage::targets($this->snapshots('target', true), $this->snapshots('target', false), $this->connectionIdNames()),
            default      => throw new \InvalidArgumentException('unknown section'),
        };
        return $table + ['errors' => $errors];
    }

    /** @return list<string> names of the active connections the live sections read */
    public function connectionNames(): array
    {
        return array_values(array_map(static fn(array $s): string => $s['name'], $this->sources()));
    }

    private function activity(string $from, string $to, array &$errors): array
    {
        $jobLogs = $this->events('joblog', $from, $to);
        // Logins carry source IPs and admin names: configuration right only.
        if (!Session::haveRight(RightDefinition::CONFIG, READ)) {
            $table          = AuditPage::activity($jobLogs, [], [], [], true);
            $table['note'] .= ' ' . __('System log and system alarms are shown to Backup Info configuration users only.', 'backupinfo');
            return $table;
        }
        return AuditPage::activity($jobLogs, $this->events('syslog', $from, $to), $this->events('sysalarm', $from, $to), self::ownIps(), $this->showOwn);
    }

    /** Lists the logins of this GLPI server too (the page's "Show this server's logins"). */
    public function showOwnLogins(bool $show): void
    {
        $this->showOwn = $show;
    }

    /**
     * Addresses of this GLPI server, whose logins to the backup system are the plugin's own: the
     * Setup list, else the address the web server answers on.
     *
     * @return list<string>
     */
    public static function ownIps(): array
    {
        $cfg = \Config::getConfigurationValues(SuiteConfig::CONTEXT, ['own_ips']);
        $ips = preg_split('/[\s,;]+/', trim((string) ($cfg['own_ips'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($ips === [] && !empty($_SERVER['SERVER_ADDR'])) {
            $ips = [(string) $_SERVER['SERVER_ADDR']];
        }
        return array_values(array_filter($ips, static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
    }

    /** Stored events of the active connections (the sync keeps them; see EventSync). */
    private function events(string $kind, string $from, string $to): array
    {
        return (new DbEventStore())->rows($kind, $from, $to, array_keys($this->sources()));
    }

    /**
     * Per active connection, the newest ($last) or oldest snapshot of $kind taken in the period.
     *
     * @return array<int, array{taken_at: string, rows: list<array<string, mixed>>}>
     */
    private function snapshots(string $kind, bool $last): array
    {
        return (new DbSnapshotStore())->pick($kind, array_keys($this->sources()), $this->period['from'], $this->period['to'], $last);
    }

    /** Rows of the newest snapshot of $kind in the period, every connection merged. */
    private function snapshotRows(string $kind): array
    {
        $rows = [];
        foreach ($this->snapshots($kind, true) as $snap) {
            array_push($rows, ...$snap['rows']);
        }
        return $rows;
    }

    /** @return array<int, string> active connections, id => name */
    private function connectionIdNames(): array
    {
        return array_map(static fn(array $s): string => $s['name'], $this->sources());
    }

    /** @return array<int, array{name: string, source: AuditSource}> */
    private function sources(): array
    {
        global $DB;

        if ($this->sources === null) {
            $this->sources = [];
            foreach ($DB->request(['FROM' => 'glpi_plugin_backupinfo_connections', 'WHERE' => ['is_active' => 1], 'ORDER' => ['name']]) as $conn) {
                try {
                    $source = Registry::create($conn, Cron::decryptor(), [Cron::class, 'computerNames']);
                } catch (\Throwable $e) {
                    continue;
                }
                if ($source instanceof AuditSource) {
                    $this->sources[(int) $conn['id']] = ['name' => (string) $conn['name'], 'source' => $source];
                }
            }
        }
        return $this->sources;
    }

    /** @return array<int, list<array<string, mixed>>> */
    private function objects(): array
    {
        return $this->objects ??= Policy::groupByItem(array_values(array_filter(
            (new DbObjectStore())->matched(),
            fn(array $o): bool => isset($this->computers[(int) $o['items_id']])
        )));
    }

    /**
     * @param list<int> $ids mandatory Computers
     * @return array<int, string> those visible in the active entities, id => name
     */
    private static function inScope(array $ids): array
    {
        global $DB;

        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => ['id' => $chunk, 'is_deleted' => 0, 'is_template' => 0] + getEntitiesRestrictCriteria('glpi_computers'),
                'ORDER'  => ['name'],
            ]) as $row) {
                $out[(int) $row['id']] = (string) $row['name'];
            }
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * Runs x in-scope Computers started in [from, to], newest first. The Computer is decided now
     * (RunResolve), so a VM matched or pinned after its runs were stored still counts.
     *
     * @return list<array<string, mixed>>
     */
    public static function runRows(array $ids, string $from, string $to): array
    {
        global $DB;

        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_plugin_backupinfo_connections']) as $c) {
            $names[(int) $c['id']] = (string) $c['name'];
        }
        $rows = [];
        foreach ($DB->request([
            'SELECT'    => ['r.id AS runs_id', 'r.history_uuid', 'r.connections_id', 'r.job_name', 'r.start_time', 'r.finish_time', 'r.status',
                'r.size_text', 'r.duration_s', 'r.reason', 'i.computers_id', 'i.vm_name', 'i.item_status'],
            'FROM'      => DbRunStore::RUNS . ' AS r',
            'LEFT JOIN' => [DbRunStore::ITEMS . ' AS i' => ['ON' => ['i' => 'runs_id', 'r' => 'id']]],
            'WHERE'     => [
                ['r.start_time' => ['>=', $from]],
                ['r.start_time' => ['<=', $to]],
            ],
        ]) as $row) {
            $rows[] = $row;
        }
        $out = RunResolve::resolve($rows, RunResolve::lookups((new DbObjectStore())->forLookups()), array_fill_keys($ids, true));
        foreach ($out as &$row) {
            $row['connection'] = $names[(int) $row['connections_id']] ?? '';
        }
        unset($row);
        usort($out, static fn(array $a, array $b): int => [(string) $b['start_time'], (string) $a['vm_name']] <=> [(string) $a['start_time'], (string) $b['vm_name']]);
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function tests(array $ids, string $toDate): array
    {
        global $DB;

        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($DB->request([
                'SELECT' => ['computers_id', 'date', 'method', 'result', 'source'],
                'FROM'   => RestoreTest::TABLE,
                'WHERE'  => ['computers_id' => $chunk, 'date' => ['<=', $toDate]],
            ]) as $row) {
                $out[] = $row;
            }
        }
        return $out;
    }
}
