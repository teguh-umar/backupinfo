<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

use GlpiPlugin\Backupinfo\Source\Event;

/** Vinchin log and alarm rows (descriptions already plain text) to common events. Pure. */
final class EventMapper
{
    public static function map(string $kind, array $row): array
    {
        $time = RunMapper::time($row['op_time'] ?? null) ?? RunMapper::time($row['create_time'] ?? null) ?? '';
        return match ($kind) {
            'joblog' => Event::make('joblog', [
                'time'    => $time,
                'ref'     => $row['id'] ?? '',
                'user'    => $row['user_name'] ?? '',
                'level'   => $row['log_level_des'] ?? '',
                'job'     => $row['job_name'] ?? '',
                'message' => $row['log_description'] ?? '',
            ]),
            'syslog' => Event::make('syslog', [
                'time'      => $time,
                'ref'       => $row['id'] ?? '',
                'user'      => $row['user_name'] ?? ($row['create_user'] ?? ''),
                'action'    => $row['op_type_des'] ?? ($row['op_type'] ?? ''),
                'message'   => $row['description'] ?? '',
                'source_ip' => Event::ip((string) ($row['description'] ?? '')),
            ]),
            'sysalarm' => Event::make('sysalarm', [
                'time'    => RunMapper::time($row['alarm_time'] ?? null) ?? '',
                'ref'     => $row['alarm_id'] ?? '',
                'level'   => $row['level_des'] ?? '',
                'status'  => $row['solved_des'] ?? '',
                'message' => $row['description'] ?? '',
            ]),
            default => throw new \InvalidArgumentException('kind'),
        };
    }
}
