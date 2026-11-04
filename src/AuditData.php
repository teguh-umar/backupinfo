<?php

namespace GlpiPlugin\Backupinfo;

use GlpiPlugin\Backupinfo\Source\AuditSource;
use GlpiPlugin\Backupinfo\Source\Registry;
use GlpiPlugin\Backupinfo\Sync\DbObjectStore;
use GlpiPlugin\Backupinfo\Sync\DbRunStore;
use Session;

/**
 * Data for the audit evidence page (needs GLPI). In scope = the mandatory ("Wajib Backup") Computers
 * in the user's active entities. Sections 3-5 come from GLPI tables; 1, 2, 6 and 7 are read live.
 */
final class AuditData
{
    /** @var array<int, array{name: string, source: AuditSource}>|null */
    private ?array $sources = null;

    /** @var array<int, string> */
    public readonly array $computers;

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
            'coverage'   => AuditPage::coverage($this->computers, $this->objects(), Rpo::fromConfig(), ($this->computers === [] ? [] : $this->live('platformVms', [], $errors)), time()),
            'population' => AuditPage::population($this->computers, $this->runs ??= $this->readRuns($ids, $from, $to)),
            'failures'   => AuditPage::failures($this->computers, $this->runs ??= $this->readRuns($ids, $from, $to)),
            'restore'    => AuditPage::restore($this->computers, self::tests($ids, substr($to, 0, 10)), RestoreInterval::overrides($ids),
                RestoreInterval::globalMonths(), substr($from, 0, 10), substr($to, 0, 10)),
            'copies'     => AuditPage::copies($this->computers, $this->objects(), ($this->computers === [] ? [] : $this->live('copyRuns', [$from, $to], $errors))),
            'activity'   => $this->activity($from, $to, $errors),
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
        $jobLogs = $this->live('jobLogs', [$from, $to], $errors);
        // Logins carry source IPs and admin names: configuration right only.
        if (!Session::haveRight(RightDefinition::CONFIG, READ)) {
            $table          = AuditPage::activity($jobLogs, [], []);
            $table['note'] .= ' ' . __('System log and system alarms are shown to Backup Info configuration users only.', 'backupinfo');
            return $table;
        }
        return AuditPage::activity($jobLogs, $this->live('systemLogs', [$from, $to], $errors), $this->live('systemAlarms', [$from, $to], $errors));
    }

    /** Merged rows of one AuditSource method over every active connection (cached a short while). */
    private function live(string $method, array $args, array &$errors): array
    {
        global $GLPI_CACHE;

        $rows = [];
        $ttl  = AuditCache::ttl($this->period['to'], time());
        foreach ($this->sources() as $id => $s) {
            $res = AuditCache::remember(
                is_object($GLPI_CACHE) ? $GLPI_CACHE : null,
                AuditCache::key($id, $method, $args),
                $ttl,
                static fn(): array => $s['source']->$method(...$args)
            );
            if ($res['error'] !== '') {
                $errors[] = $s['name'] . ': ' . $res['error'];
            }
            array_push($rows, ...$res['rows']);
        }
        return $rows;
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

    /** @return list<array<string, mixed>> runs x Computers started in [from, to], newest first */
    private function readRuns(array $ids, string $from, string $to): array
    {
        global $DB;

        $names = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_plugin_backupinfo_connections']) as $c) {
            $names[(int) $c['id']] = (string) $c['name'];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($DB->request([
                'SELECT'     => ['r.history_uuid', 'r.connections_id', 'r.job_name', 'r.start_time', 'r.finish_time', 'r.status', 'r.size_text',
                    'r.duration_s', 'r.reason', 'i.computers_id', 'i.vm_name', 'i.item_status'],
                'FROM'       => DbRunStore::ITEMS . ' AS i',
                'INNER JOIN' => [DbRunStore::RUNS . ' AS r' => ['ON' => ['i' => 'runs_id', 'r' => 'id']]],
                'WHERE'      => [
                    'i.computers_id' => $chunk,
                    ['r.start_time' => ['>=', $from]],
                    ['r.start_time' => ['<=', $to]],
                ],
                'ORDER'      => ['r.start_time DESC', 'i.vm_name'],
            ]) as $row) {
                $row['connection'] = $names[(int) $row['connections_id']] ?? '';
                $out[] = $row;
            }
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string) $b['start_time'], (string) $a['start_time']));
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
