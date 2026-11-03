<?php

// Offline test harness: no GLPI, no DB.
// Run one suite:  php tests/<name>_test.php
// Run all:        php tests/run_all.php
// Count results with an ANCHORED grep ('^PASS'), never a bare 'PASS' (hub §4).
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('BI_ROOT', dirname(__DIR__));

foreach (['READ' => 1, 'UPDATE' => 2, 'CREATE' => 4, 'DELETE' => 8, 'PURGE' => 16] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

$GLOBALS['__bi_pass'] = 0;
$GLOBALS['__bi_fail'] = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['__bi_pass']++;
        echo "PASS {$label}\n";
        return;
    }
    $GLOBALS['__bi_fail']++;
    echo "FAIL {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function done(): void
{
    $pass = $GLOBALS['__bi_pass'];
    $fail = $GLOBALS['__bi_fail'];
    echo ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ({$pass} passed, {$fail} failed)\n";
    exit($fail === 0 ? 0 : 1);
}

if (!function_exists('__')) {
    function __($s, $d = null) { return $s; }
}
if (!function_exists('__s')) {
    function __s($s, $d = null) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('_n')) {
    function _n($s, $p, $n, $d = null) { return $n == 1 ? $s : $p; }
}
if (!function_exists('_sx')) {
    function _sx($ctx, $s, $d = null) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('htmlescape')) {
    function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
