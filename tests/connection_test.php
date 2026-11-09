<?php

require __DIR__ . '/bootstrap.php';

// Minimal core stubs so the namespaced class can load without GLPI.
class CommonDBTM {}
class CommonGLPI {}

require BI_ROOT . '/src/Source/Registry.php';
require BI_ROOT . '/src/Connection.php';

use GlpiPlugin\Backupinfo\Connection;
use GlpiPlugin\Backupinfo\Source\Registry;

$enc = fn(string $s): string => 'ENC(' . $s . ')';

// Empty secret keeps current.
$r = Connection::prepareSecrets(['name' => 'x', 'api_key' => '', 'password' => ''], ['api_key' => 'ENC(old)', 'password' => 'ENC(oldpw)'], $enc);
check('empty api_key keeps current', ($r['api_key'] ?? null) === 'ENC(old)');
check('empty password keeps current', ($r['password'] ?? null) === 'ENC(oldpw)');
check('other fields untouched', ($r['name'] ?? null) === 'x');

$r = Connection::prepareSecrets(['name' => 'x'], ['api_key' => 'ENC(old)'], $enc);
check('absent secret keeps current', ($r['api_key'] ?? null) === 'ENC(old)');

// New secret goes through the encryptor.
$r = Connection::prepareSecrets(['api_key' => 'abc', 'password' => 'pw'], ['api_key' => 'ENC(old)'], $enc);
check('new api_key encrypted', $r['api_key'] === 'ENC(abc)');
check('new password encrypted', $r['password'] === 'ENC(pw)');

// Clear flag empties, even if a value was typed.
$r = Connection::prepareSecrets(['api_key' => '', '_clear_api_key' => '1', 'password' => 'zz', '_clear_password' => '1'],
    ['api_key' => 'ENC(old)', 'password' => 'ENC(p)'], $enc);
check('clear api_key empties', $r['api_key'] === '');
check('clear password empties', $r['password'] === '');
check('clear flags removed from output', !array_key_exists('_clear_api_key', $r) && !array_key_exists('_clear_password', $r));

// Add: no current.
$r = Connection::prepareSecrets(['api_key' => ''], [], $enc);
check('add with empty secret stores empty', ($r['api_key'] ?? '') === '');

// Registry.
check('types() has vinchin and veeam', Registry::types() === ['vinchin' => 'Vinchin', 'veeam' => 'Veeam B&R 13 (preview, not verified on a server)']);

// Source-string checks on the GLPI-dependent parts.
$src = (string) file_get_contents(BI_ROOT . '/src/Connection.php');
check('uses GLPIKey import', str_contains($src, 'use GLPIKey;'));
check('add calls prepareSecrets with GLPIKey encrypt', (bool) preg_match('/function prepareInputForAdd.*?prepareSecrets\(.*?GLPIKey\(\)\)->encrypt/s', $src));
check('update calls prepareSecrets with GLPIKey encrypt', (bool) preg_match('/function prepareInputForUpdate.*?prepareSecrets\(.*?GLPIKey\(\)\)->encrypt/s', $src));
check('form never reads stored secrets', !preg_match('/\$this->fields\[\'(api_key|password)\'\]\s*(?!\s*(===|!==|==|!=|\?\?))\s*[,)\.]/', $src)
    && !preg_match('/(echo|Html::input|value)[^;]*\$this->fields\[\'(api_key|password)\'\]/', $src));
check('secret inputs are type password', (bool) preg_match("/'type'\s*=>\s*'password'/", $src)
    && str_contains($src, "secretInput('api_key'") && str_contains($src, "secretInput('password'"));
check('clear checkboxes present', str_contains($src, "name='_clear_\" . \$field") && str_contains($src, "type='checkbox'"));
check('type select from Registry::types()', str_contains($src, 'Registry::types()'));
check('auth_mode choices', str_contains($src, "'api_key'") && str_contains($src, "'login'"));
check('verify_tls field', str_contains($src, 'verify_tls'));
check('api_version field on the form', str_contains($src, 'data-bi-field="api_version"'));
check('form reads per-type fields from Registry', str_contains($src, 'Registry::formFields('));
check('new records take type from DEFAULT_TYPE', str_contains($src, 'Registry::DEFAULT_TYPE'));
check('no hardcoded type default', !str_contains($src, "?? 'vinchin'"));
check('no legacy fields in form', !preg_match("/api_secret|'token'|'site'|api_url/", $src));
check('secret() decrypts via GLPIKey', (bool) preg_match('/function secret\(.*?->decrypt\(/s', $src));
check('global classes imported', str_contains($src, 'use Html;') && str_contains($src, 'use Dropdown;') && str_contains($src, 'use CommonDBTM;'));

$setup = (string) file_get_contents(BI_ROOT . '/setup.php');
check('setup secures api_key and password', str_contains($setup, 'connections.api_key') && str_contains($setup, 'connections.password'));
check('setup no longer secures api_secret/token', !str_contains($setup, 'connections.api_secret') && !str_contains($setup, 'connections.token'));

check('secrets excluded from history', (new GlpiPlugin\Backupinfo\Connection())->getNonLoggedFields() === ['api_key', 'password']);

done();
