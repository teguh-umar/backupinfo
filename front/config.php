<?php

/**
 * Save handler for Backup Info's section on the shared PGN Suite settings page, and
 * "Sync now". Always returns to that section; there is no page of its own.
 * CSRF: checked by the GLPI 11 kernel for every POST (plugin is csrf_compliant).
 */

use GlpiPlugin\Backupinfo\Cron;
use GlpiPlugin\Backupinfo\Policy;
use GlpiPlugin\Backupinfo\RightDefinition;
use GlpiPlugin\Backupinfo\SuiteConfig;
use GlpiPlugin\Pgnsuite\ConfigTab;

include('../../../inc/includes.php');
Session::checkRight(RightDefinition::CONFIG, READ);
global $DB; // front files run inside a function under GLPI 11 routing

if (isset($_POST['sync_now'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);

    $run = Cron::runSync(static function (string $line): void {
        // Per-line detail goes to the cron log only; the session message is the summary below.
    });
    if ($run['results'] === []) {
        Session::addMessageAfterRedirect(__s('No active connection: nothing to sync.', 'backupinfo'), false, WARNING);
    }
    foreach ($run['results'] as $r) {
        if ($r['status'] === 'ok') {
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('%1$s: fetched %2$d, matched %3$d, needs review %4$d, not found %5$d', 'backupinfo'),
                $r['name'],
                $r['stats']['fetched'],
                $r['stats']['matched'],
                $r['stats']['review'],
                $r['stats']['unmatched']
            )));
        } else {
            // The error line (no secrets) is kept on the connection and shown in its list.
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('%s: sync failed, see the connection list for the error.', 'backupinfo'),
                $r['name']
            )), false, ERROR);
        }
    }
    if ($run['fields_error'] !== '') {
        Session::addMessageAfterRedirect(__s('Fields update failed (see the backupinfo log).', 'backupinfo'), false, ERROR);
    } elseif ($run['results'] !== []) {
        Session::addMessageAfterRedirect(htmlescape(sprintf(__('Computers updated in Fields: %d', 'backupinfo'), $run['touched'])));
    }
    Html::redirect(ConfigTab::getReturnUrl('backupinfo'));
}

if (isset($_POST['update'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);

    $result = SuiteConfig::sanitize($_POST);
    if ($result['values'] !== []) {
        Config::setConfigurationValues(SuiteConfig::CONTEXT, $result['values']);
    }
    foreach ($result['errors'] as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }

    if (!empty($_POST['_manual_present'])) {
        $posted = array_map('intval', (array) ($_POST['manual_ids'] ?? []));

        // The picker only offers Computers in the active entities: keep the others as they are.
        $existing = [];
        foreach ($DB->request(['SELECT' => ['items_id'], 'FROM' => 'glpi_plugin_backupinfo_policies', 'WHERE' => ['itemtype' => Policy::ITEMTYPE]]) as $row) {
            $existing[] = (int) $row['items_id'];
        }
        // Posted ids count only when they are Computers this user can see.
        $visible = [];
        $check   = array_values(array_unique(array_filter(array_merge($posted, $existing), static fn (int $id): bool => $id > 0)));
        if ($check !== []) {
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => ['id' => $check, 'is_template' => 0] + getEntitiesRestrictCriteria('glpi_computers'),
            ]) as $row) {
                $visible[] = (int) $row['id'];
            }
        }
        Policy::setManual(array_merge(array_intersect($posted, $visible), array_diff($existing, $visible)));
    }

    if ($result['errors'] === []) {
        Session::addMessageAfterRedirect(__s('Settings saved', 'backupinfo'));
    }
    Html::redirect(ConfigTab::getReturnUrl('backupinfo'));
}

Html::redirect(ConfigTab::getReturnUrl('backupinfo'));
