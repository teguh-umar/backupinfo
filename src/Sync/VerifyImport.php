<?php

namespace GlpiPlugin\Backupinfo\Sync;

/**
 * Restore-point verifications (/backup_data/verify_history) as restore-test records.
 * The row-to-input mapping lives in the brand's VerifyMapper. This class keeps only the
 * round-robin batch() helper and the PER_RUN cap; it names no brand.
 */
final class VerifyImport
{
    /** Verification reads per cron run; objects are taken round-robin. */
    public const PER_RUN = 50;

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
