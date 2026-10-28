<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

use GlpiPlugin\Backupinfo\Source\BackupObject;

/**
 * Fallback mapping from /jobs/history. Vinchin 8/9 reports history per job without a VM name, so known VM
 * names are matched inside job_name (e.g. "WEEKLY COPY_VM-APP-HUB" -> "VM-APP-HUB").
 */
final class JobHistoryMapper
{
    private const SKIP = '/restore|recover|verif|migrat|mount|instant/i';
    private const SUCCESS = '/succe|complet|finish|ok|done|成功|完成/iu';

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $knownVmNames
     * @return list<BackupObject>
     */
    public static function map(array $rows, array $knownVmNames, int $nowS, int $lookbackDays = 14): array
    {
        $cutoff = $nowS - $lookbackDays * 86400;
        // Longest name first so "VM-A-2" is not swallowed by "VM-A".
        $names = array_values(array_filter(array_unique(array_map('trim', $knownVmNames)), static fn($n) => $n !== ''));
        usort($names, static fn($a, $b) => strlen($b) <=> strlen($a));

        $acc = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $job = trim((string) ($row['job_name'] ?? ''));
            $type = trim((string) ($row['job_type'] ?? ''));
            if ($job === '' || preg_match(self::SKIP, $type . ' ' . $job)) {
                continue;
            }
            if (!preg_match(self::SUCCESS, (string) ($row['job_status'] ?? ''))) {
                continue;
            }
            $time = ItemMapper::parseTime($row['finish_time'] ?? null) ?? ItemMapper::parseTime($row['start_time'] ?? null);
            if ($time === null || $time < $cutoff) {
                continue;
            }
            $vm = null;
            foreach ($names as $n) {
                if (stripos($job, $n) !== false) {
                    $vm = $n;
                    break;
                }
            }
            if ($vm === null) {
                continue;
            }
            if (!isset($acc[$vm])) {
                $acc[$vm] = ['time' => $time, 'job' => $job, 'count' => 0];
            }
            $acc[$vm]['count']++;
            if ($time > $acc[$vm]['time']) {
                $acc[$vm]['time'] = $time;
                $acc[$vm]['job'] = $job;
            }
        }

        $out = [];
        foreach ($acc as $vm => $a) {
            $out[] = new BackupObject($vm, $vm, '', 'vm', $a['job'], $a['time'], $a['count'], '', false, false);
        }
        return $out;
    }
}
