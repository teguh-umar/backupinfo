<?php

namespace GlpiPlugin\Backupinfo\Tickets;

use Config;
use GlpiPlugin\Backupinfo\AuditData;
use GlpiPlugin\Backupinfo\AuditPage;
use GlpiPlugin\Backupinfo\Policy;
use GlpiPlugin\Backupinfo\RestoreInterval;
use GlpiPlugin\Backupinfo\Rpo;
use GlpiPlugin\Backupinfo\SuiteConfig;
use GlpiPlugin\Backupinfo\Sync\DbObjectStore;

/**
 * Writes the plugin's Risk Register findings (0.4.0), following SAC's FindingWriter: only when
 * riskregister is active, class API only, upsert by (entity, code), never touches status, never
 * recursive, Computers linked once, ISO 27001 A.8.13 linked once, "Signal cleared" note when the
 * signal goes. Needs GLPI. Off until "findings_enabled".
 */
final class FindingService
{
    private const REF = 'BackupInfo/';

    public static function isAvailable(): bool
    {
        return \Plugin::isPluginActive('riskregister') && class_exists('\GlpiPlugin\Riskregister\Finding')
            && class_exists('\GlpiPlugin\Riskregister\Finding_Item');
    }

    /** @return array{written: int, cleared: int, errors: list<string>} */
    public static function run(int $nowS): array
    {
        global $DB;

        $out = ['written' => 0, 'cleared' => 0, 'errors' => []];
        $cfg = Config::getConfigurationValues(SuiteConfig::CONTEXT, ['findings_enabled']);
        if (empty($cfg['findings_enabled']) || !self::isAvailable()) {
            return $out;
        }

        $ids       = Policy::fromConfig()->mandatoryIds();
        $computers = [];
        $entityOf  = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'entities_id'], 'FROM' => 'glpi_computers',
                'WHERE' => ['id' => $chunk, 'is_deleted' => 0, 'is_template' => 0]]) as $c) {
                $computers[(int) $c['id']] = (string) $c['name'];
                $entityOf[(int) $c['id']]  = (int) $c['entities_id'];
            }
        }
        $list    = array_keys($computers);
        $objects = Policy::groupByItem(array_values(array_filter((new DbObjectStore())->matched(), static fn(array $o): bool => isset($computers[(int) $o['items_id']]))));
        $today   = date('Y-m-d', $nowS);

        // One row per Computer, in $computers order (AuditPage iterates it).
        $cov     = AuditPage::coverage($computers, $objects, Rpo::fromConfig(), [], $nowS)['rows'];
        $res     = AuditPage::restore($computers, AuditData::restoreTests($list, $today), RestoreInterval::overrides($list),
            RestoreInterval::globalMonths(), $today, $today)['rows'];
        $signals = ['BCKP-COVERAGE' => [], 'BCKP-RESTORE' => [], 'BCKP-REPEAT' => array_keys(TicketService::openProblems())];
        foreach ($list as $i => $c) {
            if (($cov[$i][1] ?? 'protected') !== 'protected') {
                $signals['BCKP-COVERAGE'][] = $c;
            }
            if (in_array($res[$i][4] ?? '', [RestoreInterval::NONE, RestoreInterval::OVERDUE], true)) {
                $signals['BCKP-RESTORE'][] = $c;
            }
        }

        $finding  = new \GlpiPlugin\Riskregister\Finding();
        // 0.4.0-rc2: rc1 wrote BI-* codes (BI reads as Business Intelligence): renamed once, status kept
        foreach ($finding->find(['assessment_ref' => ['LIKE', self::REF . 'BI-%']]) as $old) {
            $code = 'BCKP-' . substr((string) $old['code'], 3);
            $finding->update(['id' => (int) $old['id'], 'code' => $code, 'assessment_ref' => self::REF . $code]);
        }
        $existing = [];
        foreach ($finding->find(['assessment_ref' => ['LIKE', self::REF . '%'], 'is_deleted' => 0]) as $row) {
            $existing[$row['entities_id'] . '|' . $row['code']] = true;
        }
        $control = self::control();
        foreach (FindingPlan::plan($signals, $entityOf, $existing) as $f) {
            try {
                $hit = $finding->find(['entities_id' => $f['entities_id'], 'code' => $f['code']]);
                $id  = $hit ? (int) array_key_first($hit) : 0;
                if ($f['cleared']) {
                    $content = (string) ($hit[$id]['content'] ?? '');
                    if ($id > 0 && !str_contains($content, 'Signal cleared')) {
                        $finding->update(['id' => $id, 'is_recursive' => 0, 'content' => $content . '<p>Signal cleared on ' . $today . '.</p>']);
                        $out['cleared']++;
                    }
                    continue;
                }
                [$name, , $severity, $recommendation] = FindingPlan::CODES[$f['code']];
                $input = [
                    'entities_id'    => $f['entities_id'],
                    'is_recursive'   => 0,
                    'code'           => $f['code'],
                    'name'           => $name,
                    'content'        => FindingPlan::content($f['code'], array_map(static fn(int $c): string => $computers[$c], $f['computers'])),
                    'severity'       => $severity,
                    'recommendation' => $recommendation,
                    'scope_label'    => mb_substr(sprintf('%d computer(s)', count($f['computers'])), 0, 255),
                    'assessment_ref' => self::REF . $f['code'],
                ];
                if ($id > 0) {
                    $finding->update(['id' => $id] + $input); // the status a person set is kept
                } else {
                    $id = (int) $finding->add($input);
                }
                if ($id <= 0) {
                    $out['errors'][] = 'Finding not written: ' . $f['code'] . ' entity ' . $f['entities_id'];
                    continue;
                }
                foreach ($f['computers'] as $c) {
                    self::linkOnce('\GlpiPlugin\Riskregister\Finding_Item', ['plugin_riskregister_findings_id' => $id, 'itemtype' => 'Computer', 'items_id' => $c]);
                }
                if ($control > 0 && class_exists('\GlpiPlugin\Riskregister\Finding_FrameworkControl')) {
                    self::linkOnce('\GlpiPlugin\Riskregister\Finding_FrameworkControl', ['plugin_riskregister_findings_id' => $id, 'plugin_riskregister_frameworkcontrols_id' => $control]);
                }
                $out['written']++;
            } catch (\Throwable $e) {
                $out['errors'][] = $f['code'] . ': ' . $e->getMessage();
            }
        }
        return $out;
    }

    private static function linkOnce(string $class, array $crit): void
    {
        $link = new $class();
        if (!$link->find($crit)) {
            $link->add($crit);
        }
    }

    /** ISO 27001:2022 A.8.13 "Information backup" in the Risk Register's framework controls, else 0. */
    private static function control(): int
    {
        global $DB;

        foreach ($DB->request([
            'SELECT'     => ['c.id'],
            'FROM'       => 'glpi_plugin_riskregister_frameworkcontrols AS c',
            'INNER JOIN' => ['glpi_plugin_riskregister_frameworks AS f' => ['ON' => ['c' => 'plugin_riskregister_frameworks_id', 'f' => 'id']]],
            'WHERE'      => ['c.ref_code' => 'A.8.13', 'f.name' => ['LIKE', '%27001%']],
            'LIMIT'      => 1,
        ]) as $r) {
            return (int) $r['id'];
        }
        return 0;
    }
}
