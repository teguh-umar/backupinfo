<?php

namespace GlpiPlugin\Backupinfo\Tickets;

use CommonITILObject;
use Config;
use GlpiPlugin\Backupinfo\AuditData;
use GlpiPlugin\Backupinfo\Policy;
use GlpiPlugin\Backupinfo\SuiteConfig;
use Item_Problem;
use Problem;
use Problem_Ticket;
use ProblemTemplatePredefinedField;
use Ticket;
use TicketTemplatePredefinedField;

/**
 * Opens the plugin's Incidents and Problems (0.4.0). Needs GLPI. Only adds tickets and links:
 * never edits, solves or closes one. Off until "tickets_enabled"; runs that started before it was
 * enabled never open a ticket. A dry run writes nothing and says what would be created.
 */
final class TicketService
{
    public const TABLE = 'glpi_plugin_backupinfo_tickets';
    public const TICKET_TEMPLATE = 'Backup Info - Backup failed';
    public const PROBLEM_TEMPLATE = 'Backup Info - Repeated backup failure';

    /**
     * @return array{incidents: list<string>, problems: list<string>, links: int, warnings: list<string>, errors: list<string>}
     */
    public static function run(bool $dry, int $nowS): array
    {
        global $DB;

        $out = ['incidents' => [], 'problems' => [], 'links' => 0, 'warnings' => [], 'errors' => []];
        $cfg = Config::getConfigurationValues(SuiteConfig::CONTEXT, ['tickets_enabled', 'tickets_since', 'ticket_template', 'problem_template', 'problem_count', 'problem_days']);
        if (!$dry && empty($cfg['tickets_enabled'])) {
            return $out;
        }
        $since = (string) ($cfg['tickets_since'] ?? '');
        if ($since === '') {
            $since = date('Y-m-d H:i:s', $nowS); // dry run before enabling: as if enabled now
        }

        $computers = self::computers(Policy::fromConfig()->mandatoryIds());
        $names     = array_map(static fn(array $c): string => $c['name'], $computers);

        $tt   = (int) ($cfg['ticket_template'] ?? 0);
        $pt   = (int) ($cfg['problem_template'] ?? 0);
        $tPre = $tt > 0 ? (new TicketTemplatePredefinedField())->getPredefinedFields($tt, true) : [];
        $pPre = $pt > 0 ? (new ProblemTemplatePredefinedField())->getPredefinedFields($pt, true) : [];
        $out['warnings'] = array_merge(
            TicketInput::warnings($tPre, $tt > 0 ? self::templateName('glpi_tickettemplates', $tt) : ''),
            TicketInput::warnings($pPre, $pt > 0 ? self::templateName('glpi_problemtemplates', $pt) : '')
        );

        // 1. Incidents
        $linked = [];
        foreach ($DB->request(['SELECT' => ['runs_id', 'computers_id'], 'FROM' => self::TABLE]) as $l) {
            $linked[$l['runs_id'] . '|' . $l['computers_id']] = true;
        }
        $rows = AuditData::runRows(array_keys($names), $since, date('Y-m-d H:i:s', $nowS));
        foreach (IncidentPlan::plan($rows, $linked, $since, $names) as $i) {
            $out['incidents'][] = $i['name'];
            if ($dry) {
                continue;
            }
            $ticket = new Ticket();
            $id     = $ticket->add(TicketInput::build($tPre, [
                'name'               => $i['name'],
                'content'            => $i['content'],
                'type'               => Ticket::INCIDENT_TYPE,
                'entities_id'        => $computers[$i['computers_id']]['entities_id'],
                'tickettemplates_id' => $tt,
                'items_id'           => ['Computer' => [$i['computers_id']]],
            ]));
            if (!$id) {
                $out['errors'][] = 'Ticket not created: ' . $i['name'];
                continue;
            }
            $DB->insert(self::TABLE, ['runs_id' => $i['runs_id'], 'computers_id' => $i['computers_id'], 'tickets_id' => (int) $id,
                'problems_id' => 0, 'date_creation' => date('Y-m-d H:i:s', $nowS)]);
        }

        // 2. Problems, on the Incidents stored so far (a dry run does not count the would-be ones)
        $incidents = [];
        foreach ($DB->request([
            'SELECT'     => ['t.tickets_id', 't.computers_id', 't.problems_id', 'r.start_time'],
            'FROM'       => self::TABLE . ' AS t',
            'INNER JOIN' => ['glpi_plugin_backupinfo_runs AS r' => ['ON' => ['t' => 'runs_id', 'r' => 'id']]],
        ]) as $r) {
            $incidents[(int) $r['computers_id']][] = ['tickets_id' => (int) $r['tickets_id'], 'start_time' => (string) $r['start_time'], 'problems_id' => (int) $r['problems_id']];
        }
        $plan = ProblemPlan::plan($incidents, self::openProblems(), max(1, (int) ($cfg['problem_count'] ?? 3)), max(1, (int) ($cfg['problem_days'] ?? 30)), $nowS);
        $out['links'] = count($plan['link']);
        foreach ($plan['open'] as $c => $ticketIds) {
            $name               = sprintf('Repeated backup failure: %s (%d failures)', $names[$c] ?? ('#' . $c), count($ticketIds));
            $out['problems'][]  = $name;
            if ($dry) {
                continue;
            }
            $problem = new Problem();
            $pid     = $problem->add(TicketInput::build($pPre, [
                'name'                => $name,
                'content'             => '<p>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '. Opened automatically by Backup Info; the Incidents are linked.</p>',
                'entities_id'         => $computers[$c]['entities_id'] ?? 0,
                'problemtemplates_id' => $pt,
            ]));
            if (!$pid) {
                $out['errors'][] = 'Problem not created: ' . $name;
                continue;
            }
            (new Item_Problem())->add(['problems_id' => (int) $pid, 'itemtype' => 'Computer', 'items_id' => $c]);
            foreach ($ticketIds as $tid) {
                self::link((int) $pid, $tid);
            }
        }
        if (!$dry) {
            foreach ($plan['link'] as [$pid, $tid]) {
                self::link($pid, $tid);
            }
        }
        return $out;
    }

    private static function link(int $problemId, int $ticketId): void
    {
        global $DB;

        (new Problem_Ticket())->add(['problems_id' => $problemId, 'tickets_id' => $ticketId]);
        $DB->update(self::TABLE, ['problems_id' => $problemId], ['tickets_id' => $ticketId]);
    }

    /** @return array<int, int> Computer => open plugin Problem (not solved or closed) */
    public static function openProblems(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'     => ['t.computers_id', 't.problems_id'],
            'FROM'       => self::TABLE . ' AS t',
            'INNER JOIN' => ['glpi_problems AS p' => ['ON' => ['t' => 'problems_id', 'p' => 'id']]],
            'WHERE'      => ['p.is_deleted' => 0, ['NOT' => ['p.status' => [CommonITILObject::SOLVED, CommonITILObject::CLOSED]]]],
        ]) as $r) {
            $out[(int) $r['computers_id']] = (int) $r['problems_id'];
        }
        return $out;
    }

    /** @return array<int, array{name: string, entities_id: int}> mandatory Computers, all entities (cron) */
    private static function computers(array $ids): array
    {
        global $DB;

        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'entities_id'], 'FROM' => 'glpi_computers',
                'WHERE' => ['id' => $chunk, 'is_deleted' => 0, 'is_template' => 0]]) as $c) {
                $out[(int) $c['id']] = ['name' => (string) $c['name'], 'entities_id' => (int) $c['entities_id']];
            }
        }
        return $out;
    }

    private static function templateName(string $table, int $id): string
    {
        global $DB;

        foreach ($DB->request(['SELECT' => ['name'], 'FROM' => $table, 'WHERE' => ['id' => $id]]) as $r) {
            return (string) $r['name'];
        }
        return '#' . $id;
    }

    /** Creates the two templates once (install/upgrade) and selects them when nothing is selected. */
    public static function ensureTemplates(): void
    {
        global $DB;

        $cfg = Config::getConfigurationValues(SuiteConfig::CONTEXT, ['ticket_template', 'problem_template']);
        foreach ([
            'ticket_template'  => ['glpi_tickettemplates', \TicketTemplate::class, self::TICKET_TEMPLATE],
            'problem_template' => ['glpi_problemtemplates', \ProblemTemplate::class, self::PROBLEM_TEMPLATE],
        ] as $key => [$table, $class, $name]) {
            if (!empty($cfg[$key])) {
                continue;
            }
            $id = 0;
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => ['name' => $name]]) as $r) {
                $id = (int) $r['id'];
            }
            if ($id === 0) {
                $id = (int) (new $class())->add(['name' => $name, 'entities_id' => 0, 'is_recursive' => 1,
                    'comment' => 'Used by Backup Info for its automatic tickets: set the requester, groups, supplier and category here.']);
            }
            if ($id > 0) {
                Config::setConfigurationValues(SuiteConfig::CONTEXT, [$key => $id]);
            }
        }
    }
}
