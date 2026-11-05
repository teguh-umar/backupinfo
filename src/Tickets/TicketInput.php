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
        return $out;
    }
}
