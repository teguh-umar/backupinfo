<?php

namespace GlpiPlugin\Backupinfo\Veeam;

use GlpiPlugin\Backupinfo\Source\Event;
use GlpiPlugin\Backupinfo\Source\Time;
use GlpiPlugin\Backupinfo\Veeam\RunMapper;

/**
 * Veeam security events -> common Event rows.
 */
final class EventMapper
{
    public static function authorization(array $row): array
    {
        $user = trim((string) ($row['createdBy'] ?? ''));
        $processed = trim((string) ($row['processedBy'] ?? ''));
        if ($processed !== '') {
            $user .= ' → ' . $processed;
        }
        $state = (string) ($row['state'] ?? '');
        $level = 'info';
        if (in_array($state, ['Pending', 'Rejected', 'Expired'], true)) {
            $level = 'warning';
        }
        return Event::make('syslog', [
            'time'      => Time::local($row['creationTime'] ?? null),
            'end_time'  => trim((string) ($row['processedTime'] ?? '')),
            'ref'       => trim((string) ($row['id'] ?? '')),
            'user'      => $user,
            'level'     => $level,
            'action'    => trim((string) ($row['name'] ?? '')),
            'message'   => trim((string) ($row['description'] ?? '')),
            'status'    => $state,
            'source_ip' => '',
        ]);
    }

    public static function malware(array $row): array
    {
        $state  = (string) ($row['state'] ?? '');
        $sev    = (string) ($row['severity'] ?? '');
        $level  = 'info';
        if ($state === 'Infected') {
            $level = 'critical';
        } elseif ($state === 'Suspicious') {
            $level = 'warning';
        }
        $machine = trim((string) (($row['machine']['displayName'] ?? '') ?? ''));
        return Event::make('sysalarm', [
            'time'      => Time::local($row['detectionTimeUtc'] ?? null),
            'ref'       => trim((string) ($row['id'] ?? '')),
            'user'      => trim((string) ($row['createdBy'] ?? '')),
            'level'     => $level,
            'action'    => trim((string) ($row['type'] ?? '')),
            'message'   => trim((string) ($row['details'] ?? '')) . ($machine !== '' ? ' · ' . $machine : ''),
            'vm'        => $machine,
            'status'    => $state,
            'item_status' => $sev,
        ]);
    }

    public static function copy(array $session, array $item): array
    {
        return Event::make('copy', [
            'time'       => Time::local($session['creationTime'] ?? null),
            'end_time'   => Time::local($session['endTime'] ?? null),
            'ref'        => trim((string) ($session['id'] ?? '')),
            'job'        => trim((string) ($session['name'] ?? '')),
            'vm'         => trim((string) ($item['vm_name'] ?? '')),
            'status'     => RunMapper::status($session['result'] ?? null),
            'item_status'=> trim((string) ($item['status'] ?? '')),
            'error'      => mb_substr(trim((string) ($item['error'] ?? '')), 0, 255),
        ]);
    }
}
