<?php

namespace GlpiPlugin\Backupinfo;

use Session;

class Menu
{
    /** Entry in the shared Tools ICT menu: Coverage, Audit evidence, Objects, Restore tests, Probe, each behind its right. */
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
        if (Session::haveRight(RightDefinition::REPORT, READ)) {
            $options['audit'] = [
                'title' => __('Audit evidence', 'backupinfo'),
                'page'  => '/plugins/backupinfo/front/audit.php',
                'icon'  => 'ti ti-file-certificate',
            ];
        }
        if (Session::haveRight(RightDefinition::VIEW, READ)) {
            $options['objects'] = [
                'title' => __('Backup objects', 'backupinfo'),
                'page'  => '/plugins/backupinfo/front/objects.php',
                'icon'  => 'ti ti-list-search',
            ];
        }
        if (Session::haveRight(RightDefinition::RESTORETEST, READ)) {
            $options['restoretests'] = [
                'title' => __('Restore tests', 'backupinfo'),
                'page'  => '/plugins/backupinfo/front/restoretest.php',
                'icon'  => 'ti ti-restore',
            ];
        }
        // Connections are not here: they are set in Setup, Backup Info's section of the PGN
        // Suite settings page (user, 2026-11-02).
        if (Session::haveRight(RightDefinition::CONFIG, UPDATE)) {
            $options['probe'] = [
                'title' => __('Probe', 'backupinfo'),
                'page'  => '/plugins/backupinfo/front/probe.php',
                'icon'  => 'ti ti-zoom-scan',
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
