<?php

namespace GlpiPlugin\Backupinfo;

use CommonDBTM;
use Dropdown;
use GLPIKey;
use GlpiPlugin\Backupinfo\Source\Registry;
use Html;
use Session;

class Connection extends CommonDBTM
{
    public static $rightname = 'plugin_backupinfo_config';
    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Connection', 'Connections', $nb, 'backupinfo');
    }

    public static function getIcon()
    {
        return 'ti ti-plug-connected';
    }

    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0)
    {
        return self::createTabEntry(self::getTypeName(1));
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        return $ong;
    }

    public const SECRETS = ['api_key', 'password'];

    /** Secrets never go to the item history (glpi_logs), not even encrypted. */
    public function getNonLoggedFields(): array
    {
        return self::SECRETS;
    }

    /**
     * Pure secret handling (encryptor injected, no GLPI needed).
     * Empty or absent secret keeps the stored value; "_clear_<field>" empties it;
     * anything else is encrypted. The _clear_* helper keys never reach the DB.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current stored (already encrypted) row values
     * @param callable(string): string $encrypt
     * @return array<string, mixed>
     */
    public static function prepareSecrets(array $input, array $current, callable $encrypt): array
    {
        foreach (self::SECRETS as $field) {
            $clear = !empty($input['_clear_' . $field]);
            unset($input['_clear_' . $field]);
            $submitted = (string) ($input[$field] ?? '');
            if ($clear) {
                $input[$field] = '';
            } elseif ($submitted === '') {
                $input[$field] = (string) ($current[$field] ?? '');
            } else {
                $input[$field] = $encrypt($submitted);
            }
        }
        return $input;
    }

    /** Decrypted secret ('' if unset). Core never decrypts on read. */
    public function secret(string $field): string
    {
        if (!in_array($field, self::SECRETS, true)) {
            return '';
        }
        $raw = (string) ($this->fields[$field] ?? '');
        return $raw === '' ? '' : (string) ((new GLPIKey())->decrypt($raw) ?? '');
    }

    private function secretInput(string $field, string $label): void
    {
        $stored = ((string) ($this->fields[$field] ?? '')) !== '';
        echo Html::input($field, [
            'type'         => 'password',
            'value'        => '',
            'autocomplete' => 'new-password',
            'placeholder'  => $stored ? __('set / unchanged', 'backupinfo') : '',
        ]);
        if ($stored) {
            echo "<label class='ms-2'><input type='checkbox' name='_clear_" . $field . "' value='1'> "
                . __('clear', 'backupinfo') . "</label>";
        }
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $typeValue = (string) ($this->fields['type'] ?? Registry::DEFAULT_TYPE);
        $authValue = (string) ($this->fields['auth_mode'] ?? Registry::defaults($typeValue)['auth_mode'] ?? 'api_key');

        // data-bi-field only on per-type fields (the union of every type's FORM_FIELDS). Shared
        // fields (name, is_active, type, comment) keep no attribute so they are never hidden.
        $perTypeFields = [];
        foreach (Registry::types() as $type => $label) {
            $perTypeFields = array_values(array_unique(array_merge($perTypeFields, Registry::formFields($type))));
        }
        $biField = function (string $f) use ($perTypeFields): string {
            return in_array($f, $perTypeFields, true) ? ' data-bi-field="' . $f . '"' : '';
        };

        echo '<tr class="tab_bg_1"><td' . $biField('name') . '>' . __('Name') . '</td><td' . $biField('name') . '>';
        echo Html::input('name', ['value' => $this->fields['name'] ?? '']);
        echo '</td><td' . $biField('is_active') . '>' . __('Active') . '</td><td' . $biField('is_active') . '>';
        Dropdown::showYesNo('is_active', $this->fields['is_active'] ?? 1);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td' . $biField('type') . '>' . __('Type') . '</td><td' . $biField('type') . '>';
        Dropdown::showFromArray('type', Registry::types(), ['value' => $typeValue]);
        echo '</td><td' . $biField('verify_tls') . '>' . __('Verify TLS certificate', 'backupinfo') . '</td><td' . $biField('verify_tls') . '>';
        Dropdown::showYesNo('verify_tls', $this->fields['verify_tls'] ?? 1);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td' . $biField('host') . '>' . __('Host', 'backupinfo') . '</td><td' . $biField('host') . '>';
        echo Html::input('host', ['value' => $this->fields['host'] ?? '', 'size' => 60]);
        echo '</td><td' . $biField('auth_mode') . '>' . __('Authentication', 'backupinfo') . '</td><td' . $biField('auth_mode') . '>';
        Dropdown::showFromArray('auth_mode', [
            'api_key' => __('API key', 'backupinfo'),
            'login'   => __('Username and password', 'backupinfo'),
        ], ['value' => $authValue]);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td' . $biField('api_key') . '>' . __('API key', 'backupinfo') . '</td><td' . $biField('api_key') . '>';
        $this->secretInput('api_key', 'API key');
        echo '</td><td' . $biField('username') . '>' . __('Username', 'backupinfo') . '</td><td' . $biField('username') . '>';
        echo Html::input('username', ['value' => $this->fields['username'] ?? '']);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td' . $biField('password') . '>' . __('Password') . '</td><td' . $biField('password') . '>';
        $this->secretInput('password', 'Password');
        echo '</td><td' . $biField('api_version') . '>' . __('API version', 'backupinfo') . '</td><td' . $biField('api_version') . '>';
        echo Html::input('api_version', [
            'value'        => $this->fields['api_version'] ?? '',
            'placeholder'  => '1.3-rev2',
        ]);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td' . $biField('comment') . '>' . __('Comments') . '</td><td colspan="3"' . $biField('comment') . '>';
        echo "<textarea name='comment' cols='80' rows='4'>" . htmlescape($this->fields['comment'] ?? '') . "</textarea>";
        echo '</td></tr>';

        // Show/hide per-type form fields. JSON of type => field list; cells outside the chosen
        // type's list get d-none and their inputs disabled (not posted). JSON_HEX_* keeps the
        // JSON valid inside a <script> block without htmlspecialchars (which would break JS).
        $fieldsJson = json_encode(array_map(
            static fn(string $t): array => Registry::formFields($t),
            Registry::types()
        ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo "<script>
            (function(){
                var fields = {$fieldsJson};
                var typeSel = document.querySelector(\"select[name='type']\");
                if (!typeSel) return;
                function apply() {
                    var t = typeSel.value;
                    var show = fields[t] || [];
                    document.querySelectorAll('[data-bi-field]').forEach(function(cell){
                        var f = cell.getAttribute('data-bi-field');
                        var on = show.indexOf(f) >= 0;
                        cell.classList.toggle('d-none', !on);
                        cell.querySelectorAll('input, select, textarea').forEach(function(inp){
                            inp.disabled = !on;
                        });
                    });
                }
                $(typeSel).on('change', apply);
                apply();
            })();
        </script>";

        $this->showFormButtons($options);
        return true;
    }

    public function prepareInputForAdd($input)
    {
        return self::prepareSecrets($input, [], fn(string $s): string => (new GLPIKey())->encrypt($s));
    }

    public function prepareInputForUpdate($input)
    {
        return self::prepareSecrets($input, $this->fields, fn(string $s): string => (new GLPIKey())->encrypt($s));
    }
}
