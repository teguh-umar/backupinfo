<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * The common audit event every backup source returns (0.3.0-rc6), whatever the brand: off-site copy
 * runs ("copy"), job logs ("joblog"), system logs ("syslog") and system alarms ("sysalarm"). Stored
 * as is in glpi_plugin_backupinfo_events, so its keys never change meaning; a brand that has no
 * value leaves it ''. Times are `Y-m-d H:i:s` in the backup server's time. Pure.
 */
final class Event
{
    public const KINDS = ['copy', 'joblog', 'syslog', 'sysalarm'];

    /** key => max length ('' default); restore_points is int|null */
    private const TEXT = [
        'time'        => 19,  // when it happened (copy: start)
        'end_time'    => 19,  // copy: end
        'ref'         => 64,  // the source's own id of the event, when it has one
        'user'        => 128,
        'level'       => 64,  // severity as the source words it
        'action'      => 128, // what was done (e.g. "System Login")
        'message'     => 1000,
        'job'         => 255,
        'vm'          => 255,
        'status'      => 32,  // copy run / alarm status
        'item_status' => 32,  // copy: the VM's own status
        'error'       => 255,
        'source_ip'   => 45,  // syslog: the client address of a login or action
        'size'        => 64,
    ];

    /** @return array<string, string|int|null> */
    public static function make(string $kind, array $fields): array
    {
        $out = ['kind' => in_array($kind, self::KINDS, true) ? $kind : 'syslog'];
        foreach (self::TEXT as $key => $max) {
            $out[$key] = mb_substr(trim((string) ($fields[$key] ?? '')), 0, $max);
        }
        $rp = $fields['restore_points'] ?? null;
        $out['restore_points'] = ($rp === null || $rp === '') ? null : (int) $rp;
        return $out;
    }

    /** First IPv4 address in a text, or ''. */
    public static function ip(string $text): string
    {
        return preg_match('/\b((?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3})\b/', $text, $m) === 1 ? $m[1] : '';
    }
}
