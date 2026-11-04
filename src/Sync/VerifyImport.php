<?php

namespace GlpiPlugin\Backupinfo\Sync;

use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Text;
use GlpiPlugin\Backupinfo\Vinchin\RunMapper;

/**
 * Vinchin restore-point verifications (/backup_data/verify_history) as restore-test records. The doc
 * gives no row id, so a row is identified by its restore point and start time. The result comes from
 * the checks' texts (ping, heartbeat, boot screenshot, integrity); a row without a readable check is
 * skipped, never guessed. Pure.
 */
final class VerifyImport
{
    /** Verification reads per cron run; objects are taken round-robin. */
    public const PER_RUN = 50;

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

    /**
     * Round-robin slice of $n keys from $cursor (wrapping), and the next cursor.
     *
     * @param list<string> $keys
     * @return array{0: list<string>, 1: int}
     */
    public static function batch(array $keys, int $cursor, int $n): array
    {
        $count = count($keys);
        if ($count === 0) {
            return [[], 0];
        }
        $cursor = $cursor >= $count || $cursor < 0 ? 0 : $cursor;
        $out    = [];
        for ($i = 0; $i < min($n, $count); $i++) {
            $out[] = $keys[($cursor + $i) % $count];
        }
        return [$out, ($cursor + count($out)) % $count];
    }
}
