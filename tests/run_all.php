<?php

// Runs every tests/*_test.php in its own PHP process (stubs differ per suite).
// Children get no php.ini: forward the extension dir and the extensions the parent loaded.
$ini = '-d extension_dir=' . escapeshellarg((string) ini_get('extension_dir'));
foreach (['openssl', 'curl'] as $ext) {
    if (extension_loaded($ext)) {
        $ini .= ' -d extension=' . $ext;
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
