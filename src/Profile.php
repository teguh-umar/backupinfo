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
                // CREATE/PURGE: add and delete connections. Offered here so saving the
                // matrix never strips them from a profile (Super-Admin is seeded with all).
                'rights'   => [READ => __('Read'), UPDATE => __('Update'), CREATE => __('Create'), PURGE => __('Delete permanently')],
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
            [
                'itemtype' => RestoreTest::class,
                'label'    => __('Restore tests', 'backupinfo'),
                'field'    => RightDefinition::RESTORETEST,
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
            RightDefinition::RESTORETEST => ALLSTANDARDRIGHT,
        ];

        if ($profiles_id) {
            foreach ($rights as $right => $value) {
                ProfileRight::updateProfileRights($profiles_id, [$right => $value]);
            }
        }
    }

    /**
     * Pure: the config right a profile gets on the 0.2.0 upgrade, or null to leave it.
     * Whoever could edit the config in rc2 (UPDATE) may now also add and delete connections.
     */
    public static function upgradedConfigRight(int $current): ?int
    {
        if (($current & UPDATE) === 0) {
            return null;
        }
        $new = $current | CREATE | PURGE;
        return $new === $current ? null : $new;
    }

    /** Pure: true when no stored config right still needs the 0.2.0 grant. */
    public static function allGranted(array $storedRights): bool
    {
        foreach ($storedRights as $rights) {
            if (self::upgradedConfigRight((int) $rights) !== null) {
                return false;
            }
        }
        return true;
    }

    /**
     * One-shot step (install marker config_rights_v020): CREATE|PURGE for every config UPDATE
     * holder. Returns the read-back: true only when the rights now stored are all complete, so
     * the caller sets its marker only after the effect is confirmed (hub §4).
     */
    public static function grantCreatePurgeToEditors(): bool
    {
        foreach (self::storedConfigRights() as $profilesId => $rights) {
            $new = self::upgradedConfigRight($rights);
            if ($new !== null) {
                ProfileRight::updateProfileRights($profilesId, [RightDefinition::CONFIG => $new]);
            }
        }
        return self::allGranted(self::storedConfigRights());
    }

    /** @return array<int, int> profiles_id => stored plugin_backupinfo_config bitmask */
    private static function storedConfigRights(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => RightDefinition::CONFIG]]) as $row) {
            $out[(int) $row['profiles_id']] = (int) $row['rights'];
        }
        return $out;
    }
}
