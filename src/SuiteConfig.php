<?php

namespace GlpiPlugin\Backupinfo;

use Computer;
use Config;
use Dropdown;
use GlpiPlugin\Backupinfo\Fields\FieldsBridge;
use GlpiPlugin\Backupinfo\Source\Registry;
use Html;
use Session;

/**
 * Backup Info's section on the shared PGN Suite settings page (hub §5). The forms post to
 * front/config.php, which saves and returns here through ConfigTab::getReturnUrl().
 */
class SuiteConfig
{
    public const SECTION = 'backupinfo';
    public const CONTEXT = 'plugin:backupinfo';

    public const RPO_MIN = 1;
    public const RPO_MAX = 720;

    private const FIELD_KEYS = ['fields_container', 'fields_status_field', 'fields_media_field', 'fields_mandatory_field'];

    /**
     * Section provider for the shared PGN Suite settings page (pgnsuite_config_section).
     * Returns null when the current user may not see the section.
     */
    public static function section(): ?array
    {
        if (!Session::haveRight(RightDefinition::CONFIG, READ)) {
            return null;
        }
        return [
            'title'  => __('Backup Info', 'backupinfo'),
            'icon'   => 'ti ti-database-export',
            'order'  => 500,
            'render' => self::class . '::render',
        ];
    }

    /**
     * Pure: the config values to store from a POST, and the errors to show.
     * Only keys present in $post are returned, so a partial POST changes nothing else.
     * Fields mapping values are names (FieldsBridge resolves them by system name, then
     * by unique label), never ids.
     *
     * @param array<string, mixed> $post
     * @return array{values: array<string, int|string>, errors: list<string>}
     */
    public static function sanitize(array $post): array
    {
        $values = [];
        $errors = [];

        if (array_key_exists('rpo_hours', $post)) {
            $raw = trim((string) $post['rpo_hours']);
            if (preg_match('/^\d+$/', $raw) === 1 && (int) $raw >= self::RPO_MIN && (int) $raw <= self::RPO_MAX) {
                $values['rpo_hours'] = (int) $raw;
            } else {
                $errors[] = sprintf(__('RPO must be a whole number of hours from %1$d to %2$d; it was not changed.', 'backupinfo'), self::RPO_MIN, self::RPO_MAX);
            }
        }
        if (array_key_exists('policy_mode', $post)) {
            $values['policy_mode'] = (string) $post['policy_mode'] === Policy::MODE_MANUAL ? Policy::MODE_MANUAL : Policy::MODE_FIELD;
        }
        foreach (self::FIELD_KEYS as $key) {
            if (array_key_exists($key, $post)) {
                $values[$key] = substr(trim((string) $post[$key]), 0, 255);
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    public static function render(): void
    {
        $canedit = Session::haveRight(RightDefinition::CONFIG, UPDATE);
        $action  = Html::getPrefixedUrl('/plugins/backupinfo/front/config.php');
        $cfg     = Config::getConfigurationValues(self::CONTEXT, array_merge(['rpo_hours', 'policy_mode'], self::FIELD_KEYS));
        $bridge  = FieldsBridge::fromConfig();

        self::connections();

        // Fields warnings first: they explain why Backup Status is not being written.
        $problems = $bridge->problems();
        if (!$bridge->available() || $problems !== []) {
            echo '<div class="alert alert-warning"><strong>' . __s('Fields is not (fully) updated by the sync:', 'backupinfo') . '</strong><ul class="mb-0">';
            foreach ($problems as $problem) {
                echo '<li>' . htmlescape($problem) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<form method="post" action="' . htmlescape($action) . '">';
        echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">' . __s('Backup policy', 'backupinfo') . '</h3></div><div class="card-body">';

        echo '<div class="mb-3"><label class="form-label" for="backupinfo-rpo">' . __s('RPO (hours): a backup older than this is "expired"', 'backupinfo') . '</label>';
        echo '<input type="number" min="' . self::RPO_MIN . '" max="' . self::RPO_MAX . '" step="1" class="form-control" id="backupinfo-rpo" name="rpo_hours" value="'
            . (int) ($cfg['rpo_hours'] ?? 26) . '"' . ($canedit ? '' : ' disabled') . '></div>';

        $mode = (string) ($cfg['policy_mode'] ?? Policy::MODE_FIELD) === Policy::MODE_MANUAL ? Policy::MODE_MANUAL : Policy::MODE_FIELD;
        echo '<div class="mb-3"><label class="form-label">' . __s('Which computers must be backed up', 'backupinfo') . '</label>';
        Dropdown::showFromArray('policy_mode', [
            Policy::MODE_FIELD  => __('The "Wajib Backup" Fields value (set by people)', 'backupinfo'),
            Policy::MODE_MANUAL => __('The manual list below', 'backupinfo'),
        ], ['value' => $mode, 'width' => '100%', 'readonly' => !$canedit]);
        echo '</div>';

        echo '<div class="mb-3"><label class="form-label">' . __s('Manual list (used in "manual list" mode)', 'backupinfo') . '</label>';
        echo Html::hidden('_manual_present', ['value' => 1]);
        // 'value', not 'values': for multiple, Dropdown::show() copies value into values (11.0.5).
        Computer::dropdown([
            'name'     => 'manual_ids[]',
            'multiple' => true,
            'value'    => self::manualIds(),
            'width'    => '100%',
            'readonly' => !$canedit,
        ]);
        echo '<div class="form-hint">' . __s('Computers outside your active entities are kept as they are.', 'backupinfo') . '</div>';
        echo '</div>';
        echo '</div></div>';

        echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">' . __s('Fields mapping (Computer)', 'backupinfo') . '</h3></div><div class="card-body">';
        $containers = $bridge->containers();
        $savedContainer = trim((string) ($cfg['fields_container'] ?? ''));
        $options = ['' => Dropdown::EMPTY_VALUE];
        $containerId = 0;
        foreach ($containers as $id => $label) {
            $options[$label] = $label;
            if ($savedContainer !== '' && mb_strtolower($label) === mb_strtolower($savedContainer)) {
                $containerId = (int) $id;
                $savedContainer = $label;
            }
        }
        if ($savedContainer !== '' && !isset($options[$savedContainer])) {
            $options[$savedContainer] = sprintf(__('%s (not found)', 'backupinfo'), $savedContainer);
        }
        echo '<div class="mb-3"><label class="form-label">' . __s('Fields container', 'backupinfo') . '</label>';
        Dropdown::showFromArray('fields_container', $options, ['value' => $savedContainer, 'width' => '100%', 'readonly' => !$canedit]);
        echo '<div class="form-hint">' . __s('After changing the container, save once to list its fields.', 'backupinfo') . '</div></div>';

        $fields = $containerId > 0 ? $bridge->fields($containerId) : [];
        $roles  = [
            'fields_status_field'    => __('"Backup Status" field (Yes/No, written by the sync)', 'backupinfo'),
            'fields_media_field'     => __('"Backup Media - Content" field (dropdown or text, written by the sync)', 'backupinfo'),
            'fields_mandatory_field' => __('"Wajib Backup" field (Yes/No, read only, set by people)', 'backupinfo'),
        ];
        foreach ($roles as $key => $label) {
            $saved   = trim((string) ($cfg[$key] ?? ''));
            $options = ['' => Dropdown::EMPTY_VALUE];
            foreach ($fields as $name => $fieldLabel) {
                $options[$name] = $fieldLabel . ' (' . $name . ')';
            }
            if ($saved !== '' && !isset($options[$saved])) {
                $options[$saved] = sprintf(__('%s (not found)', 'backupinfo'), $saved);
            }
            echo '<div class="mb-3"><label class="form-label">' . htmlescape($label) . '</label>';
            Dropdown::showFromArray($key, $options, ['value' => $saved, 'width' => '100%', 'readonly' => !$canedit]);
            echo '</div>';
        }
        echo '</div></div>';

        if ($canedit) {
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
        }
        Html::closeForm();

        if ($canedit) {
            echo '<form method="post" class="mt-3" action="' . htmlescape($action) . '">';
            echo '<span class="me-2">' . __s('Run the sync of every active connection now (same as the hourly automatic action).', 'backupinfo') . '</span>';
            echo Html::submit(__('Sync now', 'backupinfo'), ['name' => 'sync_now', 'class' => 'btn btn-outline-secondary']);
            Html::closeForm();
        }
    }

    /** Connections summary with a link to the list (the connection forms stay where they are). */
    private static function connections(): void
    {
        global $DB;

        $types = Registry::types();
        echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">' . __s('Connections', 'backupinfo') . '</h3>';
        echo '<div class="card-actions"><a class="btn btn-sm btn-outline-secondary" href="' . htmlescape(Connection::getSearchURL()) . '"><i class="ti ti-plug-connected me-1"></i>'
            . __s('Manage connections', 'backupinfo') . '</a></div></div>';
        echo '<div class="card-body p-0"><table class="tab_cadre_fixehov"><tr class="noHover">'
            . '<th>' . __s('Name') . '</th><th>' . __s('Type') . '</th><th>' . __s('Active') . '</th>'
            . '<th>' . __s('Last sync', 'backupinfo') . '</th><th>' . __s('Result', 'backupinfo') . '</th></tr>';
        $any = false;
        foreach ($DB->request(['FROM' => 'glpi_plugin_backupinfo_connections', 'ORDER' => ['name']]) as $c) {
            $any    = true;
            $status = (string) ($c['last_status'] ?? '');
            $result = $status === '' ? '' : ($status === 'ok' ? __('OK') : $status . ': ' . (string) ($c['last_error'] ?? ''));
            echo '<tr class="tab_bg_1">'
                . '<td><a href="' . htmlescape(Connection::getFormURLWithID((int) $c['id'])) . '">' . htmlescape((string) $c['name']) . '</a></td>'
                . '<td>' . htmlescape($types[(string) $c['type']] ?? (string) $c['type']) . '</td>'
                . '<td>' . (!empty($c['is_active']) ? __s('Yes') : __s('No')) . '</td>'
                . '<td>' . htmlescape(!empty($c['last_sync']) ? Html::convDateTime((string) $c['last_sync']) : __('never', 'backupinfo')) . '</td>'
                . '<td>' . htmlescape($result) . '</td>'
                . '</tr>';
        }
        if (!$any) {
            echo '<tr class="tab_bg_1"><td colspan="5">' . __s('No connection yet.', 'backupinfo') . '</td></tr>';
        }
        echo '</table></div></div>';
    }

    /** @return list<int> */
    private static function manualIds(): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request(['SELECT' => ['items_id'], 'FROM' => 'glpi_plugin_backupinfo_policies', 'WHERE' => ['itemtype' => Policy::ITEMTYPE]]) as $row) {
            $ids[] = (int) $row['items_id'];
        }
        return $ids;
    }
}
