<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Rpo.php';
require BI_ROOT . '/src/Status.php';

use GlpiPlugin\Backupinfo\Status;

$now = 1800000000;
$h = 3600;

// Protected / expired by age of the newest backup.
check(
    'newest backup 25 h old with RPO 26 h is protected',
    Status::of([['last_backup' => $now - 25 * $h]], 26, $now) === Status::PROTECTED
);
check(
    'newest backup 27 h old with RPO 26 h is expired',
    Status::of([['last_backup' => $now - 27 * $h]], 26, $now) === Status::EXPIRED
);
check(
    'age exactly equal to RPO is protected (age <= rpo)',
    Status::of([['last_backup' => $now - 26 * $h]], 26, $now) === Status::PROTECTED
);
check(
    'newest of several objects decides the status',
    Status::of([
        ['last_backup' => $now - 100 * $h],
        ['last_backup' => $now - 2 * $h],
        ['last_backup' => $now - 50 * $h],
    ], 26, $now) === Status::PROTECTED
);
check(
    'old objects only are expired',
    Status::of([
        ['last_backup' => $now - 100 * $h],
        ['last_backup' => $now - 50 * $h],
    ], 26, $now) === Status::EXPIRED
);

// Objects with no backup time still count as a backup object.
check(
    'objects with only null times are expired',
    Status::of([['last_backup' => null], ['last_backup' => null]], 26, $now) === Status::EXPIRED
);
check(
    'null time mixed with a fresh time is protected',
    Status::of([['last_backup' => null], ['last_backup' => $now - $h]], 26, $now) === Status::PROTECTED
);

// String values from $DB are cast; '' is null.
check(
    'string epoch from $DB is cast and judged',
    Status::of([['last_backup' => (string) ($now - 25 * $h)]], 26, $now) === Status::PROTECTED
);
check(
    "empty string time is treated as null (expired, not protected)",
    Status::of([['last_backup' => '']], 26, $now) === Status::EXPIRED
);

// No objects.
check('no objects is none', Status::of([], 26, $now) === Status::NONE);

// hasBackup.
check('hasBackup(protected) is true', Status::hasBackup(Status::PROTECTED) === true);
check('hasBackup(expired) is true', Status::hasBackup(Status::EXPIRED) === true);
check('hasBackup(none) is false', Status::hasBackup(Status::NONE) === false);

// Media text.
check(
    'mediaText builds source, kind and job',
    Status::mediaText([['kind' => 'vm', 'job_name' => 'DAILY_PRODUCTION']], 'Vinchin')
        === 'Vinchin · VM · DAILY_PRODUCTION'
);
check(
    'mediaText is empty with no objects',
    Status::mediaText([], 'Vinchin') === ''
);
check(
    'mediaText maps database kind label',
    Status::mediaText([['kind' => 'database', 'job_name' => 'SQL_NIGHTLY']], 'Vinchin')
        === 'Vinchin · Database · SQL_NIGHTLY'
);
check(
    'mediaText maps unknown kind to Other',
    Status::mediaText([['kind' => 'mystery', 'job_name' => 'J']], 'Vinchin') === 'Vinchin · Other · J'
);
check(
    'mediaText omits the job segment when job is empty',
    Status::mediaText([['kind' => 'file', 'job_name' => '']], 'Vinchin') === 'Vinchin · File'
);

// Several objects: the newest object's kind and job are used.
check(
    'mediaText uses the newest object when several are given',
    Status::mediaText([
        ['kind' => 'file', 'job_name' => 'OLD_FILE_JOB', 'last_backup' => $now - 50 * $h],
        ['kind' => 'vm', 'job_name' => 'DAILY_PRODUCTION', 'last_backup' => $now - 2 * $h],
        ['kind' => 'agent', 'job_name' => 'AGENT_JOB', 'last_backup' => $now - 10 * $h],
    ], 'Vinchin') === 'Vinchin · VM · DAILY_PRODUCTION'
);

done();
