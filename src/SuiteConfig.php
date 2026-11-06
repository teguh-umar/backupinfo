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
        foreach (['tickets_enabled', 'findings_enabled', 'followup_on_success'] as $key) {
            if (array_key_exists($key, $post)) {
                $values[$key] = (int) (bool) $post[$key];
            }
        }
        foreach (['ticket_template', 'problem_template'] as $key) {
            if (array_key_exists($key, $post)) {
                $values[$key] = max(0, (int) $post[$key]);
            }
        }
        foreach ([
            'problem_count' => [1, 50, __('Failures before a Problem must be a whole number from %1$d to %2$d; it was not changed.', 'backupinfo')],
            'problem_days'  => [1, 365, __('The Problem window must be a whole number of days from %1$d to %2$d; it was not changed.', 'backupinfo')],
        ] as $key => [$min, $max, $message]) {
            if (array_key_exists($key, $post)) {
                $raw = trim((string) $post[$key]);
                if (preg_match('/^\d+$/', $raw) === 1 && (int) $raw >= $min && (int) $raw <= $max) {
                    $values[$key] = (int) $raw;
                } else {
                    $errors[] = sprintf($message, $min, $max);
                }
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
     * Pure: switching tickets on stores the start time (runs before it never open a ticket);
     * switching off and on again restarts it.
     *
     * @param array<string, int|string> $values sanitized values to store
     * @return array<string, int|string>
     */
    public static function withTicketsSince(array $values, bool $wasEnabled, int $nowS): array
    {
        if (!empty($values['tickets_enabled']) && !$wasEnabled) {
            $values['tickets_since'] = date('Y-m-d H:i:s', $nowS);
        }
        return $values;
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

        $problems  = $bridge->problems();
        $fieldsBad = !$bridge->available() || $problems !== [];
        $editing   = isset($_GET[self::CONNECTION_PARAM]);
        echo '<div class="card mb-3"><div class="card-header">' . self::tabNav($fieldsBad, $editing) . '</div><div class="card-body">';
        echo '<div class="tab-content">';
        echo '<div class="tab-pane active show" id="backupinfo-tab-connections" role="tabpanel">';
        self::connections();
        if ($canedit) {
            echo '<form method="post" class="mb-3" action="' . htmlescape($action) . '">';
            echo '<span class="me-2">' . __s('Run the sync of every active connection now (same as automatic action).', 'backupinfo') . '</span>';
            echo Html::submit(__('Sync now', 'backupinfo'), ['name' => 'sync_now', 'class' => 'btn btn-outline-secondary']);
            Html::closeForm();
        }
        echo '</div>';

        // One settings form across the other tabs: Save on any tab saves them all.
        // Bootstrap hides only direct children of .tab-content: close the Connections one and give the
        // settings form its own (a form cannot hold the connection editor's form).
        echo '</div>'; // tab-content (Connections)
        echo '<form method="post" action="' . htmlescape($action) . '">';
        echo '<div class="tab-content">';
        echo '<div class="tab-pane" id="backupinfo-tab-policy" role="tabpanel">';

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
        self::saveButtons($canedit, false);
        echo '</div>';

        echo '<div class="tab-pane" id="backupinfo-tab-fields" role="tabpanel">';
        // Fields warnings first: they explain why Backup Status is not being written.
        if ($fieldsBad) {
            echo '<div class="alert alert-warning"><strong>' . __s('Fields is not (fully) updated by the sync:', 'backupinfo') . '</strong><ul class="mb-0">';
            foreach ($problems ?: [__('Fields is not available (see the backupinfo log).', 'backupinfo')] as $problem) {
                echo '<li>' . htmlescape($problem) . '</li>';
            }
            echo '</ul></div>';
        }
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
        self::saveButtons($canedit, false);
        echo '</div>';

        echo '<div class="tab-pane" id="backupinfo-tab-tickets" role="tabpanel">';
        self::ticketsCard($canedit);
        self::saveButtons($canedit, true);
        echo '</div>';
        echo '</div>'; // tab-content (settings)
        Html::closeForm();
        echo '</div></div>'; // card-body, card
        echo self::tabScript($editing);
    }

    /** Pure: the tab bar. Fields gets a warning mark when the sync cannot write Fields. */
    public static function tabNav(bool $fieldsWarning, bool $editingConnection): string
    {
        $tabs = [
            'connections' => ['ti-plug-connected', __('Connections', 'backupinfo')],
            'policy'      => ['ti-shield-check', __('Backup policy', 'backupinfo')],
            'fields'      => ['ti-forms', __('Fields mapping', 'backupinfo')],
            'tickets'     => ['ti-ticket', __('Tickets and findings', 'backupinfo')],
        ];
        $html = '<ul class="nav nav-tabs card-header-tabs" id="backupinfo-tabs" role="tablist">';
        foreach ($tabs as $key => [$icon, $label]) {
            $active = $key === 'connections'; // shown without JavaScript too
            $html  .= '<li class="nav-item" role="presentation"><button type="button" class="nav-link' . ($active ? ' active' : '') . '" data-bs-toggle="tab"'
                . ' data-bs-target="#backupinfo-tab-' . $key . '" data-tab="' . $key . '" role="tab"><i class="ti ' . $icon . ' me-1"></i>' . htmlescape($label)
                . ($key === 'fields' && $fieldsWarning ? ' <i class="ti ti-alert-triangle text-warning ms-1" title="' . __s('Fields is not (fully) updated by the sync', 'backupinfo') . '"></i>' : '')
                . '</button></li>';
        }
        return $html . '</ul>';
    }

    private static function saveButtons(bool $canedit, bool $dryRun): void
    {
        if (!$canedit) {
            return;
        }
        echo '<div class="mb-3">' . Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
        if ($dryRun) {
            echo ' ' . Html::submit(__('What would be created now', 'backupinfo'), ['name' => 'dryrun_tickets', 'class' => 'btn btn-outline-secondary']);
        }
        echo '</div>';
    }

    /** Opens the tab used last (kept per browser), Connections the first time or while editing one. */
    private static function tabScript(bool $editingConnection): string
    {
        return '<script>(function () {'
            . 'var key = "backupinfo.setup.tab", nav = document.getElementById("backupinfo-tabs"); if (!nav) { return; }'
            . 'var want = ' . ($editingConnection ? '"connections"' : 'null') . ';'
            . 'if (!want) { try { want = localStorage.getItem(key); } catch (e) {} }'
            . 'var btn = nav.querySelector("[data-tab=\"" + (want || "connections") + "\"]") || nav.querySelector("[data-tab]");'
            . 'if (btn && window.bootstrap) { bootstrap.Tab.getOrCreateInstance(btn).show(); }'
            . 'nav.addEventListener("shown.bs.tab", function (ev) { try { localStorage.setItem(key, ev.target.getAttribute("data-tab")); } catch (e) {} });'
            . '})();</script>';
    }

    /** Connections: list, add and edit, all inside this section (the form posts to front/connection.form.php). */
    private static function connections(): void
    {
        global $DB;

        $types = Registry::types();
        if (Session::haveRight(RightDefinition::CONFIG, CREATE)) {
            echo '<div class="d-flex justify-content-end mb-2"><a class="btn btn-sm btn-outline-secondary" href="' . htmlescape(self::connectionLink(0)) . '"><i class="ti ti-plus me-1"></i>'
                . __s('Add a connection', 'backupinfo') . '</a></div>';
        }
        echo '<div class="table-responsive mb-3"><table class="tab_cadre_fixehov"><tr class="noHover">'
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
        echo '</table></div>';

        // The connection form lives here, in Setup (user, 2026-11-02); it posts to
        // front/connection.form.php, which returns to this section.
        $requested = $_GET[self::CONNECTION_PARAM] ?? null;
        if ($requested !== null && is_numeric($requested)) {
            $id   = (int) $requested;
            $conn = new Connection();
            $ok   = $id > 0 ? $conn->can($id, READ) : Session::haveRight(RightDefinition::CONFIG, CREATE);
            if ($ok) {
                echo '<div class="border rounded p-3 mb-3"><div class="d-flex justify-content-between align-items-center mb-2"><h4 class="m-0">'
                    . ($id > 0 ? __s('Edit connection', 'backupinfo') : __s('New connection', 'backupinfo')) . '</h4>'
                    . '<a class="btn btn-sm btn-ghost-secondary" href="' . htmlescape(self::connectionLink(null)) . '">'
                    . __s('Close') . '</a></div>';
                $conn->showForm($id);
                echo '</div>';
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

    /** 0.4.0: Incidents, Problems and findings (off until switched on). */
    private static function ticketsCard(bool $canedit): void
    {
        $cfg = Config::getConfigurationValues(self::CONTEXT, ['tickets_enabled', 'tickets_since', 'ticket_template', 'problem_template', 'problem_count', 'problem_days', 'findings_enabled', 'followup_on_success']);
        foreach ([
            'tickets_enabled'  => __('Open an Incident for each failed backup of a computer that must be backed up, and a Problem for repeated failures', 'backupinfo'),
            'followup_on_success' => __('Add one follow-up "Backup succeeded again" to the open Incident when a later backup of that computer succeeds (the Incident is not closed)', 'backupinfo'),
            'findings_enabled' => __('Write Risk Register findings (coverage, restore tests, repeated failures)', 'backupinfo'),
        ] as $key => $label) {
            echo '<div class="mb-3 form-check"><input type="hidden" name="' . $key . '" value="0">';
            echo '<input type="checkbox" class="form-check-input" id="backupinfo-' . $key . '" name="' . $key . '" value="1"' . (empty($cfg[$key]) ? '' : ' checked') . ($canedit ? '' : ' disabled') . '>';
            echo '<label class="form-check-label" for="backupinfo-' . $key . '">' . htmlescape($label) . '</label></div>';
        }
        if (!empty($cfg['tickets_enabled']) && !empty($cfg['tickets_since'])) {
            echo '<div class="form-hint mb-3">' . htmlescape(sprintf(__('Only backups started after %s open a ticket.', 'backupinfo'), $cfg['tickets_since'])) . '</div>';
        }
        echo '<div class="mb-3"><label class="form-label">' . __s('Incident template (requester, groups, supplier, category, urgency)', 'backupinfo') . '</label>';
        \TicketTemplate::dropdown(['name' => 'ticket_template', 'value' => (int) ($cfg['ticket_template'] ?? 0), 'entity' => 0, 'width' => '100%']);
        echo '</div><div class="mb-3"><label class="form-label">' . __s('Problem template', 'backupinfo') . '</label>';
        \ProblemTemplate::dropdown(['name' => 'problem_template', 'value' => (int) ($cfg['problem_template'] ?? 0), 'entity' => 0, 'width' => '100%']);
        echo '<div class="form-hint">' . __s('Fill in the templates in Setup > Templates. Their predefined fields are copied into each ticket.', 'backupinfo') . '</div></div>';
        echo '<div class="row"><div class="col-md-6 mb-3"><label class="form-label" for="backupinfo-pcount">' . __s('Failures before a Problem', 'backupinfo') . '</label>';
        echo '<input type="number" min="1" max="50" class="form-control" id="backupinfo-pcount" name="problem_count" value="' . (int) ($cfg['problem_count'] ?? 3) . '"' . ($canedit ? '' : ' disabled') . '></div>';
        echo '<div class="col-md-6 mb-3"><label class="form-label" for="backupinfo-pdays">' . __s('Within (days)', 'backupinfo') . '</label>';
        echo '<input type="number" min="1" max="365" class="form-control" id="backupinfo-pdays" name="problem_days" value="' . (int) ($cfg['problem_days'] ?? 30) . '"' . ($canedit ? '' : ' disabled') . '></div></div>';
    }

}
