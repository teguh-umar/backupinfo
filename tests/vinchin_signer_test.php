<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Vinchin/Signer.php';

use GlpiPlugin\Backupinfo\Vinchin\Signer;

// Golden values produced by the Python client (2026-10-28).
check('golden 1', Signer::sign(['offset' => 0, 'limit' => 100, 'search' => ''], 1761638400000)['sign'] ===
    '72eb19f0a053bfc9633ccfe25d735f1a8a7ac8e21b5312ca2be76137abce67a5e4b11f8c4734f6c68943f1bee6b8d49897a9a183db90a71082eaf950148daace');
check('golden 2 (array + bool kept)', Signer::sign(['search' => '', 'sort' => 'item_name', 'order' => 'desc', 'module_type' => [], 'offset' => 0, 'limit' => 100, 'flag' => true], 1761638400000)['sign'] ===
    '4e36b188bfd24df99fae9734f515e1aa05c060301ffa6a02961ec8c92e10cf7a78754f2060d8f5e09c5e9db66db480690e1213d189181a9a822f7a9dd9df611f5cfa5fe8560c92c6dd11d6dd2219a68fb1f383d067cf6c0241556768f7c35db38419aef8979b267c126cfb560b3324c797ad345332ea55e38b7c02c6bb124ee1');
check('rsa output is 128 bytes base64', strlen(base64_decode(Signer::rsaEncrypt('x'))) === 128);

$s = Signer::sign(['a' => 1], 5);
check('sign keeps params and adds timestamp', $s['a'] === 1 && $s['timestamp'] === 5 && isset($s['sign']));
check('json empty array is []', Signer::json(['x' => []]) === '{"x":[]}');
check('json unescaped unicode and slashes', Signer::json(['u' => "é/"]) === '{"u":"é/"}');

done();
