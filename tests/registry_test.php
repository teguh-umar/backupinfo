<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Source/BackupObject.php';
require BI_ROOT . '/src/Source/BackupSource.php';
require BI_ROOT . '/src/Source/Registry.php';

use GlpiPlugin\Backupinfo\Source\BackupObject;
use GlpiPlugin\Backupinfo\Source\Registry;

// Registry types.
check('types() is vinchin only', Registry::types() === ['vinchin' => 'Vinchin']);

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
