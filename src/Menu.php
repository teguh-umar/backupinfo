<?php

namespace GlpiPlugin\Backupinfo;

use Session;

class Menu
{
    /** Entry in the shared Tools ICT menu: Coverage, Objects, Connections, each behind its right. */
    public static function entry(): ?array
    {
        $options = [];
        if (Session::haveRight(RightDefinition::REPORT, READ)) {
            $options['coverage'] = [
                'title' => __('Coverage', 'backupinfo'),
                'page'  => '/plugins/backupinfo/front/coverage.php',
                'icon'  => 'ti ti-shield-check',
            ];
        }
        if (Session::haveRight(RightDefinition::VIEW, READ)) {
            $options['objects'] = [
                'title' => __('Backup objects', 'backupinfo'),
                'page'  => '/plugins/backupinfo/front/objects.php',
                'icon'  => 'ti ti-list-search',
            ];
        }
        if (Session::haveRight(RightDefinition::CONFIG, READ)) {
            $options['connection'] = [
                'title' => __('Connections', 'backupinfo'),
                'page'  => '/plugins/backupinfo/front/connection.php',
                'icon'  => 'ti ti-plug-connected',
            ];
        }
        if ($options === []) {
            return null;
        }

        return [
            'title'   => 'Backup Info',
            'icon'    => 'ti ti-database-export',
            'page'    => reset($options)['page'],
            'order'   => 100,
            'options' => $options,
        ];
    }
}
