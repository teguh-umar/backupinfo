<?php

/**
 * Probe: a read-only sample from one connection (raw rows, the plugin's mapping, the match
 * result), to check a source before trusting a full sync. Writes nothing. Needs config UPDATE
 * because it uses the stored credentials; CSRF is checked by the GLPI 11 kernel.
 */

use GlpiPlugin\Backupinfo\Connection;
use GlpiPlugin\Backupinfo\Cron;
use GlpiPlugin\Backupinfo\Matching\DbAssetLookup;
use GlpiPlugin\Backupinfo\Matching\Matcher;
use GlpiPlugin\Backupinfo\Probe;
use GlpiPlugin\Backupinfo\RightDefinition;
use GlpiPlugin\Backupinfo\Source\Registry;

include('../../../inc/includes.php');
Session::checkRight(RightDefinition::CONFIG, UPDATE);

/** @var \DBmysql $DB */
global $DB; // front files run inside a function under GLPI 11 routing

$connections = [];
foreach ($DB->request(['FROM' => Connection::getTable(), 'ORDER' => 'name']) as $row) {
    $connections[(int) $row['id']] = $row;
}
$selected = (int) ($_POST['connections_id'] ?? 0);

Html::header(__('Probe', 'backupinfo'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'backupinfo', 'probe');

echo '<div class="card mb-3"><div class="card-body">';
echo '<p>' . __s('Takes a small sample (one page per endpoint) from a connection and shows what the backup system returns, how the plugin reads it and which Computer it matches. Nothing is saved.', 'backupinfo') . '</p>';
echo '<form method="post" action="' . htmlescape(Html::getPrefixedUrl('/plugins/backupinfo/front/probe.php')) . '" class="d-flex gap-2 align-items-end">';
$options = [0 => Dropdown::EMPTY_VALUE];
foreach ($connections as $id => $row) {
    $options[$id] = $row['name'] . ' (' . (Registry::types()[$row['type']] ?? $row['type']) . ($row['is_active'] ? '' : ', ' . __('inactive', 'backupinfo')) . ')';
}
echo '<div class="flex-grow-1"><label class="form-label">' . __s('Connection', 'backupinfo') . '</label>';
Dropdown::showFromArray('connections_id', $options, ['value' => $selected, 'width' => '100%']);
echo '</div>';
echo Html::submit(__('Take sample', 'backupinfo'), ['name' => 'probe', 'class' => 'btn btn-primary']);
Html::closeForm();
echo '</div></div>';

if (isset($_POST['probe']) && isset($connections[$selected])) {
    $result = Probe::run(
        $connections[$selected],
        Cron::decryptor(),
        new Matcher(new DbAssetLookup()),
        Probe::LIMIT,
        [Cron::class, 'computerNames']
    );

    foreach ($result['errors'] as $error) {
        echo '<div class="alert alert-warning">' . htmlescape($error) . '</div>';
    }

    // 1. Raw rows (credentials masked by Probe)
    foreach ($result['raw'] as $endpoint => $rows) {
        echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">'
            . htmlescape(sprintf(__('Raw: %1$s (%2$d rows)', 'backupinfo'), $endpoint, count($rows))) . '</h3></div>';
        echo '<div class="card-body"><pre class="mb-0" style="max-height:30rem;overflow:auto">'
            . htmlescape(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            . '</pre></div></div>';
    }

    // 2. Mapping and 3. match result
    if ($result['objects'] !== []) {
        echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">' . __s('As the plugin reads it', 'backupinfo') . '</h3></div>';
        echo '<div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr>';
        foreach ([__('Name'), __('IP'), __('Kind', 'backupinfo'), __('Job', 'backupinfo'), __('Last backup', 'backupinfo'),
            __('Restore points', 'backupinfo'), __('Size', 'backupinfo'), __('Flags', 'backupinfo'), __('Match', 'backupinfo')] as $head) {
            echo '<th>' . htmlescape($head) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $now = time();
        foreach ($result['objects'] as $i => $o) {
            $last = $o->lastBackup === null ? '—'
                : date('Y-m-d H:i', $o->lastBackup) . ' (' . sprintf(__('%d h ago', 'backupinfo'), intdiv(max(0, $now - $o->lastBackup), 3600)) . ')';
            $flags = array_filter([
                $o->abnormalChain ? __('abnormal chain', 'backupinfo') : '',
                $o->sourceDeleted ? __('source deleted', 'backupinfo') : '',
            ]);
            $match = $result['matches'][$i] ?? null;
            if ($match === null) {
                $matchCell = htmlescape(__('not matched (not a VM)', 'backupinfo'));
            } elseif ($match['state'] === 'matched') {
                $computer = new Computer();
                $matchCell = $computer->getFromDB($match['items_id'])
                    ? $computer->getLink() . ' <span class="text-muted">(' . htmlescape($match['source']) . ')</span>'
                    : htmlescape('#' . $match['items_id']);
            } else {
                $matchCell = htmlescape($match['state']);
            }
            echo '<tr>'
                . '<td>' . htmlescape($o->name) . '</td>'
                . '<td>' . htmlescape($o->ip) . '</td>'
                . '<td>' . htmlescape($o->kind) . '</td>'
                . '<td>' . htmlescape($o->jobName) . '</td>'
                . '<td>' . htmlescape($last) . '</td>'
                . '<td>' . (int) $o->restorePoints . '</td>'
                . '<td>' . htmlescape($o->dataSize) . '</td>'
                . '<td>' . htmlescape(implode(', ', $flags)) . '</td>'
                . '<td>' . $matchCell . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div></div>';
    }
}

Html::footer();
