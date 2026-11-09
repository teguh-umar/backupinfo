<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Text;

/**
 * Vinchin verification row (/backup_data/verify_history) -> RestoreTest input.
 * Same body as VerifyImport::toInput had; pure. A row without a readable check is
 * skipped, never guessed.
 */
final class VerifyMapper
{
    private const CHECKS = [
        'ping_status_des'            => 'Network (ping)',
        'heartbeat_status_des'       => 'Heartbeat',
        'screen_status_des'          => 'Boot screenshot',
        'integrity_check_status_des' => 'Integrity',
        'vir_det_kill_status_des'    => 'Virus scan',
    ];

    /** @return array<string, mixed>|null RestoreTest input, or null when the row cannot be read */
    public static function toInput(array $row, int $computerId): ?array
    {
        $start = RunMapper::time($row['start_time'] ?? null);
        $date  = RunMapper::time($row['end_time'] ?? null) ?? $start;
        $tp    = trim((string) ($row['timepoint_uuid'] ?? ''));
        if ($date === null || $tp === '') {
            return null;
        }

        $ok    = 0;
        $bad   = 0;
        $lines = [];
        foreach (self::CHECKS as $key => $label) {
            $text = Text::plain((string) ($row[$key] ?? ''), 60);
            if ($text === '') {
                continue;
            }
            $lines[] = $label . ': ' . $text;
            match (RunMapper::status($text)) {
                Run::SUCCESS             => $ok++,
                Run::FAILED, Run::ABORTED => $bad++,
                default                  => null,
            };
        }
        if ($ok + $bad === 0) {
            return null;
        }

        return [
            'computers_id' => $computerId,
            'date'         => substr($date, 0, 10),
            'method'       => 'vinchin_verify',
            'result'       => $bad === 0 ? 'pass' : ($ok === 0 ? 'fail' : 'partial'),
            'source'       => 'vinchin',
            'source_ref'   => mb_substr($tp . '@' . ($start ?? $date), 0, 128),
            'comment'      => sprintf('Vinchin verification of restore point %s. ', (string) (RunMapper::time($row['timepoint'] ?? null) ?? $tp))
                . implode('; ', $lines) . '.',
        ];
    }
}
