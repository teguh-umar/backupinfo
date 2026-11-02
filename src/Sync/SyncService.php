<?php

namespace GlpiPlugin\Backupinfo\Sync;

use GlpiPlugin\Backupinfo\Fields\FieldsBridge;
use GlpiPlugin\Backupinfo\Matching\Matcher;
use GlpiPlugin\Backupinfo\Source\BackupSource;
use GlpiPlugin\Backupinfo\Source\PartialFetch;
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
        $partial  = $source instanceof PartialFetch && $source->lastFetchWasPartial();
        $existing = [];
        foreach ($store->existing($connId) as $key => $row) {
            $existing[self::key((string) $key)] = $row; // the DB unique key is case-insensitive
        }

        // A successful but empty answer while rows exist is far likelier a source problem than
        // every backup vanishing at once; refuse rather than flip every asset to "no backup".
        if ($objects === [] && $existing !== []) {
            throw new \RuntimeException(sprintf('source returned no objects; kept %d stored rows', count($existing)));
        }

        $stats    = ['fetched' => count($objects), 'matched' => 0, 'review' => 0, 'unmatched' => 0];
        $affected = [];
        $seen     = [];

        foreach ($objects as $o) {
            $seen[self::key($o->sourceKey)] = true;
            $old = $existing[self::key($o->sourceKey)] ?? null;

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
                'source_key'     => $old !== null ? (string) $old['source_key'] : self::cap($o->sourceKey, 255),
                'name'           => self::cap($o->name, 255),
                'ip'             => self::cap($o->ip, 255),
                'kind'           => self::cap($o->kind, 50),
                'job_name'       => self::cap($o->jobName, 255),
                'last_backup'    => $o->lastBackup,
                'restore_points' => $o->restorePoints,
                'data_size'      => self::cap($o->dataSize, 64),
                'abnormal_chain' => $o->abnormalChain ? 1 : 0,
                'source_deleted' => $o->sourceDeleted ? 1 : 0,
                'itemtype'       => (string) $match['itemtype'],
                'items_id'       => (int) $match['items_id'],
                'match_state'    => (string) $match['state'],
            ]);
        }

        $stale = [];
        foreach ($partial ? [] : $existing as $key => $row) {
            if (!isset($seen[$key])) {
                $stale[] = (string) $row['source_key'];
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

    private static function key(string $sourceKey): string
    {
        return mb_strtolower($sourceKey);
    }

    /** Fit a value to its column (multibyte-safe) so one long value cannot fail the whole run. */
    private static function cap(string $value, int $width): string
    {
        return mb_substr($value, 0, $width);
    }

    /**
     * Syncs every connection; one failure (source factory or run) is recorded and the next
     * connection still runs. Rows of inactive or deleted connections are purged first.
     *
     * @param list<array<string, mixed>> $connections active connection rows
     * @param callable(array): BackupSource $sourceFactory
     * @return array{results: list<array{id:int,name:string,status:string,error:string,stats:array}>, affected: list<int>}
     */
    public function runAll(array $connections, callable $sourceFactory, ObjectStore $store, Matcher $m, int $nowS): array
    {
        $affected = [];
        foreach ($store->purgeInactive() as $id) {
            $affected[(int) $id] = true;
        }

        $results = [];
        foreach ($connections as $conn) {
            $res = ['id' => (int) $conn['id'], 'name' => (string) ($conn['name'] ?? ''), 'status' => 'ok', 'error' => '', 'stats' => []];
            try {
                $stats = $this->run($conn, $sourceFactory($conn), $store, $m, $nowS);
                foreach ($stats['affected'] as $id) {
                    $affected[(int) $id] = true;
                }
                unset($stats['affected']);
                $res['stats'] = $stats;
            } catch (\Throwable $e) {
                $res['status'] = 'error';
                $res['error']  = self::errorLine($e);
            }
            $results[] = $res;
        }

        return ['results' => $results, 'affected' => array_map('intval', array_keys($affected))];
    }

    /** GLPI cron return: -1 every connection failed, 1 something synced or was applied, 0 nothing to do. */
    public static function cronResult(array $results, int $touched): int
    {
        foreach ($results as $r) {
            if ($r['status'] === 'ok') {
                return 1;
            }
        }
        if ($touched > 0) {
            return 1;
        }
        return $results === [] ? 0 : -1;
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
