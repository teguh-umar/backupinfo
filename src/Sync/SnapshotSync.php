<?php

namespace GlpiPlugin\Backupinfo\Sync;

/**
 * Keeps the state the backup system only shows "as of now" (0.3.0-rc7): backup targets with their
 * capacity, and the platform VMs with their protection state. One snapshot per connection, kind and
 * day, so the audit page reads GLPI and shows a past period as it was at its end. A kind that fails
 * is reported and the others go on; its last good snapshot stays. Pure (source and store passed in).
 */
final class SnapshotSync
{
    /** kind => AuditSource method */
    public const KINDS = ['target' => 'targets', 'platformvm' => 'platformVms'];

    /**
     * @param object $source an AuditSource
     * @return array{stored: array<string, int>, errors: list<string>}
     */
    public static function run(int $connectionId, object $source, SnapshotStore $store, int $nowS): array
    {
        $out = ['stored' => [], 'errors' => []];
        foreach (self::KINDS as $kind => $method) {
            $res = $source->$method();
            if (($res['error'] ?? '') !== '') {
                $out['errors'][] = $kind . ': ' . $res['error'];
                continue;
            }
            $store->put($connectionId, $kind, date('Y-m-d H:i:s', $nowS), array_values($res['rows']));
            $out['stored'][$kind] = count($res['rows']);
        }
        return $out;
    }
}
