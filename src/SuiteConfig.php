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
        if (array_key_exists('rpo_grace_hours', $post)) {
            $raw = trim((string) $post['rpo_grace_hours']);
            if (preg_match('/^\d+$/', $raw) === 1 && (int) $raw <= Rpo::GRACE_MAX) {
                $values['rpo_grace_hours'] = (int) $raw;
            } else {
                $errors[] = sprintf(__('Grace must be a whole number of hours from 0 to %d; it was not changed.', 'backupinfo'), Rpo::GRACE_MAX);
            }
        }
        foreach ([
            'restore_interval_months' => __('Restore-test interval must be a whole number of months from 0 to %d; it was not changed.', 'backupinfo'),
            'run_keep_months'         => __('Run retention must be a whole number of months from 0 to %d; it was not changed.', 'backupinfo'),
        ] as $key => $message) {
            if (array_key_exists($key, $post)) {
                $raw = trim((string) $post[$key]);
                if (preg_match('/^\d+$/', $raw) === 1 && (int) $raw <= RestoreInterval::MAX_MONTHS) {
                    $values[$key] = (int) $raw;
                } else {
                    $errors[] = sprintf($message, RestoreInterval::MAX_MONTHS);
                }
            }
        }
        if (array_key_exists('own_ips', $post)) {
            $ips = preg_split('/[\s,;]+/', trim((string) $post['own_ips']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $bad = array_filter($ips, static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) === false);
            if ($bad === []) {
                $values['own_ips'] = implode(', ', $ips);
            } else {
                $errors[] = __('The GLPI server addresses must be IP addresses, separated by commas; they were not changed.', 'backupinfo');
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

    /**
     * Pure: options for the container select (system name => "label (name)"), the value to
     * preselect, and the container id for the field selects. Stores names (binding ruling);
     * a value saved as a label by the first Task 12 build still resolves (unique label) and
     * is preselected by its name, so the next Save migrates it.
     *
     * @param list<array{id:int,name:string,label:string}> $containers
     * @return array{0: array<string,string>, 1: string, 2: int}
     */
    public static function containerChoices(array $containers, string $saved): array
    {
        $options  = ['' => '-----'];
        $selected = $saved;
        $id       = 0;
        foreach ($containers as $c) {
            $options[$c['name']] = $c['label'] . ' (' . $c['name'] . ')';
            if ($saved !== '' && $c['name'] === $saved) {
                $id = $c['id'];
            }
        }
        if ($saved !== '' && $id === 0) {
            $fold = static fn (string $v): string => function_exists('mb_strtolower') ? mb_strtolower($v) : strtolower($v);
            $hits = array_values(array_filter($containers, static fn (array $c): bool => $fold(trim($c['label'])) === $fold($saved)));
            if (count($hits) === 1) {
                $id       = $hits[0]['id'];
                $selected = $hits[0]['name'];
            }
        }
        if ($selected !== '' && !isset($options[$selected])) {
            $options[$selected] = sprintf(__('%s (not found)', 'backupinfo'), $selected);
        }
        return [$options, $selected, $id];
    }

    public static function render(): void
    {
        $canedit = Session::haveRight(RightDefinition::CONFIG, UPDATE);
        $action  = Html::getPrefixedUrl('/plugins/backupinfo/front/config.php');
        $cfg     = Config::getConfigurationValues(self::CONTEXT, array_merge(['rpo_hours', 'rpo_grace_hours', 'policy_mode', 'restore_interval_months', 'run_keep_months', 'own_ips'], self::FIELD_KEYS));
        $bridge  = FieldsBridge::fromConfig();

        self::connections();

        // Fields warnings first: they explain why Backup Status is not being written.
        $problems = $bridge->problems();
        if (!$bridge->available() || $problems !== []) {
            echo '<div class="alert alert-warning"><strong>' . __s('Fields is not (fully) updated by the sync:', 'backupinfo') . '</strong><ul class="mb-0">';
            foreach ($problems ?: [__('Fields is not available (see the backupinfo log).', 'backupinfo')] as $problem) {
                echo '<li>' . htmlescape($problem) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<form method="post" action="' . htmlescape($action) . '">';
        echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">' . __s('Backup policy', 'backupinfo') . '</h3></div><div class="card-body">';

        // A backup is "expired" when older than its job's schedule interval (read from the
        // backup system) plus the grace; the RPO below applies when the schedule is unknown.
        echo '<div class="mb-3"><label class="form-label" for="backupinfo-grace">' . __s('Grace (hours) after the job schedule: a backup older than schedule + grace is "expired"', 'backupinfo') . '</label>';
        echo '<input type="number" min="0" max="' . Rpo::GRACE_MAX . '" step="1" class="form-control" id="backupinfo-grace" name="rpo_grace_hours" value="'
            . Rpo::fromValues($cfg)->graceHours . '"' . ($canedit ? '' : ' disabled') . '></div>';
        echo '<div class="mb-3"><label class="form-label" for="backupinfo-rpo">' . __s('RPO (hours) when the job schedule is unknown', 'backupinfo') . '</label>';
        echo '<input type="number" min="' . self::RPO_MIN . '" max="' . self::RPO_MAX . '" step="1" class="form-control" id="backupinfo-rpo" name="rpo_hours" value="'
            . (int) ($cfg['rpo_hours'] ?? 26) . '"' . ($canedit ? '' : ' disabled') . '></div>';

        // Audit evidence (0.3.0): restore-test interval (per Computer override on its Backup tab) and run retention.
        echo '<div class="mb-3"><label class="form-label" for="backupinfo-restore">' . __s('Restore test required every (months, 0 = not required); a computer can override it on its Backup tab', 'backupinfo') . '</label>';
        echo '<input type="number" min="0" max="' . RestoreInterval::MAX_MONTHS . '" step="1" class="form-control" id="backupinfo-restore" name="restore_interval_months" value="'
            . (int) ($cfg['restore_interval_months'] ?? 12) . '"' . ($canedit ? '' : ' disabled') . '></div>';
        echo '<div class="mb-3"><label class="form-label" for="backupinfo-keep">' . __s('Keep backup run history (months, 0 = forever)', 'backupinfo') . '</label>';
        echo '<input type="number" min="0" max="' . RestoreInterval::MAX_MONTHS . '" step="1" class="form-control" id="backupinfo-keep" name="run_keep_months" value="'
            . (int) ($cfg['run_keep_months'] ?? 36) . '"' . ($canedit ? '' : ' disabled') . '></div>';
        echo '<div class="mb-3"><label class="form-label" for="backupinfo-ownips">' . __s('IP addresses of this GLPI server (its logins to the backup system are the plugin\'s own and hidden on the audit page; blank = the address the web server answers on)', 'backupinfo') . '</label>';
        echo '<input type="text" maxlength="255" class="form-control" id="backupinfo-ownips" name="own_ips" value="'
            . htmlescape((string) ($cfg['own_ips'] ?? '')) . '"' . ($canedit ? '' : ' disabled') . '></div>';

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
        [$options, $savedContainer, $containerId] = self::containerChoices(
            $bridge->containerOptions(),
            trim((string) ($cfg['fields_container'] ?? ''))
        );
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

    /** Connections: list, add and edit, all inside this section (the form posts to front/connection.form.php). */
    private static function connections(): void
    {
        global $DB;

        $types = Registry::types();
        echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">' . __s('Connections', 'backupinfo') . '</h3>';
        if (Session::haveRight(RightDefinition::CONFIG, CREATE)) {
            echo '<div class="card-actions"><a class="btn btn-sm btn-outline-secondary" href="' . htmlescape(self::connectionLink(0)) . '"><i class="ti ti-plus me-1"></i>'
                . __s('Add a connection', 'backupinfo') . '</a></div>';
        }
        echo '</div>';
        echo '<div class="card-body p-0"><table class="tab_cadre_fixehov"><tr class="noHover">'
            . '<th>' . __s('Name') . '</th><th>' . __s('Type') . '</th><th>' . __s('Active') . '</th>'
            . '<th>' . __s('Last sync', 'backupinfo') . '</th><th>' . __s('Result', 'backupinfo') . '</th></tr>';
        $any = false;
        foreach ($DB->request(['FROM' => 'glpi_plugin_backupinfo_connections', 'ORDER' => ['name']]) as $c) {
            $any    = true;
            $status = (string) ($c['last_status'] ?? '');
            $result = $status === '' ? '' : ($status === 'ok' ? __('OK') : $status . ': ' . (string) ($c['last_error'] ?? ''));
            echo '<tr class="tab_bg_1">'
                . '<td><a href="' . htmlescape(self::connectionLink((int) $c['id'])) . '">' . htmlescape((string) $c['name']) . '</a></td>'
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

        // The connection form lives here, in Setup (user, 2026-11-02); it posts to
        // front/connection.form.php, which returns to this section.
        $requested = $_GET[self::CONNECTION_PARAM] ?? null;
        if ($requested !== null && is_numeric($requested)) {
            $id   = (int) $requested;
            $conn = new Connection();
            $ok   = $id > 0 ? $conn->can($id, READ) : Session::haveRight(RightDefinition::CONFIG, CREATE);
            if ($ok) {
                echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">'
                    . ($id > 0 ? __s('Edit connection', 'backupinfo') : __s('New connection', 'backupinfo')) . '</h3>'
                    . '<div class="card-actions"><a class="btn btn-sm btn-ghost-secondary" href="' . htmlescape(self::connectionLink(null)) . '">'
                    . __s('Close') . '</a></div></div><div class="card-body">';
                $conn->showForm($id);
                echo '</div></div>';
            }
        }
    }

    public const CONNECTION_PARAM = 'bi_connection';

    /**
     * Pure: link to this section on the settings page, with the connection form open for $id
     * (0 = new) or closed (null). Relative to the settings page, which is where it is used.
     */
    public static function connectionLink(?int $id): string
    {
        $query = ['section' => self::SECTION];
        if ($id !== null) {
            $query[self::CONNECTION_PARAM] = max(0, $id);
        }
        return '?' . http_build_query($query);
    }

    /** Absolute URL of this section on the settings page (for redirects from front/ controllers). */
    public static function sectionUrl(?int $connectionId = null): string
    {
        $base = \GlpiPlugin\Pgnsuite\ConfigTab::getReturnUrl(self::SECTION);
        return $base . (str_contains($base, '?') ? '&' : '?') . substr(self::connectionLink($connectionId), 1);
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
