<?php

require __DIR__ . '/bootstrap.php';

// Minimal core stub so the itemtype can load without GLPI.
class CommonDBTM {}

require BI_ROOT . '/src/RightDefinition.php';
require BI_ROOT . '/src/RestoreInterval.php';

use GlpiPlugin\Backupinfo\RestoreInterval;

$read = static fn (string $rel): string => (string) @file_get_contents(BI_ROOT . '/' . $rel);
$now  = strtotime('2026-11-04 10:00:00');

// --- effective interval: per-Computer override, else the Setup default
check('effective: no override -> global', RestoreInterval::effective(12, null) === 12);
check('effective: override wins', RestoreInterval::effective(12, 3) === 3);
check('effective: override 0 = exempt', RestoreInterval::effective(12, 0) === 0);
check('effective: global 0, no override = no requirement', RestoreInterval::effective(0, null) === 0);

// --- status
$cases = [
    // [last test, months, expected]
    [null, 0, 'exempt'],
    ['2020-01-01', 0, 'exempt'],
    [null, 12, 'none'],
    ['2026-06-01', 12, 'ok'],
    ['2025-12-01', 12, 'due'],      // due 2026-12-01, within 30 days
    ['2025-12-05', 12, 'ok'],       // due 2026-12-05: 31 days away
    ['2025-11-04', 12, 'due'],      // due today: still due, not overdue
    ['2025-11-03', 12, 'overdue'],  // due yesterday
    ['2026-08-03', 3, 'overdue'],   // due 2026-11-03
    ['2026-09-01', 3, 'due'],       // due 2026-12-01
];
foreach ($cases as [$last, $months, $want]) {
    $got = RestoreInterval::status($last, $months, $now);
    check(sprintf('status(%s, %d) = %s', $last ?? 'null', $months, $want), $got === $want, $got);
}
check('status: future-dated test counts as ok', RestoreInterval::status('2026-12-01', 12, $now) === 'ok');
check('status: garbage date = none', RestoreInterval::status('n/a', 12, $now) === 'none');

check('nextDue: date + months', RestoreInterval::nextDue('2025-11-30', 3) === '2026-02-28');
check('nextDue: month end clamps (no overflow into March)', RestoreInterval::nextDue('2025-11-30', 3) !== '2026-03-02');
check('nextDue: exempt -> null', RestoreInterval::nextDue('2025-11-30', 0) === null);
check('nextDue: no test -> null', RestoreInterval::nextDue(null, 12) === null);

check('parse override: blank = no override', RestoreInterval::parseOverride('') === ['ok' => true, 'months' => null]);
check('parse override: 0 = exempt', RestoreInterval::parseOverride('0') === ['ok' => true, 'months' => 0]);
check('parse override: 3', RestoreInterval::parseOverride(' 3 ') === ['ok' => true, 'months' => 3]);
check('parse override: over 120 refused', RestoreInterval::parseOverride('121')['ok'] === false);
check('parse override: text refused', RestoreInterval::parseOverride('x')['ok'] === false);

// --- RestoreTest input validation (pure part)
require BI_ROOT . '/src/RestoreTest.php';
use GlpiPlugin\Backupinfo\RestoreTest;

$ok = ['computers_id' => '5', 'date' => '2026-11-01', 'method' => 'vm_restore', 'result' => 'pass', 'rto_minutes' => '45', 'comment' => 'x'];
$v  = RestoreTest::validate($ok, $now, true);
check('validate: good input, no errors', $v['errors'] === [], json_encode($v['errors']));
check('validate: source forced manual, ref cleared', $v['input']['source'] === 'manual' && !array_key_exists('source_ref', $v['input']));
check('validate: source cannot be posted as vinchin', RestoreTest::validate($ok + ['source' => 'vinchin', 'source_ref' => 'r'], $now, true)['input']['source'] === 'manual');
check('validate: casts', $v['input']['computers_id'] === 5 && $v['input']['rto_minutes'] === 45);
check('validate: blank RTO -> null', RestoreTest::validate(['rto_minutes' => ''] + $ok, $now, true)['input']['rto_minutes'] === null);
foreach ([
    'missing computer'  => ['computers_id' => '0'],
    'future date'       => ['date' => '2026-11-05'],
    'bad date'          => ['date' => '2026-02-30'],
    'bad method'        => ['method' => 'guess'],
    'vinchin by hand'   => ['method' => 'vinchin_verify'],
    'bad result'        => ['result' => 'maybe'],
    'negative RTO'      => ['rto_minutes' => '-1'],
] as $label => $bad) {
    check("validate: $label refused", RestoreTest::validate($bad + $ok, $now, true)['errors'] !== []);
}
check('validate update: only posted keys are checked', RestoreTest::validate(['id' => 3, 'comment' => 'y'], $now, false)['errors'] === []);
check('validate update: entity and computer cannot be moved', !array_key_exists('entities_id', RestoreTest::validate(['id' => 3, 'entities_id' => 9], $now, false)['input']));

check('methods for people exclude the Vinchin import', !array_key_exists('vinchin_verify', RestoreTest::manualMethods()) && count(RestoreTest::manualMethods()) === 3);

// --- wiring
$cls = $read('src/RestoreTest.php');
check('itemtype right', str_contains($cls, 'public static $rightname = RightDefinition::RESTORETEST;') && RightDefinitionCheck::ok());
check('history on', str_contains($cls, 'public $dohistory = true;'));
check('documents and history tabs', str_contains($cls, "addStandardTab(Document_Item::class") && str_contains($cls, "addStandardTab(Log::class"));
check('imported Vinchin rows are read-only', (bool) preg_match('/function canUpdateItem\(\): bool\s*\{\s*if \(\(\$this->fields\[\'source\'\] \?\? \'\'\) === self::SOURCE_VINCHIN\)/', $cls)
    && (bool) preg_match('/function canPurgeItem\(\): bool\s*\{\s*if \(\(\$this->fields\[\'source\'\] \?\? \'\'\) === self::SOURCE_VINCHIN\)/', $cls));
check('entity from the Computer on add', str_contains($cls, "\$input['entities_id']  = (int) \$computer->getEntityID();"));
check('add refuses a Computer the user cannot see', str_contains($cls, '!$computer->can($input[\'computers_id\'], READ)'));

$form = $read('front/restoretest.form.php');
check('form: right checked', str_contains($form, 'Session::checkRight(RightDefinition::RESTORETEST, READ);'));
check('form: add/update/purge go through check()', str_contains($form, '$test->check(-1, CREATE, $_POST);') && str_contains($form, '$test->check($id, UPDATE);') && str_contains($form, '$test->check($id, PURGE);'));
check('form: interval needs config UPDATE and a visible Computer', str_contains($form, 'Session::checkRight(RightDefinition::CONFIG, UPDATE);') && str_contains($form, '$computer->can($computerId, READ)'));
check('form: no raw $DB writes', !preg_match('/\$DB->(insert|update|delete|doQuery)/', $form));
$list = $read('front/restoretest.php');
check('list: Search::show with right and sub-nav', str_contains($list, 'Search::show(RestoreTest::class);') && str_contains($list, "renderSubNav('backupinfo', 'restoretests')"));

$prof = $read('src/Profile.php');
check('profile matrix offers the restore-test right', str_contains($prof, "'field'    => RightDefinition::RESTORETEST,"));
check('Super-Admin gets every restore-test right', str_contains($prof, 'RightDefinition::RESTORETEST => ALLSTANDARDRIGHT,'));
$menu = $read('src/Menu.php');
check('menu: restore tests behind its right', (bool) preg_match("/haveRight\(RightDefinition::RESTORETEST, READ\)\)\s*\{\s*\\\$options\['restoretests'\]/", $menu));
$tab = $read('src/AssetTab.php');
check('asset tab: restore block with status, next due, add link', str_contains($tab, 'renderRestore(') && str_contains($tab, 'RestoreInterval::status(') && str_contains($tab, 'RestoreInterval::nextDue('));
check('asset tab: restore block also when no backup object', substr_count($tab, 'self::renderRestore($item);') === 2
    && (bool) preg_match('/No backup object is matched to this computer\.[^\n]*\n\s*self::renderRestore\(\$item\);/', $tab));
check('asset tab: interval form for config editors only, CSRF via closeForm', str_contains($tab, 'Session::haveRight(RightDefinition::CONFIG, UPDATE)') && str_contains($tab, "'name' => 'set_interval'") && str_contains($tab, 'Html::closeForm();'));
$cfg = $read('src/SuiteConfig.php');
check('Setup: restore interval and run retention inputs', str_contains($cfg, 'name="restore_interval_months"') && str_contains($cfg, 'name="run_keep_months"'));

done();

final class RightDefinitionCheck
{
    public static function ok(): bool
    {
        return \GlpiPlugin\Backupinfo\RightDefinition::RESTORETEST === 'plugin_backupinfo_restoretest';
    }
}
