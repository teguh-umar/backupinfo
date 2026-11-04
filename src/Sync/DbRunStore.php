<?php

namespace GlpiPlugin\Backupinfo\Sync;

use GlpiPlugin\Backupinfo\Source\Run;

/** RunStore over glpi_plugin_backupinfo_runs / _runs_items. Needs GLPI ($DB). */
final class DbRunStore implements RunStore
{
    public const RUNS  = 'glpi_plugin_backupinfo_runs';
    public const ITEMS = 'glpi_plugin_backupinfo_runs_items';

    public function known(int $connectionId, array $uuids): array
    {
        global $DB;

        $out = [];
        foreach (array_chunk(array_values(array_unique($uuids)), 500) as $chunk) {
            foreach ($DB->request([
                'SELECT' => ['history_uuid'],
                'FROM'   => self::RUNS,
                'WHERE'  => ['connections_id' => $connectionId, 'history_uuid' => $chunk],
            ]) as $row) {
                $out[(string) $row['history_uuid']] = true;
            }
        }
        return $out;
    }

    public function insert(int $connectionId, Run $run, string $reason, array $items): bool
    {
        global $DB;

        if ($this->known($connectionId, [$run->historyUuid]) !== []) {
            return false;
        }
        $DB->insert(self::RUNS, [
            'connections_id' => $connectionId,
            'history_uuid'   => mb_substr($run->historyUuid, 0, 64),
            'job_name'       => mb_substr($run->jobName, 0, 255),
            'job_uuid'       => mb_substr($run->jobUuid, 0, 64),
            'start_time'     => $run->start,
            'finish_time'    => $run->finish,
            'status'         => $run->status,
            'status_text'    => $run->statusText,
            'size_text'      => $run->sizeText,
            'duration_s'     => $run->durationS,
            'reason'         => $reason === '' ? null : $reason,
            'date_creation'  => date('Y-m-d H:i:s'),
        ]);
        $runsId = (int) $DB->insertId();
        foreach ($items as $i) {
            $DB->insert(self::ITEMS, [
                'runs_id'      => $runsId,
                'computers_id' => (int) $i['computers_id'],
                'vm_name'      => mb_substr((string) $i['vm_name'], 0, 255),
                'item_status'  => mb_substr((string) $i['item_status'], 0, 20),
            ]);
        }
        return true;
    }

    public function purgeOlderThan(int $months, int $nowS): int
    {
        global $DB;

        if ($months <= 0) {
            return 0;
        }
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-$months months", $nowS));
        $ids    = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::RUNS,
            'WHERE'  => ['OR' => [
                ['start_time' => ['<', $cutoff]],
                ['start_time' => null, 'date_creation' => ['<', $cutoff]],
            ]],
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $DB->delete(self::ITEMS, ['runs_id' => $chunk]);
            $DB->delete(self::RUNS, ['id' => $chunk]);
        }
        return count($ids);
    }
}
