<?php

namespace GlpiPlugin\Backupinfo;

/**
 * Backup summary cards on the Backup objects page (spec 2026-11-11, the user's DCOC "Backup
 * summary" tab): counts, last backup age, by kind, needs attention, largest objects. Pure: the
 * page passes the rows the user can see, their newest runs, the RPO and "now".
 */
final class Summary
{
    /** Last backup age buckets ("freshness", renamed by the user), key => label. */
    public const AGE_BUCKETS = [
        'day'   => '≤ 24 hours',
        'days3' => '1–3 days',
        'days7' => '3–7 days',
        'older' => '> 7 days',
        'none'  => 'No timestamp',
    ];

    /** Needs attention reasons, in severity order (worst first). */
    public const REASONS = [
        'chain'   => 'Broken chain',
        'deleted' => 'Source deleted',
        'failed'  => 'Last run failed',
        'never'   => 'No backup',
        'expired' => 'Older than RPO',
    ];

    private const UNITS = ['B' => 0, 'KB' => 1, 'MB' => 2, 'GB' => 3, 'TB' => 4, 'PB' => 5];
    private const LARGEST = 10;

    /** "683.47GB", "317.5 GB", "0B" => bytes (1024 steps). Anything else counts 0. */
    public static function bytes(string $size): int
    {
        if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMGTP]?B)\s*$/i', $size, $m)) {
            return 0;
        }
        return (int) round((float) $m[1] * 1024 ** self::UNITS[strtoupper($m[2])]);
    }

    /** Bytes for display: "512 B", "22.0 GB", "1.1 PB". */
    public static function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return max(0, $bytes) . ' B';
        }
        $unit  = 'B';
        $value = (float) $bytes;
        foreach (array_keys(self::UNITS) as $u) {
            if ($u === 'B') {
                continue;
            }
            if ($value < 1024) {
                break;
            }
            $value /= 1024;
            $unit   = $u;
        }
        return sprintf('%.1f %s', $value, $unit);
    }

    /** Seconds since the last backup, or null when there is none or it cannot be read. */
    private static function ageSeconds(?string $lastBackup, int $nowS): ?int
    {
        $text = trim((string) $lastBackup);
        if ($text === '' || str_starts_with($text, '0000-00-00')) {
            return null;
        }
        $ts = strtotime($text);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return max(0, $nowS - $ts); // a time in the future counts as just now
    }

    /** One of the AGE_BUCKETS keys. */
    public static function ageBucket(?string $lastBackup, int $nowS): string
    {
        $age = self::ageSeconds($lastBackup, $nowS);
        return match (true) {
            $age === null       => 'none',
            $age <= 86400       => 'day',
            $age <= 3 * 86400   => 'days3',
            $age <= 7 * 86400   => 'days7',
            default             => 'older',
        };
    }

    /**
     * Why an object needs attention, REASONS keys in severity order ([] = fine).
     *
     * @param array<string, mixed> $object objects row
     * @param array<string, mixed>|null $run its newest stored run (ObjectsView::lastRuns())
     * @return list<string>
     */
    public static function reasons(array $object, ?array $run, Rpo $rpo, int $nowS): array
    {
        $age = self::ageSeconds(isset($object['last_backup']) ? (string) $object['last_backup'] : null, $nowS);
        $out = [];
        if (!empty($object['abnormal_chain'])) {
            $out[] = 'chain';
        }
        if (!empty($object['source_deleted'])) {
            $out[] = 'deleted';
        }
        if ($run !== null && in_array(ObjectsView::result($run), ['failed', 'fail'], true)) {
            $out[] = 'failed';
        }
        if ($age === null) {
            $out[] = 'never';
        } elseif ($age > $rpo->hoursFor($object) * 3600) {
            $out[] = 'expired';
        }
        return $out;
    }

    /**
     * The card data. Rows of inactive connections are left out (their objects no longer count
     * as backups); every other row is one the user can see.
     *
     * @param list<array<string, mixed>> $objects page rows (with connection_name, connection_active)
     * @param array<string, array<string, mixed>> $last ObjectsView::lastRuns()
     * @param array<int, array<string, string>> $gapsByConnection connection id => Registry::gaps(type)
     * @return array<string, mixed>
     */
    public static function build(array $objects, array $last, Rpo $rpo, int $nowS, array $gapsByConnection): array
    {
        $m = [
            'count' => 0, 'kinds' => 0, 'sources' => 0, 'fresh' => 0, 'freshPct' => null,
            'bytes' => 0, 'points' => 0, 'pointsAvg' => null, 'matched' => 0,
            'age' => array_fill_keys(array_keys(self::AGE_BUCKETS), 0),
            'byKind' => [], 'attention' => [], 'attentionIds' => [], 'reasonCounts' => [],
            'largest' => [], 'notes' => [], 'sizeReported' => true,
        ];
        $kinds = [];
        $conns = [];
        $sized = [];
        $attention = [];
        foreach ($objects as $o) {
            if (empty($o['connection_active'])) {
                continue;
            }
            $id     = (int) $o['id'];
            $cid    = (int) ($o['connections_id'] ?? 0);
            $kind   = trim((string) ($o['kind'] ?? '')) ?: 'other';
            $bytes  = self::bytes((string) ($o['data_size'] ?? ''));
            $bucket = self::ageBucket(isset($o['last_backup']) ? (string) $o['last_backup'] : null, $nowS);
            $run    = $last[$cid . '|' . mb_strtolower(trim((string) ($o['name'] ?? '')))] ?? null;

            $m['count']++;
            $m['bytes']  += $bytes;
            $m['points'] += (int) ($o['restore_points'] ?? 0);
            $m['age'][$bucket]++;
            if ((int) ($o['items_id'] ?? 0) > 0) {
                $m['matched']++;
            }
            if ($bucket === 'day') {
                $m['fresh']++;
            }
            $conns[$cid] = (string) ($o['connection_name'] ?? '');
            $kinds[$kind] ??= ['kind' => $kind, 'label' => Status::kindLabel($kind), 'count' => 0, 'bytes' => 0, 'fresh' => 0];
            $kinds[$kind]['count']++;
            $kinds[$kind]['bytes'] += $bytes;
            $kinds[$kind]['fresh'] += $bucket === 'day' ? 1 : 0;
            if ($bytes > 0) {
                $sized[] = ['id' => $id, 'name' => (string) $o['name'], 'bytes' => $bytes];
            }
            $reasons = self::reasons($o, $run, $rpo, $nowS);
            if ($reasons !== []) {
                $age = self::ageSeconds(isset($o['last_backup']) ? (string) $o['last_backup'] : null, $nowS);
                $attention[] = [
                    'id' => $id, 'name' => (string) $o['name'], 'job' => (string) ($o['job_name'] ?? ''),
                    'ageDays' => $age === null ? null : intdiv($age, 86400), 'reasons' => $reasons,
                    '_rank' => array_search($reasons[0], array_keys(self::REASONS), true), '_age' => $age ?? PHP_INT_MAX,
                ];
                foreach ($reasons as $r) {
                    $m['reasonCounts'][$r] = ($m['reasonCounts'][$r] ?? 0) + 1;
                }
            }
        }

        $m['kinds']   = count($kinds);
        $m['sources'] = count($conns);
        if ($m['count'] > 0) {
            $m['freshPct']  = $m['fresh'] * 100 / $m['count'];
            $m['pointsAvg'] = $m['points'] / $m['count'];
        }
        // Reason counts in severity order.
        $m['reasonCounts'] = array_intersect_key(array_replace(array_fill_keys(array_keys(self::REASONS), 0), $m['reasonCounts']), $m['reasonCounts']);

        usort($kinds, static fn(array $a, array $b): int => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);
        foreach ($kinds as $k) {
            $m['byKind'][] = ['kind' => $k['kind'], 'label' => $k['label'], 'count' => $k['count'], 'bytes' => $k['bytes'], 'freshPct' => $k['fresh'] * 100 / $k['count']];
        }

        // Worst reason first, then the oldest backup (none = oldest).
        usort($attention, static fn(array $a, array $b): int => [$a['_rank'], $b['_age']] <=> [$b['_rank'], $a['_age']]);
        foreach ($attention as $a) {
            unset($a['_rank'], $a['_age']);
            $m['attention'][]            = $a;
            $m['attentionIds'][$a['id']] = true;
        }

        usort($sized, static fn(array $a, array $b): int => [$b['bytes'], $a['name']] <=> [$a['bytes'], $b['name']]);
        $m['largest'] = array_slice($sized, 0, self::LARGEST);

        $noSize  = [];
        $noChain = [];
        foreach ($conns as $cid => $name) {
            $gaps = $gapsByConnection[$cid] ?? [];
            if (isset($gaps['size'])) {
                $noSize[] = $name;
            }
            if (isset($gaps['chain'])) {
                $noChain[] = $name;
            }
        }
        // Spec §4.7: no silent zeros when none of the counted sources reports a size.
        $m['sizeReported'] = $conns === [] || count($noSize) < count($conns);
        if ($noSize !== []) {
            $m['notes'][] = 'Size not reported by: ' . implode(', ', $noSize) . '.';
        }
        if ($noChain !== []) {
            $m['notes'][] = 'Broken chain and source deleted not checked for: ' . implode(', ', $noChain) . '.';
        }
        return $m;
    }
}
