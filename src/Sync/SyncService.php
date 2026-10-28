<?php

namespace GlpiPlugin\Backupinfo\Sync;

use GlpiPlugin\Backupinfo\Fields\FieldsBridge;
use GlpiPlugin\Backupinfo\Matching\Matcher;
use GlpiPlugin\Backupinfo\Source\BackupSource;
use GlpiPlugin\Backupinfo\Status;

/**
 * One sync: source -> objects table -> matching -> Fields. Pure of GLPI classes so it
 * runs offline against fakes; Cron supplies the real source, store and bridge.
 */
class SyncService
{
    public const MODE = 'hybrid_name';

    /**
     * @param array<string, mixed> $connectionRow needs 'id'
     * @return array{fetched:int,matched:int,review:int,unmatched:int,affected:list<int>}
     *         affected = Computer ids whose objects were deleted or whose match changed
     */
    public function run(array $connectionRow, BackupSource $source, ObjectStore $store, Matcher $m, int $nowS): array
    {
        $connId   = (int) $connectionRow['id'];
        $objects  = $source->fetch();          // a throw here changes nothing
        $existing = $store->existing($connId);

        $stats    = ['fetched' => count($objects), 'matched' => 0, 'review' => 0, 'unmatched' => 0];
        $affected = [];
        $seen     = [];

        foreach ($objects as $o) {
            $seen[$o->sourceKey] = true;
            $old = $existing[$o->sourceKey] ?? null;

            if ($old !== null && ($old['match_state'] ?? '') === 'manual') {
                $match = ['state' => 'manual', 'itemtype' => (string) $old['itemtype'], 'items_id' => (int) $old['items_id']];
            } elseif ($o->kind === 'vm') {
                $match = $m->match($o->name, $o->ip, self::MODE);
            } else {
                $match = ['state' => Matcher::UNMATCHED, 'itemtype' => '', 'items_id' => 0];
            }

            if ($o->kind === 'vm' || $match['state'] === 'manual') {
                match ($match['state']) {
                    Matcher::REVIEW    => $stats['review']++,
                    Matcher::UNMATCHED => $stats['unmatched']++,
                    default            => $stats['matched']++,
                };
            }

            $oldId = $old === null ? 0 : (int) $old['items_id'];
            if ($oldId !== (int) $match['items_id']) {
                foreach ([$oldId, (int) $match['items_id']] as $id) {
                    if ($id > 0) {
                        $affected[$id] = true;
                    }
                }
            }

            $store->upsert($connId, [
                'source_key'     => $o->sourceKey,
                'name'           => $o->name,
                'ip'             => $o->ip,
                'kind'           => $o->kind,
                'job_name'       => $o->jobName,
                'last_backup'    => $o->lastBackup,
                'restore_points' => $o->restorePoints,
                'data_size'      => $o->dataSize,
                'abnormal_chain' => $o->abnormalChain ? 1 : 0,
                'source_deleted' => $o->sourceDeleted ? 1 : 0,
                'itemtype'       => (string) $match['itemtype'],
                'items_id'       => (int) $match['items_id'],
                'match_state'    => (string) $match['state'],
            ]);
        }

        $stale = [];
        foreach ($existing as $key => $row) {
            if (!isset($seen[$key])) {
                $stale[] = (string) $key;
                if ((int) $row['items_id'] > 0) {
                    $affected[(int) $row['items_id']] = true;
                }
            }
        }
        if ($stale !== []) {
            $store->delete($connId, $stale);
        }

        return $stats + ['affected' => array_map('intval', array_keys($affected))];
    }

    /**
     * Writes Backup Status / Media to Computers that currently have matched objects, plus
     * $extraItemIds (items that just lost theirs get hasBackup=false, blank media). Computers
     * never matched are never touched.
     *
     * @param list<int> $extraItemIds
     * @return int number of assets whose Fields values were changed
     */
    public function applyToAssets(ObjectStore $store, FieldsBridge $f, int $rpoHours, int $nowS, array $extraItemIds = []): int
    {
        if (!$f->available()) {
            return 0;
        }

        $byItem = [];
        foreach ($store->matched() as $row) {
            $byItem[(int) $row['items_id']][] = $row;
        }
        foreach ($extraItemIds as $id) {
            $byItem[(int) $id] ??= [];
        }

        $touched = 0;
        foreach ($byItem as $id => $rows) {
            if ($id <= 0) {
                continue;
            }
            $label = (string) ($rows[0]['source_label'] ?? '');
            $has   = Status::hasBackup(Status::of($rows, $rpoHours, $nowS));
            $media = $rows === [] ? '' : Status::mediaText($rows, $label);
            if ($f->write('Computer', (int) $id, $has, $media)) {
                $touched++;
            }
        }
        return $touched;
    }

    /** One line, at most 255 characters, for connections.last_error. */
    public static function errorLine(\Throwable $e): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));
        $line = $line === '' ? get_class($e) : $line;
        return function_exists('mb_strcut') ? mb_strcut($line, 0, 255, 'UTF-8') : substr($line, 0, 255);
    }
}
