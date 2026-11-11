<?php

require __DIR__ . '/bootstrap.php';
require_once BI_ROOT . '/src/Manual.php';

use GlpiPlugin\Backupinfo\Manual;

// 0.5.0-rc1, suite rule 2026-11-09 (hub §4 "links its user manual"); reference opmanager v1.3.0-rc4.
check('manual path is docs/manual.md', str_ends_with(str_replace('\\', '/', Manual::path()), '/docs/manual.md'));
check('commonmark: raw HTML escaped, unsafe links dropped', Manual::CONVERTER_OPTIONS === ['html_input' => 'escape', 'allow_unsafe_links' => false]);

check('version from the Indonesian header', Manual::versionOf("# x\n\nVersi didokumentasikan: 0.5.0\n") === '0.5.0');
check('version from the English header', Manual::versionOf("Version documented: v0.5\n") === '0.5');
check('no header, no version', Manual::versionOf("# Manual\n") === '');
// Hub §4 lesson: Copilot writes the version in bold.
check('Copilot-style bold version', Manual::versionOf("> Versi didokumentasikan: **0.5.0** · Disusun: 2026-11-10\n") === '0.5.0');
check('italic / code version', Manual::versionOf("Version documented: _0.5_\n") === '0.5' && Manual::versionOf("Versi didokumentasikan: `v0.5.0`\n") === '0.5.0');
check('bold label and value', Manual::versionOf("**Versi didokumentasikan:** **0.5.0**\n") === '0.5.0');

// Lag by major.minor only.
check('patch release does not lag', !Manual::lags('0.5.0', '0.5.1'));
check('minor release lags', Manual::lags('0.4.0', '0.5.0-rc1'));
check('major release lags', Manual::lags('0.9', '1.0.0'));
check('rc suffix ignored', !Manual::lags('0.5.0', '0.5.0-rc1'));
check('same version does not lag', !Manual::lags('0.5.0', '0.5.0'));
check('unknown manual version never lags', !Manual::lags('', '0.5.0'));

$missing = Manual::body(null, '0.5.0-rc1');
check('missing manual: info notice', str_contains($missing, 'alert-info') && str_contains($missing, 'The user manual is not installed with this copy of the plugin.'));
$lag = Manual::body("Versi didokumentasikan: 0.4.0\n\n# Hi", '0.5.0-rc1');
check('lagging manual: warning names both versions', str_contains($lag, 'alert-warning') && str_contains($lag, '0.4.0') && str_contains($lag, '0.5.0-rc1'));
$cur = Manual::body("Versi didokumentasikan: **0.5.0**\n\n# Hi", '0.5.0-rc1');
check('current manual: version line, no warning', !str_contains($cur, 'alert-warning') && str_contains($cur, 'Manual for version 0.5.0'));

// Offline there is no league/commonmark: the fallback must still escape.
$x = Manual::render("<script>alert(1)</script>");
check('fallback render escapes HTML', !str_contains($x, '<script>') && str_contains($x, '&lt;script&gt;'));

$page = (string) file_get_contents(BI_ROOT . '/front/manual.php');
check('page needs the view right', str_contains($page, 'Session::checkRight(RightDefinition::VIEW, READ);'));
check('page in the shared sector with its sub-nav', str_contains($page, "\\GlpiPlugin\\Pgnsuite\\Menu::SECTOR, 'backupinfo', 'manual');")
    && str_contains($page, "\\GlpiPlugin\\Pgnsuite\\Menu::renderSubNav('backupinfo', 'manual');"));
check('page renders Manual::body with the plugin version', str_contains($page, 'Manual::body(Manual::read(), PLUGIN_BACKUPINFO_VERSION)'));

$menu = (string) file_get_contents(BI_ROOT . '/src/Menu.php');
check('menu option manual with ti ti-book', (bool) preg_match("/\['manual'\]\s*=\s*\[[^\]]*'icon'\s*=> 'ti ti-book'/s", $menu) && str_contains($menu, "'/plugins/backupinfo/front/manual.php'"));
$setup = (string) file_get_contents(BI_ROOT . '/src/SuiteConfig.php');
check('Setup links the manual', str_contains($setup, '/plugins/backupinfo/front/manual.php') && str_contains($setup, 'ti ti-book'));

$build = (string) file_get_contents(BI_ROOT . '/tools/build_zip.py');
check('build copies the suite manual to docs/manual.md', str_contains($build, "'docs', 'manual', 'backupinfo.md'") && str_contains($build, "MANUAL = 'docs/manual.md'"));
check('build goes on without the manual', str_contains($build, 'building without it'));
check('build ships the manual with LF (byte-equal to the committed blob)', str_contains($build, "replace(b'\\r\\n', b'\\n')"));
check('build copy is not committed', str_contains((string) file_get_contents(BI_ROOT . '/.gitignore'), '/docs/manual.md'));

done();
