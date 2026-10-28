<?php

namespace GlpiPlugin\Backupinfo;

use ProfileRight;
use Session;
use Html;

class Profile extends \Profile
{
    public static $rightname = 'profile';

    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() == 'Profile') {
            return self::createTabEntry('Backup Info');
        }
        return '';
    }

    public static function displayTabContentForItem(\CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() == 'Profile') {
            $profile = new self();
            $profile->showForm($item->getField('id'));
        }
        return true;
    }

    public function showForm($profiles_id = 0, $openform = true, $closeform = true)
    {
        if (!Session::haveRight("profile", UPDATE)) {
            return false;
        }

        // post_getFromDB() merges the profilerights rows into ->fields, which pre-checks the boxes.
        $profile = new \Profile();
        if (!$profiles_id || !$profile->getFromDB($profiles_id)) {
            return false;
        }

        $rights = [
            [
                'itemtype' => '',
                'rights'   => [READ => __('Read'), UPDATE => __('Update')],
                'label'    => __('Configure Backup Info', 'backupinfo'),
                'field'    => RightDefinition::CONFIG,
            ],
            [
                'itemtype' => '',
                'rights'   => [READ => __('Read')],
                'label'    => __('View Backup Info', 'backupinfo'),
                'field'    => RightDefinition::VIEW,
            ],
            [
                'itemtype' => '',
                'rights'   => [READ => __('Read')],
                'label'    => __('View/Export Reports', 'backupinfo'),
                'field'    => RightDefinition::REPORT,
            ],
        ];

        echo "<form method='post' action='" . htmlescape(\Profile::getFormURL()) . "' data-track-changes='true'>";
        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => true,
            'default_class' => 'tab_bg_2',
            'title'         => __('Backup Info rights', 'backupinfo'),
        ]);
        echo "<div class='center mt-2'>";
        echo Html::hidden('id', ['value' => $profiles_id]);
        echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
        echo "</div>";
        Html::closeForm();
    }

    public static function install()
    {
        global $DB;

        $profiles_id = 0;
        foreach ($DB->request(['FROM' => 'glpi_profiles', 'WHERE' => ['interface' => 'central']]) as $prof) {
            if ($prof['name'] == 'Super-Admin') {
                $profiles_id = $prof['id'];
                break;
            }
        }

        $rights = [
            RightDefinition::CONFIG => ALLSTANDARDRIGHT,
            RightDefinition::VIEW   => READ,
            RightDefinition::REPORT => READ,
        ];

        if ($profiles_id) {
            foreach ($rights as $right => $value) {
                ProfileRight::updateProfileRights($profiles_id, [$right => $value]);
            }
        }
    }
}
