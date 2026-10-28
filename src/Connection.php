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

        echo "<tr class='tab_bg_1'><td>" . __('Name') . "</td><td>";
        echo Html::input('name', ['value' => $this->fields['name'] ?? '']);
        echo "</td><td>" . __('Active') . "</td><td>";
        Dropdown::showYesNo('is_active', $this->fields['is_active'] ?? 1);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Type') . "</td><td>";
        Dropdown::showFromArray('type', Registry::types(), ['value' => $this->fields['type'] ?? 'vinchin']);
        echo "</td><td>" . __('Verify TLS certificate', 'backupinfo') . "</td><td>";
        Dropdown::showYesNo('verify_tls', $this->fields['verify_tls'] ?? 1);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Host', 'backupinfo') . "</td><td>";
        echo Html::input('host', ['value' => $this->fields['host'] ?? '', 'size' => 60]);
        echo "</td><td>" . __('Authentication', 'backupinfo') . "</td><td>";
        Dropdown::showFromArray('auth_mode', [
            'api_key' => __('API key', 'backupinfo'),
            'login'   => __('Username and password', 'backupinfo'),
        ], ['value' => $this->fields['auth_mode'] ?? 'api_key']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('API key', 'backupinfo') . "</td><td>";
        $this->secretInput('api_key', 'API key');
        echo "</td><td>" . __('Username', 'backupinfo') . "</td><td>";
        echo Html::input('username', ['value' => $this->fields['username'] ?? '']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Password') . "</td><td>";
        $this->secretInput('password', 'Password');
        echo "</td><td></td><td></td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Comments') . "</td><td colspan='3'>";
        echo "<textarea name='comment' cols='80' rows='4'>" . htmlescape($this->fields['comment'] ?? '') . "</textarea>";
        echo "</td></tr>";

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
