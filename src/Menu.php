<?php

namespace GlpiPlugin\Backupinfo;

use Session;

class Menu
{
    public static function entry(): ?array
    {
        if (!Session::haveRight(RightDefinition::VIEW, READ) && !Session::haveRight(RightDefinition::CONFIG, READ)) {
            return null;
        }

        return [
            'title'   => 'Backup Info',
            'icon'    => 'ti ti-database-export',
            'page'    => '/plugins/backupinfo/front/dashboard.php',
            'order'   => 100,
            'options' => [
                'dashboard' => [
                    'title' => 'Dashboard',
                    'page'  => '/plugins/backupinfo/front/dashboard.php',
                    'icon'  => 'ti ti-layout-dashboard',
                ],
                'assets' => [
                    'title' => 'Protected Assets',
                    'page'  => '/plugins/backupinfo/front/assets.php',
                    'icon'  => 'ti ti-server',
                ],
                'jobs' => [
                    'title' => 'Backup Jobs',
                    'page'  => '/plugins/backupinfo/front/jobs.php',
                    'icon'  => 'ti ti-list-check',
                ],
                'storage' => [
                    'title' => 'Backup Storage',
                    'page'  => '/plugins/backupinfo/front/storage.php',
                    'icon'  => 'ti ti-database',
                ],
                'report' => [
                    'title' => 'Report',
                    'page'  => '/plugins/backupinfo/front/report.php',
                    'icon'  => 'ti ti-file-report',
                ],
                'connection' => [
                    'title' => 'Connections',
                    'page'  => '/plugins/backupinfo/front/connection.php',
                    'icon'  => 'ti ti-plug-connected',
                ],
            ],
        ];
    }
}
