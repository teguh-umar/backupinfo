<?php

namespace GlpiPlugin\Backupinfo\Fields;

use Config;

/**
 * The only place Backup Info touches the third-party Fields plugin.
 *
 * Reads the human-set "Wajib Backup" field; writes "Backup Status" (Yes/No) and
 * "Backup Media - Content" (dropdown or text). Orchestrator rules (2026-10-28):
 * - container and fields are resolved BY NAME on every call (config stores names; a
 *   Fields system name or its label both match); ids are never stored;
 * - a missing/inactive container or field skips that write and logs one warning per
 *   run (one bridge instance = one run) and is listed by problems() for Setup;
 * - containers and fields are never created (an entry inside an existing dropdown
 *   field may be);
 * - a value is written only when it differs from the stored one, so an asset gets
 *   history only on a real change.
 * Never throws: every fault is logged and the write reports false.
 */
class FieldsBridge
{
    public const CONFIG_CONTEXT = 'plugin:backupinfo';
    public const ITEMTYPE       = 'Computer';

    private const VARCHAR_MAX = 255;

    private const ROLES = [
        'status'    => ['key' => 'fields_status_field', 'label' => 'Backup Status', 'types' => ['yesno']],
        'media'     => ['key' => 'fields_media_field', 'label' => 'Backup Media - Content', 'types' => ['dropdown', 'text', 'textarea']],
        'mandatory' => ['key' => 'fields_mandatory_field', 'label' => 'Wajib Backup', 'types' => ['yesno']],
    ];

    private FieldsGateway $gateway;

    /** @var array<string,true> warning keys already logged by this instance (this run) */
    private array $warned = [];

    /** @param array<string,mixed> $config keys fields_container, fields_status_field, fields_media_field, fields_mandatory_field */
    public function __construct(private array $config, ?FieldsGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new GlpiFieldsGateway();
    }

    /** Bridge using the plugin's saved configuration. Create one per sync run. */
    public static function fromConfig(): self
    {
        return new self(Config::getConfigurationValues(self::CONFIG_CONTEXT, [
            'fields_container', 'fields_status_field', 'fields_media_field', 'fields_mandatory_field',
        ]));
    }

    /** True when both owned fields can be written (Fields active, container and both fields resolve). */
    public function available(): bool
    {
        return $this->safely(fn () => $this->writeProblems() === [], false);
    }

    /**
     * Why Fields is (partly) not updated, for the Setup warning. Empty when all is well.
     * The mandatory field is reported only when it is set but cannot be read.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->safely(function (): array {
            $problems = $this->writeProblems();
            if ($problems === [] && $this->configValue('mandatory') !== '') {
                [, $problem] = $this->resolveField($this->resolveContainer(self::ITEMTYPE)[0], 'mandatory');
                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }
            return $problems;
        }, ['Fields could not be inspected (see the backupinfo log).']);
    }

    /** "Wajib Backup" of the item: true/false, or null when unknown (unset, unreadable, empty). */
    public function readMandatory(string $itemtype, int $id): ?bool
    {
        return $this->safely(function () use ($itemtype, $id): ?bool {
            if ($this->configValue('mandatory') === '') {
                return null;
            }
            [$container, $problem] = $this->resolveContainer($itemtype);
            if ($container === null) {
                $this->warn('container', $problem);
                return null;
            }
            [$field, $problem] = $this->resolveField($container, 'mandatory');
            if ($field === null) {
                $this->warn('field:mandatory', $problem);
                return null;
            }
            $row   = $this->gateway->currentValues($itemtype, $container['name'], $id) ?? [];
            $value = $row[self::column($field)] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            return (int) $value === 1;
        }, null);
    }

    /**
     * Writes Backup Status and (when $media is not blank) Backup Media - Content.
     * Only changed values are sent; a field that cannot be resolved is skipped with a warning.
     *
     * @return bool true when a write was made and Fields accepted it
     */
    public function write(string $itemtype, int $id, bool $hasBackup, string $media): bool
    {
        return $this->safely(function () use ($itemtype, $id, $hasBackup, $media): bool {
            [$container, $problem] = $this->resolveContainer($itemtype);
            if ($container === null) {
                $this->warn('container', $problem);
                return false;
            }
            $targets = [];
            foreach (['status', 'media'] as $role) {
                [$field, $problem] = $this->resolveField($container, $role);
                if ($field === null) {
                    $this->warn('field:' . $role, $problem);
                } else {
                    $targets[$role] = $field;
                }
            }
            if ($targets === []) {
                return false;
            }
            $entity = $this->gateway->itemEntity($itemtype, $id);
            if ($entity === null) {
                return false;
            }

            $current = $this->gateway->currentValues($itemtype, $container['name'], $id) ?? [];
            $changes = [];
            if (isset($targets['status'])) {
                $changes += self::statusChange($targets['status'], $current, $hasBackup);
            }
            $media = trim($media);
            if (isset($targets['media']) && $media !== '') {
                $changes += $this->mediaChange($targets['media'], $current, $media);
            }
            if ($changes === []) {
                return false;
            }

            return $this->gateway->update([
                'plugin_fields_containers_id' => $container['id'],
                'items_id'                    => $id,
                'itemtype'                    => $itemtype,
                'entities_id'                 => $entity,
            ] + $changes, $itemtype);
        }, false);
    }

    /** Setup helper: active Fields containers attached to Computer, id => label. */
    public function containers(): array
    {
        return $this->safely(function (): array {
            if (!$this->gateway->pluginActive()) {
                return [];
            }
            $out = [];
            foreach ($this->gateway->containers() as $c) {
                if ($c['is_active'] && in_array(self::ITEMTYPE, $c['itemtypes'], true)) {
                    $out[(int) $c['id']] = (string) $c['label'];
                }
            }
            return $out;
        }, []);
    }

    /** Setup helper: active fields of a container, name => label. */
    public function fields(int $containerId): array
    {
        return $this->safely(function () use ($containerId): array {
            if (!$this->gateway->pluginActive()) {
                return [];
            }
            $out = [];
            foreach ($this->gateway->fields($containerId) as $f) {
                if ($f['is_active'] && $f['type'] !== 'header') {
                    $out[(string) $f['name']] = (string) $f['label'];
                }
            }
            return $out;
        }, []);
    }

    // ---- decisions (pure, given gateway data) ----

    /** @return list<string> */
    private function writeProblems(): array
    {
        [$container, $problem] = $this->resolveContainer(self::ITEMTYPE);
        if ($container === null) {
            return [$problem];
        }
        $problems = [];
        foreach (['status', 'media'] as $role) {
            [, $problem] = $this->resolveField($container, $role);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        return $problems;
    }

    /** @return array{0:?array,1:?string} [container row, problem] */
    private function resolveContainer(string $itemtype): array
    {
        if (!$this->gateway->pluginActive()) {
            return [null, 'The Fields plugin is not active.'];
        }
        $wanted = trim((string) ($this->config['fields_container'] ?? ''));
        if ($wanted === '') {
            return [null, 'No Fields container is set.'];
        }
        $all = $this->gateway->containers();
        // Container names are unique only per itemtype: match among the usable ones first,
        // so a same-named container on another itemtype cannot shadow this one.
        $usable = array_values(array_filter(
            $all,
            static fn (array $c): bool => $c['is_active'] && in_array($itemtype, $c['itemtypes'], true)
        ));
        $container = self::byName($usable, $wanted);
        if ($container !== null) {
            return [$container, null];
        }
        // Not usable: search everything only to explain why.
        $container = self::byName($all, $wanted);
        if ($container === null) {
            return [null, sprintf('Fields container "%s" was not found.', $wanted)];
        }
        if (!$container['is_active']) {
            return [null, sprintf('Fields container "%s" is inactive.', $wanted)];
        }
        if (!in_array($itemtype, $container['itemtypes'], true)) {
            return [null, sprintf('Fields container "%s" is not attached to %s.', $wanted, $itemtype)];
        }
        return [$container, null];
    }

    /** @return array{0:?array,1:?string} [field row, problem] */
    private function resolveField(?array $container, string $role): array
    {
        if ($container === null) {
            return [null, 'No usable Fields container.'];
        }
        $label  = self::ROLES[$role]['label'];
        $wanted = $this->configValue($role);
        if ($wanted === '') {
            return [null, sprintf('No Fields field is set for "%s".', $label)];
        }
        $field = self::byName($this->gateway->fields((int) $container['id']), $wanted);
        if ($field === null) {
            return [null, sprintf('Fields field "%s" (for "%s") was not found in the container.', $wanted, $label)];
        }
        if (!$field['is_active']) {
            return [null, sprintf('Fields field "%s" (for "%s") is inactive.', $wanted, $label)];
        }
        $types = self::ROLES[$role]['types'];
        if (!in_array($field['type'], $types, true)) {
            $expected = $types === ['yesno'] ? 'Yes/No' : 'dropdown or text';
            return [null, sprintf('Fields field "%s" (for "%s") must be %s, not %s.', $wanted, $label, $expected, $field['type'])];
        }
        if ($field['type'] === 'dropdown' && !empty($field['multiple'])) {
            return [null, sprintf('Fields field "%s" (for "%s") is a multiple dropdown, which is not supported.', $wanted, $label)];
        }
        if ($role !== 'mandatory' && $this->isPolicyField($container, $field)) {
            return [null, sprintf('Fields field "%s" (for "%s") is the human-set "Wajib Backup" field; the plugin never writes it.', $wanted, $label)];
        }
        return [$field, null];
    }

    /** True when $field is the human-owned "Wajib Backup" field: by name/label, or the configured mandatory field. */
    private function isPolicyField(array $container, array $field): bool
    {
        foreach ([(string) $field['name'], (string) $field['label']] as $text) {
            $norm = preg_replace('/[^a-z0-9]/', '', strtolower($text));
            if ($norm === 'wajibbackup' || $norm === 'wajibbackupfield') {
                return true;
            }
        }
        $wanted = $this->configValue('mandatory');
        if ($wanted === '') {
            return false;
        }
        $mandatory = self::byName($this->gateway->fields((int) $container['id']), $wanted);
        return $mandatory !== null && (int) $mandatory['id'] === (int) $field['id'];
    }

    /** @return array<string,int> */
    private static function statusChange(array $field, array $current, bool $hasBackup): array
    {
        $column  = self::column($field);
        $desired = $hasBackup ? 1 : 0;
        $stored  = $current[$column] ?? null;
        if ($stored !== null && $stored !== '' && (int) $stored === $desired) {
            return [];
        }
        return [$column => $desired];
    }

    /** @return array<string,int|string> */
    private function mediaChange(array $field, array $current, string $media): array
    {
        $column = self::column($field);
        $stored = $current[$column] ?? null;
        if ($field['type'] !== 'textarea') {
            // text columns and dropdown names are VARCHAR(255): compare what will actually be stored.
            $media = self::cap($media, self::VARCHAR_MAX);
        }
        if ($field['type'] !== 'dropdown') {
            return (string) ($stored ?? '') === $media ? [] : [$column => $media];
        }
        $entry = $this->gateway->findDropdownEntry((string) $field['name'], $media);
        if ($entry > 0) {
            return (int) ($stored ?? 0) === $entry ? [] : [$column => $entry];
        }
        // The value is new to the dropdown, so it differs from the stored one: add the entry.
        $entry = $this->gateway->createDropdownEntry((string) $field['name'], $media);
        if ($entry <= 0) {
            $this->warn('dropdown:' . $field['name'], sprintf('Could not add "%s" to the dropdown of Fields field "%s".', $media, $field['name']));
            return [];
        }
        return [$column => $entry];
    }

    /** First $max characters (not bytes) of $s; works without mbstring. */
    private static function cap(string $s, int $max): string
    {
        if (preg_match('/^.{0,' . $max . '}/su', $s, $m) === 1) {
            return rtrim($m[0]);
        }
        return substr($s, 0, $max); // invalid UTF-8: byte cut is the best we can do
    }

    /** Column of a field in the generated container table (Fields field.class.php naming). */
    private static function column(array $field): string
    {
        return $field['type'] === 'dropdown'
            ? 'plugin_fields_' . $field['name'] . 'dropdowns_id'
            : (string) $field['name'];
    }

    /** Match a Fields system name first, then a label (case-insensitive). */
    private static function byName(array $rows, string $wanted): ?array
    {
        foreach ($rows as $row) {
            if ((string) $row['name'] === $wanted) {
                return $row;
            }
        }
        $fold = static fn (string $s): string => function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
        $hits = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $fold(trim((string) $row['label'])) === $fold($wanted)
        ));
        return count($hits) === 1 ? $hits[0] : null;
    }

    private function configValue(string $role): string
    {
        return trim((string) ($this->config[self::ROLES[$role]['key']] ?? ''));
    }

    /** Logs once per run per stable $key (problem kind + role/field), whatever the message text. */
    private function warn(string $key, ?string $message): void
    {
        if (isset($this->warned[$key])) {
            return;
        }
        $this->warned[$key] = true;
        $message = 'FieldsBridge: ' . ($message ?? 'unknown problem');
        try {
            $this->gateway->log($message);
        } catch (\Throwable $ignored) {
            // logging must never become the thing that throws
        }
    }

    /**
     * @template T
     * @param callable():T $fn
     * @param T            $fallback
     * @return T
     */
    private function safely(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            // Keyed by exception class only: a message carrying per-asset values must not
            // turn into one log line per asset.
            $this->warn('fault:' . get_class($e), 'Fields call failed: ' . get_class($e) . ': ' . $e->getMessage());
            return $fallback;
        }
    }
}
