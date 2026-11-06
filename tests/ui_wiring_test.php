<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/RightDefinition.php';
require BI_ROOT . '/src/Rpo.php';
require BI_ROOT . '/src/Status.php';
require BI_ROOT . '/src/Policy.php';
require BI_ROOT . '/src/SuiteConfig.php';
require BI_ROOT . '/src/SearchOptions.php';
require BI_ROOT . '/src/AssetTab.php';
require BI_ROOT . '/src/CoveragePage.php';

use GlpiPlugin\Backupinfo\AssetTab;
use GlpiPlugin\Backupinfo\CoveragePage;
use GlpiPlugin\Backupinfo\SearchOptions;
use GlpiPlugin\Backupinfo\SuiteConfig;

// Task 12: UI wiring. Source-string checks (OpManager style) plus the pure helpers.
$read = static fn (string $rel): string => (string) @file_get_contents(BI_ROOT . '/' . $rel);

// ---- Asset tab: a "Backup" section of the shared PGN Suite tab on Computer ----
$setup = $read('setup.php');
check('shared AssetTab loaded only when no other plugin did', (bool) preg_match("/if \\(!class_exists\\(\\\\GlpiPlugin\\\\Pgnsuite\\\\AssetTab::class, false\\)\\) \\{\\s*require_once __DIR__ \\. '\\/lib\\/pgnsuite\\/AssetTab\\.php';/", $setup));
check('shared AssetTab registered on Computer', str_contains($setup, "\\GlpiPlugin\\Pgnsuite\\AssetTab::register(['Computer']);"));
check('backupinfo asset section provider hooked', str_contains($setup, "\$PLUGIN_HOOKS[\\GlpiPlugin\\Pgnsuite\\AssetTab::HOOK]['backupinfo'] = \\GlpiPlugin\\Backupinfo\\AssetTab::class . '::sections';"));
check('wrench in Setup > Plugins opens the Backup Info settings section', str_contains($setup, "\$PLUGIN_HOOKS['config_page']['backupinfo'] = 'front/pgnsuite.settings.php?section=backupinfo';"));
check('no own addtabon on Computer', !preg_match("/addtabon'\\s*=>\\s*\\[[^\\]]*'Computer'/", $setup));

$tab = $read('src/AssetTab.php');
check('section titled Backup', str_contains($tab, "'title'  => __('Backup', 'backupinfo')"));
check('section needs view READ', (bool) preg_match('/function sections\(CommonDBTM \$item\): array\s*\{\s*if \(!Session::haveRight\(RightDefinition::VIEW, READ\)/', $tab));
check('section only on Computer', str_contains($tab, '$item instanceof Computer'));
check('section respects the Computer entity', str_contains($tab, 'Session::haveAccessToEntity('));
check('section shows mandatory and last sync', str_contains($tab, 'isMandatory(') && str_contains($tab, 'last_sync'));
check('section key in suite pattern', AssetTab::KEY === 'backup' && str_contains($tab, "'key'    => self::KEY"));
check('section order 100 (orchestrator ruling; 80 is mist)', AssetTab::ORDER === 100);

check('age: minutes', AssetTab::age(300) === '5 min');
check('age: hours', AssetTab::age(3 * 3600 + 60) === '3 h');
check('age: days', AssetTab::age(3 * 86400) === '3 d');
check('age: negative clamps to 0', AssetTab::age(-10) === '0 min');
check('size: bytes', AssetTab::size(512) === '512 B');
check('size: GiB', AssetTab::size(3 * 1024 ** 3) === '3.0 GiB');
check('size: zero is empty', AssetTab::size(0) === '');
check('sizeText: vendor text kept', AssetTab::sizeText('12.5 GB') === '12.5 GB');
check('sizeText: bare bytes formatted', AssetTab::sizeText('2048') === '2.0 KiB');
check('sizeText: empty stays empty', AssetTab::sizeText('') === '');

// ---- Search options on Computer, IDs 8370-8379 ----
$hook = $read('hook.php');
check('search options hook function declared in hook.php', str_contains($hook, 'function plugin_backupinfo_getAddSearchOptionsNew($itemtype)'));
check('search options gated on view READ', (bool) preg_match('/getAddSearchOptionsNew\(\$itemtype\)\s*\{[^}]*Session::haveRight\(RightDefinition::VIEW, READ\)/s', $hook) || (bool) preg_match('/getAddSearchOptionsNew\(\$itemtype\)\s*\{[^}]*Session::haveRight\(\\\\GlpiPlugin\\\\Backupinfo\\\\RightDefinition::VIEW, READ\)/s', $hook));
check('setup.php does not declare the search hook (no redeclare)', !str_contains($setup, 'function plugin_backupinfo_getAddSearchOptionsNew'));
$opts = SearchOptions::forItemtype('Computer');
$ids  = array_map(static fn (array $o): int => (int) $o['id'], $opts);
check('search options: at least status and last backup', count($opts) >= 2);
check('search option ids all in 8370-8379', $ids !== [] && min($ids) >= 8370 && max($ids) <= 8379);
check('search option ids unique', count(array_unique($ids)) === count($ids));
$byId = array_column($opts, null, 'id');
check('8370 last backup is a datetime on the objects table', ($byId[8370]['table'] ?? '') === 'glpi_plugin_backupinfo_objects'
    && ($byId[8370]['field'] ?? '') === 'last_backup' && ($byId[8370]['datatype'] ?? '') === 'datetime');
check('8371 backup status (object count) on the objects table', ($byId[8371]['table'] ?? '') === 'glpi_plugin_backupinfo_objects'
    && ($byId[8371]['datatype'] ?? '') === 'count' && !empty($byId[8371]['usehaving']));
$joinsOk = true;
foreach ($opts as $o) {
    if (($o['joinparams']['jointype'] ?? '') !== 'itemtype_item' || empty($o['forcegroupby']) || ($o['massiveaction'] ?? true) !== false) {
        $joinsOk = false;
    }
}
check('every option joins by itemtype_item, grouped, no massive action', $joinsOk);
check('no options on other itemtypes', SearchOptions::forItemtype('Monitor') === []);

// ---- Setup section ----
$sc = $read('src/SuiteConfig.php');
check('section posts to front/config.php', str_contains($sc, "/plugins/backupinfo/front/config.php"));
check('section lists connections with in-section edit links', str_contains($sc, "self::connectionLink((int) \$c['id'])"));
check('section "Add a connection" only with CREATE', (bool) preg_match('/haveRight\(RightDefinition::CONFIG, CREATE\)\) \{\s*echo [^;]*self::connectionLink\(0\)/', $sc));
check('section shows the connection form in place', str_contains($sc, '$conn->showForm($id);'));
check('in-section form: existing id needs READ on it, new needs CREATE',
    str_contains($sc, '$ok   = $id > 0 ? $conn->can($id, READ) : Session::haveRight(RightDefinition::CONFIG, CREATE);'));
check('connectionLink: open form', \GlpiPlugin\Backupinfo\SuiteConfig::connectionLink(5) === '?section=backupinfo&bi_connection=5');
check('connectionLink: new form', \GlpiPlugin\Backupinfo\SuiteConfig::connectionLink(0) === '?section=backupinfo&bi_connection=0');
check('connectionLink: closed', \GlpiPlugin\Backupinfo\SuiteConfig::connectionLink(null) === '?section=backupinfo');
check('section shows FieldsBridge problems', str_contains($sc, '->problems()') && str_contains($sc, '->available()'));
check('section offers containers and fields by name', str_contains($sc, '->containerOptions()') && str_contains($sc, '->fields('));
check('section has the manual policy picker', str_contains($sc, "'manual_ids[]'") && str_contains($sc, "'multiple'"));
check('sync now form only for UPDATE', (bool) preg_match('/if \(\$canedit\) \{[^}]*sync_now/s', $sc));
check('forms closed with Html::closeForm (CSRF token)', substr_count($sc, 'Html::closeForm()') >= 2);
check('section not wrapped by its own Html::header', !str_contains($sc, 'Html::header'));

$ok = SuiteConfig::sanitize(['rpo_hours' => '48', 'policy_mode' => 'manual', 'fields_container' => ' Backup ', 'fields_status_field' => 'backupstatusfield']);
check('sanitize keeps a valid RPO as int', ($ok['values']['rpo_hours'] ?? null) === 48 && $ok['errors'] === []);
check('sanitize keeps manual mode', ($ok['values']['policy_mode'] ?? '') === 'manual');
check('sanitize trims field names', ($ok['values']['fields_container'] ?? '') === 'Backup' && ($ok['values']['fields_status_field'] ?? '') === 'backupstatusfield');
foreach (['0', '721', '-3', 'abc', '2.5', ''] as $bad) {
    $r = SuiteConfig::sanitize(['rpo_hours' => $bad]);
    check("sanitize rejects RPO '{$bad}'", !array_key_exists('rpo_hours', $r['values']) && count($r['errors']) === 1);
}
check('sanitize accepts RPO bounds', (SuiteConfig::sanitize(['rpo_hours' => '1'])['values']['rpo_hours'] ?? 0) === 1
    && (SuiteConfig::sanitize(['rpo_hours' => '720'])['values']['rpo_hours'] ?? 0) === 720);
foreach (['-1', '169', 'x', '1.5', ''] as $bad) {
    $r = SuiteConfig::sanitize(['rpo_grace_hours' => $bad]);
    check("sanitize rejects grace '{$bad}'", !array_key_exists('rpo_grace_hours', $r['values']) && count($r['errors']) === 1);
}
check('sanitize accepts grace 0 and 168', (SuiteConfig::sanitize(['rpo_grace_hours' => '0'])['values']['rpo_grace_hours'] ?? null) === 0
    && (SuiteConfig::sanitize(['rpo_grace_hours' => '168'])['values']['rpo_grace_hours'] ?? null) === 168);
$sc = $read('src/SuiteConfig.php');
check('Setup has the grace field', str_contains($sc, 'name="rpo_grace_hours"'));
$at = $read('src/AssetTab.php');
check('asset tab: RPO per row from Rpo', str_contains($at, 'Rpo::fromConfig()') && str_contains($at, '$rpo->label($o)'));
foreach (['src/AssetTab.php', 'src/Policy.php', 'src/Cron.php', 'front/coverage.php'] as $f) {
    check("{$f}: no own rpo_hours read (Rpo::fromConfig)", !str_contains($read($f), "['rpo_hours']"));
}
check('sanitize maps unknown policy mode to field', (SuiteConfig::sanitize(['policy_mode' => 'x'])['values']['policy_mode'] ?? '') === 'field');
check('sanitize ignores absent keys', SuiteConfig::sanitize([])['values'] === []);
check('sanitize ignores foreign keys', !array_key_exists('api_key', SuiteConfig::sanitize(['api_key' => 'x'])['values']));

$cfg = $read('front/config.php');
check('config.php: includes.php', str_contains($cfg, "include('../../../inc/includes.php');"));
check('config.php: config READ first', (bool) preg_match('/includes\.php\'\);\s*Session::checkRight\(RightDefinition::CONFIG, READ\);/', $cfg));
check('config.php: sync now needs config UPDATE', (bool) preg_match("/isset\\(\\\$_POST\\['sync_now'\\]\\)\\) \\{\\s*Session::checkRight\\(RightDefinition::CONFIG, UPDATE\\);/", $cfg));
check('config.php: save needs config UPDATE', (bool) preg_match("/isset\\(\\\$_POST\\['update'\\]\\)\\) \\{\\s*Session::checkRight\\(RightDefinition::CONFIG, UPDATE\\);/", $cfg));
check('config.php: sync now runs the cron code path', str_contains($cfg, 'Cron::runSync('));
check('config.php: returns to the suite section', substr_count($cfg, "ConfigTab::getReturnUrl('backupinfo')") >= 2);
check('config.php: no own CSRF re-check (kernel already checked it)', !str_contains($cfg, 'checkCSRF'));
check('config.php: saves the manual list only with UPDATE', str_contains($cfg, 'Policy::setManual('));
check('plugin is CSRF compliant', str_contains($setup, "\$PLUGIN_HOOKS['csrf_compliant']['backupinfo'] = true;"));

$cron = $read('src/Cron.php');
check('cron delegates to runSync', (bool) preg_match('/function cronSync\(CronTask \$task\): int\s*\{.*?self::runSync\(/s', $cron));
check('runSync is public static', str_contains($cron, 'public static function runSync(callable $log): array'));

// ---- Front controllers ----
$fronts = [
    'coverage.php'        => 'RightDefinition::REPORT, READ',
    'objects.php'         => 'RightDefinition::VIEW, READ',
    'config.php'          => 'RightDefinition::CONFIG, READ',
    'connection.php'      => 'RightDefinition::CONFIG, READ',
    'connection.form.php' => 'RightDefinition::CONFIG, READ',
];
foreach ($fronts as $file => $right) {
    $src = $read('front/' . $file);
    check("{$file}: exists", $src !== '');
    check("{$file}: includes.php", str_contains($src, "include('../../../inc/includes.php');"));
    check("{$file}: checkRight({$right})", str_contains($src, "Session::checkRight({$right})"));
    check("{$file}: RightDefinition imported", str_contains($src, 'use GlpiPlugin\Backupinfo\RightDefinition;'));
    check("{$file}: no old 'plugins' sector", !str_contains($src, "'plugins', 'backupinfo'"));
}
foreach (['coverage.php' => 'coverage', 'objects.php' => 'objects', 'probe.php' => 'probe'] as $file => $opt) {
    $src = $read('front/' . $file);
    check("{$file}: header in the shared sector + sub-nav", (bool) preg_match("/Html::header\\([^;]*Menu::SECTOR, 'backupinfo', '{$opt}'\\);\\s*\\\\GlpiPlugin\\\\Pgnsuite\\\\Menu::renderSubNav\\('backupinfo', '{$opt}'\\);/", $src));
    check("{$file}: footer", str_contains($src, 'Html::footer();'));
}

$obj = $read('front/objects.php');
check('objects.php: pin needs config UPDATE', (bool) preg_match("/isset\\(\\\$_POST\\['pin'\\]\\)\\) \\{\\s*Session::checkRight\\(RightDefinition::CONFIG, UPDATE\\);/", $obj));
check('objects.php: unpin needs config UPDATE', (bool) preg_match("/isset\\(\\\$_POST\\['unpin'\\]\\)\\) \\{\\s*Session::checkRight\\(RightDefinition::CONFIG, UPDATE\\);/", $obj));
check('objects.php: pin stores manual', str_contains($obj, "'match_state' => 'manual'") && str_contains($obj, "'itemtype'    => 'Computer'"));
check('objects.php: unpin stores unmatched', str_contains($obj, "'match_state' => 'unmatched'"));
check('objects.php: pin checks the Computer is visible', str_contains($obj, '->can($cid, READ)'));
check('objects.php: entity restriction', str_contains($obj, "getEntitiesRestrictCriteria('glpi_computers')"));
check('objects.php: unmatched rows only with config READ', str_contains($obj, '$canConfig = Session::haveRight(RightDefinition::CONFIG, READ);'));
check('objects.php: forms closed with Html::closeForm', str_contains($obj, 'Html::closeForm(false)'));

$cov = $read('front/coverage.php');
check('coverage.php: entity restriction', str_contains($cov, "getEntitiesRestrictCriteria('glpi_computers')"));
check('coverage.php: unmatched list only with config READ', str_contains($cov, 'Session::haveRight(RightDefinition::CONFIG, READ)'));
check('coverage.php: no charts', !preg_match('/chart/i', $cov));

$b = CoveragePage::build(
    [1 => ['name' => 'a'], 2 => ['name' => 'b'], 3 => ['name' => 'c']],
    [1 => [['last_backup' => 1000 - 3600]], 2 => [['last_backup' => 1000 - 30 * 3600]]],
    26,
    1000
);
check('coverage counts', $b['counts'] === ['mandatory' => 3, 'protected' => 1, 'expired' => 1, 'none' => 1]);
check('coverage percent protected', $b['percent'] === 33);
check('coverage lists missing (none then expired)', array_column($b['missing'], 'id') === [3, 2]);
check('coverage empty is 0 %', CoveragePage::build([], [], 26, 1000)['percent'] === 0);

// ---- Fields container stored by system name (review I2) ----
$cs = [
    ['id' => 5, 'name' => 'backupinformation', 'label' => 'Backup Information'],
    ['id' => 6, 'name' => 'other', 'label' => 'Other'],
];
[$o, $sel, $cid] = SuiteConfig::containerChoices($cs, 'backupinformation');
check('container option values are system names', array_keys($o) === ['', 'backupinformation', 'other']);
check('container option text is label (name)', $o['backupinformation'] === 'Backup Information (backupinformation)');
check('saved name preselected with its id', $sel === 'backupinformation' && $cid === 5);
[$o, $sel, $cid] = SuiteConfig::containerChoices($cs, 'backup information');
check('legacy saved label resolves and preselects the name', $sel === 'backupinformation' && $cid === 5);
[$o, $sel, $cid] = SuiteConfig::containerChoices([['id' => 5, 'name' => 'backupinformation', 'label' => 'Renamed']], 'backupinformation');
check('renamed label still resolves by name', $sel === 'backupinformation' && $cid === 5);
[$o, $sel, $cid] = SuiteConfig::containerChoices($cs, 'gone');
check('unknown saved value kept as "(not found)"', $sel === 'gone' && $cid === 0 && str_contains($o['gone'], 'not found'));
[$o, $sel, $cid] = SuiteConfig::containerChoices([['id' => 5, 'name' => 'a', 'label' => 'Dup'], ['id' => 6, 'name' => 'b', 'label' => 'Dup']], 'Dup');
check('ambiguous legacy label does not resolve', $cid === 0);

// ---- Connections: add / update / purge (review I1) ----
$prof = $read('src/Profile.php');
check('matrix offers READ, UPDATE, CREATE, PURGE on config', (bool) preg_match("/'rights'\s*=>\s*\[READ => __\('Read'\), UPDATE => __\('Update'\), CREATE => __\('Create'\), PURGE => __\('Delete permanently'\)\],\s*'label'\s*=>\s*__\('Configure Backup Info'/", $prof));
check('view and report stay READ only', substr_count($prof, "'rights'   => [READ => __('Read')],") === 2);
check('Super-Admin seeded with ALLSTANDARDRIGHT on config', str_contains($prof, 'RightDefinition::CONFIG => ALLSTANDARDRIGHT'));
check('seed via updateProfileRights (idempotent)', str_contains($prof, 'ProfileRight::updateProfileRights('));
check('install calls Profile::install on every run', str_contains($read('install/install.php'), 'Profile::install();'));
$cf = $read('front/connection.form.php');
check('connection add: check CREATE', (bool) preg_match('/isset\(\$_POST\[\'add\'\]\)\) \{\s*\$connection->check\(-1, CREATE, \$_POST\);/', $cf));
check('connection add: back to the section with the new form open', str_contains($cf, 'Html::redirect(SuiteConfig::sectionUrl($new ? (int) $new : 0));'));
check('connection update: back to the section', str_contains($cf, 'Html::redirect(SuiteConfig::sectionUrl($id));'));
check('connection update: check UPDATE', (bool) preg_match('/isset\(\$_POST\[\'update\'\]\)\) \{\s*\$connection->check\(\$id, UPDATE\);/', $cf));
check('connection purge (delete alias): check PURGE', (bool) preg_match('/isset\(\$_POST\[\'purge\'\]\) \|\| isset\(\$_POST\[\'delete\'\]\)\) \{\s*\$connection->check\(\$id, PURGE\);\s*\$connection->delete\(\[\'id\' => \$id\], true\);\s*Html::redirect\(SuiteConfig::sectionUrl\(\)\);/', $cf));
check('connections table has no trash (delete alias is safe)', !preg_match('/glpi_plugin_backupinfo_connections`[^;]*is_deleted/s', $read('install/install.php')));
check('connection.form.php GET forwards to the section', str_ends_with(rtrim($cf), 'Html::redirect(SuiteConfig::sectionUrl($id));') && !str_contains($cf, 'displayFullPageForItem'));
check('connection.php forwards to the section', str_contains($read('front/connection.php'), 'Html::redirect(SuiteConfig::sectionUrl());'));
check('Menu: no connection entry (connections live in Setup)', !str_contains($read('src/Menu.php'), "\$options['connection']") && !str_contains($read('src/Menu.php'), 'front/connection'));

// ---- Menu ----
$menu = $read('src/Menu.php');
foreach (['dashboard', 'jobs', 'report', 'storage', 'assets'] as $gone) {
    check("Menu: no {$gone} page", !str_contains($menu, "front/{$gone}.php"));
    check("front/{$gone}.php deleted", !file_exists(BI_ROOT . "/front/{$gone}.php"));
}
check('Menu: coverage, objects, probe entries', str_contains($menu, "front/coverage.php") && str_contains($menu, "front/objects.php") && str_contains($menu, "front/probe.php"));
check('Menu: options keyed coverage/objects/probe', str_contains($menu, "\$options['coverage']") && str_contains($menu, "\$options['objects']") && str_contains($menu, "\$options['probe']"));
check('Menu: each option behind its right', str_contains($menu, 'Session::haveRight(RightDefinition::REPORT, READ)')
    && str_contains($menu, 'Session::haveRight(RightDefinition::VIEW, READ)')
    && str_contains($menu, 'Session::haveRight(RightDefinition::CONFIG, UPDATE)'));
check('objects filter is a bordered button group, not nav-pills', str_contains($obj, '<div class="btn-group" role="group"') && !str_contains($obj, '<ul class="nav nav-pills'));
check('src/Api removed', !is_dir(BI_ROOT . '/src/Api'));

// --- 0.4.0 tickets settings
$t = SuiteConfig::sanitize(['tickets_enabled' => '1', 'findings_enabled' => '0', 'ticket_template' => '5', 'problem_template' => '-2', 'problem_count' => '3', 'problem_days' => '400']);
check('tickets: switches as 0/1, template ids never negative', $t['values']['tickets_enabled'] === 1 && $t['values']['findings_enabled'] === 0 && $t['values']['ticket_template'] === 5 && $t['values']['problem_template'] === 0);
check('tickets: problem window out of range refused, the rest kept', !isset($t['values']['problem_days']) && $t['values']['problem_count'] === 3 && count($t['errors']) === 1);
$now = strtotime('2026-11-05 10:00:00');
check('tickets: switching on stores the start time', SuiteConfig::withTicketsSince(['tickets_enabled' => 1], false, $now)['tickets_since'] === '2026-11-05 10:00:00');
check('tickets: saving while on keeps the start time', !isset(SuiteConfig::withTicketsSince(['tickets_enabled' => 1], true, $now)['tickets_since']));
check('tickets: off stays off, no start time', !isset(SuiteConfig::withTicketsSince(['tickets_enabled' => 0], false, $now)['tickets_since']));

// --- Setup tabs (0.4.0-rc3)
$nav = SuiteConfig::tabNav(true, false);
check('setup tabs: four tabs in order, Connections shown first', preg_match_all('/data-tab="([a-z]+)"/', $nav, $m) === 4 && $m[1] === ['connections', 'policy', 'fields', 'tickets'] && substr_count($nav, 'nav-link active') === 1);
check('setup tabs: Fields marked when the sync cannot write Fields', str_contains($nav, 'ti-alert-triangle') && !str_contains(SuiteConfig::tabNav(false, false), 'ti-alert-triangle'));
$sc = $read('src/SuiteConfig.php');
check('setup tabs: connection editor outside the settings form (no nested form), Save on each settings tab', strpos($sc, 'self::connections();') < strpos($sc, '// One settings form across the other tabs') && substr_count($sc, 'self::saveButtons(') === 3);

$form = strpos($sc, "// One settings form across the other tabs");
$tail = substr($sc, (int) $form, 900);
check('setup tabs: every pane a direct child of a .tab-content (Bootstrap hides only those), the form has its own',
    $form !== false && preg_match_all('/tab-content/', $tail) >= 2 && strpos($tail, '<form method') < strpos($tail, "'<div class=\"tab-content\">'")
    && strpos($tail, "'<div class=\"tab-content\">'") < strpos($tail, 'id="backupinfo-tab-policy"'));

done();
