<?php

require __DIR__ . '/bootstrap.php';

// Profile.php extends core \Profile; a bare stub is enough to load the pure helper.
class Profile {}
require BI_ROOT . '/src/RightDefinition.php';
require BI_ROOT . '/src/Profile.php';

use GlpiPlugin\Backupinfo\Profile as BiProfile;

// Pure: what the 0.2.0 upgrade turns a stored config right into (null = leave it).
check('UPDATE holder gets CREATE and PURGE', BiProfile::upgradedConfigRight(READ | UPDATE) === (READ | UPDATE | CREATE | PURGE));
check('UPDATE-only holder gets CREATE and PURGE', BiProfile::upgradedConfigRight(UPDATE) === (UPDATE | CREATE | PURGE));
check('READ-only holder is left alone', BiProfile::upgradedConfigRight(READ) === null);
check('no right is left alone', BiProfile::upgradedConfigRight(0) === null);
check('already complete is left alone', BiProfile::upgradedConfigRight(READ | UPDATE | CREATE | PURGE) === null);
check('other bits are kept', BiProfile::upgradedConfigRight(READ | UPDATE | DELETE) === (READ | UPDATE | DELETE | CREATE | PURGE));

$prof = (string) file_get_contents(BI_ROOT . '/src/Profile.php');
check('grant reads the config right rows by name',
    (bool) preg_match("/'FROM'\s*=>\s*'glpi_profilerights',\s*'WHERE'\s*=>\s*\['name'\s*=>\s*RightDefinition::CONFIG\]/", $prof));
check('grant writes through ProfileRight::updateProfileRights',
    (bool) preg_match('/ProfileRight::updateProfileRights\(\$profilesId, \[RightDefinition::CONFIG => \$new\]\)/', $prof));
check('grant uses the pure helper', str_contains($prof, 'self::upgradedConfigRight($rights)'));
check('read-back re-reads the stored rights after writing',
    (bool) preg_match('/updateProfileRights.*?return self::allGranted\(self::storedConfigRights\(\)\);/s', $prof));

// Hub §4 (2026-11-02): a one-shot marker is set only after reading back the step's effect.
check('read-back: all done when no row still needs the grant', BiProfile::allGranted([READ, READ | UPDATE | CREATE | PURGE, 0]) === true);
check('read-back: not done while an UPDATE holder lacks CREATE/PURGE', BiProfile::allGranted([READ | UPDATE | CREATE | PURGE, READ | UPDATE]) === false);
check('read-back: no rows is done', BiProfile::allGranted([]) === true);
check('grant returns the read-back result', (bool) preg_match('/public static function grantCreatePurgeToEditors\(\): bool/', $prof)
    && str_contains($prof, 'return self::allGranted('));

$install = (string) file_get_contents(BI_ROOT . '/install/install.php');
$guarded = preg_match('/if \(empty\(\$marker\[\'config_rights_v020\'\]\)\) \{(.*?)\r?\n    \}\r?\n/s', $install, $m) ? $m[1] : '';
check('grant runs inside the config_rights_v020 marker block', str_contains($guarded, 'Profile::grantCreatePurgeToEditors()'));
check('marker set only when the read-back succeeded',
    (bool) preg_match("/if \(\\\\GlpiPlugin\\\\Backupinfo\\\\Profile::grantCreatePurgeToEditors\(\)\) \{\s*Config::setConfigurationValues\(\\\$ctx, \['config_rights_v020' => 1\]\);/", $guarded));
check('marker set only once in install', substr_count($install, "'config_rights_v020' =>") === 1);
check('grant runs after Super-Admin seeding',
    ($a = strpos($install, 'Profile::install()')) !== false && ($b = strpos($install, 'grantCreatePurgeToEditors')) !== false && $b > $a);

done();
