<?php

// Runs every tests/*_test.php in its own PHP process (stubs differ per suite).
// Only when the parent has no php.ini: forward the extension dir and loaded extensions.
$ini = '';
if (php_ini_loaded_file() === false) {
    $ini = '-d extension_dir=' . escapeshellarg((string) ini_get('extension_dir'));
    foreach (['openssl', 'curl'] as $ext) {
        if (extension_loaded($ext)) {
            $ini .= ' -d extension=' . $ext;
        }
    }
}
$failed = 0;
foreach (glob(__DIR__ . '/*_test.php') as $file) {
    echo '== ' . basename($file) . "\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . $ini . ' ' . escapeshellarg($file), $code);
    if ($code !== 0) {
        $failed++;
    }
}
echo $failed === 0 ? "SUITES OK\n" : "SUITES FAILED: {$failed}\n";
exit($failed === 0 ? 0 : 1);
