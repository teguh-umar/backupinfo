<?php

namespace GlpiPlugin\Backupinfo;

/**
 * Short-lived cache of the audit page's live reads (one per connection, method and period), so the
 * page, its sections and the exports do not each page through the backup system again. Only a read
 * without error is kept. The cache is GLPI's (PSR-16); any cache failure falls back to the read.
 */
final class AuditCache
{
    /** Seconds: a closed period no longer changes much; an open one does. */
    private const TTL_CLOSED = 3600;
    private const TTL_OPEN   = 300;

    public static function key(int $connectionId, string $method, array $args): string
    {
        return 'backupinfo_audit_' . $connectionId . '_' . md5($method . '|' . json_encode($args));
    }

    public static function ttl(string $periodTo, int $nowS): int
    {
        return $periodTo < date('Y-m-d H:i:s', $nowS) ? self::TTL_CLOSED : self::TTL_OPEN;
    }

    /**
     * @param object|null $cache PSR-16 cache (get/set), or null
     * @param callable(): array{rows: list<array<string, mixed>>, error: string} $read
     * @return array{rows: list<array<string, mixed>>, error: string}
     */
    public static function remember(?object $cache, string $key, int $ttl, callable $read): array
    {
        if ($cache !== null) {
            try {
                $hit = $cache->get($key);
                if (is_array($hit) && is_array($hit['rows'] ?? null) && ($hit['error'] ?? null) === '') {
                    return $hit;
                }
            } catch (\Throwable $e) {
                // cache down: read live
            }
        }
        $res = $read();
        if ($cache !== null && $res['error'] === '') {
            try {
                $cache->set($key, $res, $ttl);
            } catch (\Throwable $e) {
                // not cached: next view reads live again
            }
        }
        return $res;
    }
}
