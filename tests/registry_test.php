<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Source/BackupObject.php';
require BI_ROOT . '/src/Source/BackupSource.php';
require BI_ROOT . '/src/Source/ProbeableSource.php';
require BI_ROOT . '/src/Source/AuditProbeableSource.php';
require BI_ROOT . '/src/Source/RunHistorySource.php';
require BI_ROOT . '/src/Source/AuditSource.php';
require BI_ROOT . '/src/Source/PartialFetch.php';
require BI_ROOT . '/src/Source/Registry.php';
require BI_ROOT . '/src/Source/VerifyingSource.php';
require BI_ROOT . '/src/Vinchin/Signer.php';
require BI_ROOT . '/src/Http/Transport.php';
require BI_ROOT . '/src/Http/CurlTransport.php';
require BI_ROOT . '/src/Vinchin/Client.php';
require BI_ROOT . '/src/Vinchin/ItemMapper.php';
require BI_ROOT . '/src/Vinchin/JobHistoryMapper.php';
require BI_ROOT . '/src/Vinchin/ScheduleMapper.php';
require BI_ROOT . '/src/Vinchin/VinchinSource.php';
require BI_ROOT . '/src/Veeam/Client.php';
require BI_ROOT . '/src/Veeam/VeeamSource.php';

use GlpiPlugin\Backupinfo\Source\BackupObject;
use GlpiPlugin\Backupinfo\Source\Registry;
use GlpiPlugin\Backupinfo\Veeam\VeeamSource;
use GlpiPlugin\Backupinfo\Vinchin\VinchinSource;

// Registry types / labels / per-type facts.
check('types() has vinchin and veeam', Registry::types() === ['vinchin' => 'Vinchin', 'veeam' => 'Veeam B&R 13 (preview, not verified on a server)']);
check('labels()', Registry::labels() === ['vinchin' => 'Vinchin', 'veeam' => 'Veeam']);
check('DEFAULT_TYPE is vinchin', Registry::DEFAULT_TYPE === 'vinchin');
check('formFields(vinchin) === VinchinSource::FORM_FIELDS', Registry::formFields('vinchin') === VinchinSource::FORM_FIELDS);
check('formFields(unknown) === []', Registry::formFields('nope') === []);
check('defaults(vinchin)', Registry::defaults('vinchin') === ['auth_mode' => 'api_key']);
check('gaps(vinchin) === []', Registry::gaps('vinchin') === []);

// Veeam type is registered
check('types() has veeam', Registry::types()['veeam'] === 'Veeam B&R 13 (preview, not verified on a server)');
check('labels() has veeam', Registry::labels()['veeam'] === 'Veeam');
check('formFields(veeam) has api_version, no api_key', in_array('api_version', Registry::formFields('veeam'), true) && !in_array('api_key', Registry::formFields('veeam'), true));
check('defaults(veeam)', Registry::defaults('veeam') === ['auth_mode' => 'login', 'api_version' => '1.3-rev2']);
check('gaps(veeam)[targets] starts with tape', str_starts_with(Registry::gaps('veeam')['targets'] ?? '', 'tape'));

// Unknown type throws
$decrypt = fn(string $s): string => $s;
check('transport is brand-neutral', is_subclass_of(\GlpiPlugin\Backupinfo\Http\CurlTransport::class, \GlpiPlugin\Backupinfo\Http\Transport::class) && !file_exists(BI_ROOT . '/src/Vinchin/Transport.php'));

// Transport lives in a brand-neutral namespace; the Vinchin files are gone.
check('transport is brand-neutral', is_subclass_of(\GlpiPlugin\Backupinfo\Http\CurlTransport::class, \GlpiPlugin\Backupinfo\Http\Transport::class) && !file_exists(BI_ROOT . '/src/Vinchin/Transport.php'));

// Unknown type throws.
$decrypt = fn(string $s): string => $s;
$threw = false;
try {
    Registry::create(['type' => 'nope'], $decrypt);
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('create() throws InvalidArgumentException for unknown type', $threw);

$threw = false;
try {
    Registry::create([], $decrypt);
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('create() throws InvalidArgumentException when type is missing', $threw);

// Vinchin row builds a source (full mapping covered in vinchin_mapper_test.php).
$vinchinRow = ['type' => 'vinchin', 'host' => 'h', 'auth_mode' => 'api_key', 'api_key' => 'ENC', 'verify_tls' => 1];
$seen = [];
$src = Registry::create($vinchinRow, function (string $s) use (&$seen): string { $seen[] = $s; return 'dec'; });
check('create() builds a BackupSource for vinchin', $src instanceof \GlpiPlugin\Backupinfo\Source\BackupSource);
check('create() decrypts the api key', in_array('ENC', $seen, true));

$veeamRow = ['type' => 'veeam', 'host' => 'vbr.example.test', 'username' => 'u', 'password' => 'ENC', 'api_version' => '1.3-rev2', 'verify_tls' => 1];
$srcV = Registry::create($veeamRow, fn(string $s): string => $s);
check('create() builds a VeeamSource for veeam', $srcV instanceof VeeamSource);

// api_version '' (or missing) defaults to '1.3-rev2', never an empty header value.
$veeamRowEmpty = ['type' => 'veeam', 'host' => 'vbr.example.test', 'username' => 'u', 'password' => 'ENC', 'api_version' => '', 'verify_tls' => 1];
$srcVE = Registry::create($veeamRowEmpty, fn(string $s): string => $s);
$rc = new ReflectionProperty(VeeamSource::class, 'client');
$rc->setAccessible(true);
$client = $rc->getValue($srcVE);
$ra = new ReflectionProperty($client, 'apiVersion');
$ra->setAccessible(true);
check('registry: empty api_version defaults to 1.3-rev2', $ra->getValue($client) === '1.3-rev2');

// BackupObject carries every field from the contract.
$obj = new BackupObject(
    sourceKey: 'vinchin:1',
    name: 'web-01',
    ip: '10.0.0.5',
    kind: 'vm',
    jobName: 'nightly',
    lastBackup: 1790000000,
    restorePoints: 3,
    dataSize: '12 GB',
    abnormalChain: false,
    sourceDeleted: true,
);
check('BackupObject sourceKey', $obj->sourceKey === 'vinchin:1');
check('BackupObject name', $obj->name === 'web-01');
check('BackupObject ip', $obj->ip === '10.0.0.5');
check('BackupObject kind', $obj->kind === 'vm');
check('BackupObject jobName', $obj->jobName === 'nightly');
check('BackupObject lastBackup', $obj->lastBackup === 1790000000);
check('BackupObject restorePoints', $obj->restorePoints === 3);
check('BackupObject dataSize', $obj->dataSize === '12 GB');
check('BackupObject abnormalChain', $obj->abnormalChain === false);
check('BackupObject sourceDeleted', $obj->sourceDeleted === true);

// lastBackup may be null (never backed up).
$none = new BackupObject('vinchin:2', 'db-01', '', 'database', '', null, 0, '', false, false);
check('BackupObject lastBackup nullable', $none->lastBackup === null);

// Properties are readonly.
$locked = false;
try {
    $obj->name = 'other';
} catch (\Error $e) {
    $locked = true;
}
check('BackupObject is readonly', $locked && $obj->name === 'web-01');

done();
