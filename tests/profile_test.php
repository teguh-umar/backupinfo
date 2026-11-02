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
    (bool) preg_match('/ProfileRight::updateProfileRights\(\(int\) \$row\[\'profiles_id\'\], \[RightDefinition::CONFIG => \$new\]\)/', $prof));
check('grant uses the pure helper', str_contains($prof, 'self::upgradedConfigRight((int) $row[\'rights\'])'));

$install = (string) file_get_contents(BI_ROOT . '/install/install.php');
$guarded = preg_match('/if \(empty\(\$marker\[\'config_rights_v020\'\]\)\) \{(.*?)\r?\n    \}\r?\n/s', $install, $m) ? $m[1] : '';
check('grant runs inside the config_rights_v020 marker block', str_contains($guarded, 'Profile::grantCreatePurgeToEditors()'));
check('marker set after the grant', ($g = strpos($guarded, 'grantCreatePurgeToEditors')) !== false
    && ($s = strpos($guarded, "'config_rights_v020' => 1")) !== false && $s > $g);
check('marker set only once in install', substr_count($install, "'config_rights_v020' =>") === 1);
check('grant runs after Super-Admin seeding',
    ($a = strpos($install, 'Profile::install()')) !== false && ($b = strpos($install, 'grantCreatePurgeToEditors')) !== false && $b > $a);

done();
