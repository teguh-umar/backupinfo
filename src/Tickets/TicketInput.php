<?php

namespace GlpiPlugin\Backupinfo\Tickets;

/**
 * Builds the add input of a plugin Ticket or Problem from its template's predefined fields (0.4.0).
 * GLPI 11 applies predefined fields in the form only, not on a code add, so the plugin merges them:
 * actors, category, urgency, impact... come from the template; the plugin's own keys win. Pure.
 */
final class TicketInput
{
    /** Template keys the plugin always sets itself. */
    private const OWN = ['name', 'content', 'type', 'entities_id', 'items_id', 'status'];

    /**
     * @param array<string, mixed> $predefined template field => value (actors as lists)
     * @param array<string, mixed> $own        the plugin's keys (name, content, entity, type, item...)
     * @return array<string, mixed>
     */
    public static function build(array $predefined, array $own): array
    {
        return $own + array_diff_key($predefined, array_flip(self::OWN));
    }

    /**
     * Splits the add input into what is posted to GLPI and the technicians
     * to link afterwards, so production business rules (Behaviors, Escalade)
     * cannot re-route the ticket on add.
     *
     * `_users_id_assign` may be an int or a list; the result is a list of
     * positive ints. Everything else (groups, observers, requester, category)
     * is unchanged, and `_skip_rules => true` is set so GLPI does not run the
     * rules on the add itself.
     *
     * @param array<string, mixed> $input
     * @return array{add: array<string, mixed>, technicians: list<int>}
     */
    public static function split(array $input): array
    {
        $raw = $input['_users_id_assign'] ?? null;
        $techs = [];
        if (is_array($raw)) {
            foreach ($raw as $u) {
                $u = (int) $u;
                if ($u > 0) {
                    $techs[] = $u;
                }
            }
        } elseif ($raw !== null) {
            $u = (int) $raw;
            if ($u > 0) {
                $techs[] = $u;
            }
        }
        $add = $input;
        unset($add['_users_id_assign']);
        $add['_skip_rules'] = true;
        return ['add' => $add, 'technicians' => $techs];
    }

    /**
     * What a template lacks for a ticket somebody will act on (shown by the dry run).
     *
     * @return list<string>
     */
    public static function warnings(array $predefined, string $template): array
    {
        if ($template === '') {
            return [__('No template is selected: tickets would have no requester, group or supplier.', 'backupinfo')];
        }
        $out = [];
        if (empty($predefined['_groups_id_assign']) && empty($predefined['_suppliers_id_assign']) && empty($predefined['_users_id_assign'])) {
            $out[] = sprintf(__('Template "%s" assigns nobody (no group, supplier or technician).', 'backupinfo'), $template);
        }
        if (empty($predefined['_users_id_requester']) && empty($predefined['_groups_id_requester'])) {
            $out[] = sprintf(__('Template "%s" has no requester.', 'backupinfo'), $template);
        }
        if (empty($predefined['itilcategories_id'])) {
            $out[] = sprintf(__('Template "%s" sets no category; GLPI may refuse to solve or close the ticket without one.', 'backupinfo'), $template);
        }
        $groups = $predefined['_groups_id_assign'] ?? null;
        if (is_array($groups) && count($groups) > 1) {
            $out[] = sprintf(__('Template "%1$s" assigns %2$d groups; only one is kept on production (Escalade). Assign one group and put other teams as observers or technicians.', 'backupinfo'), $template, count($groups));
        }
        return $out;
    }
}
