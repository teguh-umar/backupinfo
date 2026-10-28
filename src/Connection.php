<?php

namespace GlpiPlugin\Backupinfo;

use CommonDBTM;
use Session;
use Html;
use Dropdown;

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

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'><td>" . __('Name') . "</td><td>";
        echo Html::input('name', ['value' => $this->fields['name']]);
        echo "</td><td>" . __('Active') . "</td><td>";
        Dropdown::showYesNo('is_active', $this->fields['is_active']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Type') . "</td><td>";
        Dropdown::showFromArray('type', ['vinchin' => 'Vinchin', 'veritas' => 'Veritas', 'excel' => 'Excel Import'], ['value' => $this->fields['type']]);
        echo "</td><td>" . __('Site') . "</td><td>";
        echo Html::input('site', ['value' => $this->fields['site']]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>API URL</td><td>";
        echo Html::input('api_url', ['value' => $this->fields['api_url'], 'size' => 60]);
        echo "</td><td>API Key</td><td>";
        echo Html::input('api_key', ['value' => $this->fields['api_key'], 'size' => 40]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>API Secret</td><td>";
        echo Html::input('api_secret', ['value' => $this->fields['api_secret'], 'size' => 40]);
        echo "</td><td>Token</td><td>";
        echo Html::input('token', ['value' => $this->fields['token'], 'size' => 60]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Comments') . "</td><td colspan='3'>";
        echo "<textarea name='comment' cols='80' rows='4'>" . $this->fields['comment'] . "</textarea>";
        echo "</td></tr>";

        $this->showFormButtons($options);
        return true;
    }

    public function prepareInputForAdd($input)
    {
        if (isset($input['api_key']) && $input['api_key'] !== '') {
            $input['api_key'] = (new \GLPIKey())->encrypt($input['api_key']);
        }
        if (isset($input['api_secret']) && $input['api_secret'] !== '') {
            $input['api_secret'] = (new \GLPIKey())->encrypt($input['api_secret']);
        }
        if (isset($input['token']) && $input['token'] !== '') {
            $input['token'] = (new \GLPIKey())->encrypt($input['token']);
        }
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['api_key']) && $input['api_key'] !== '' && strpos($input['api_key'], '::') !== 0) {
            $input['api_key'] = (new \GLPIKey())->encrypt($input['api_key']);
        }
        if (isset($input['api_secret']) && $input['api_secret'] !== '' && strpos($input['api_secret'], '::') !== 0) {
            $input['api_secret'] = (new \GLPIKey())->encrypt($input['api_secret']);
        }
        if (isset($input['token']) && $input['token'] !== '' && strpos($input['token'], '::') !== 0) {
            $input['token'] = (new \GLPIKey())->encrypt($input['token']);
        }
        return $input;
    }
}
