<?php

require __DIR__ . '/bootstrap.php';

// Goal 1: a new brand means three things and nothing else in the plugin's core: a folder src/<Brand>/,
// one line in Source\Registry, and the brand's own tests. Core (sync, stores, pages, tickets, findings,
// the Excel export) must not reference either brand namespace.
$offending = [];
$roots     = [BI_ROOT . '/src', BI_ROOT . '/front', BI_ROOT . '/hook.php', BI_ROOT . '/setup.php', BI_ROOT . '/install'];
$exclude   = [BI_ROOT . '/src/Vinchin/', BI_ROOT . '/src/Veeam/', BI_ROOT . '/src/Source/Registry.php'];

foreach ($roots as $root) {
    if (is_file($root)) {
        $files = [$root];
    } elseif (is_dir($root)) {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS));
        $files = [];
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    } else {
        continue;
    }
    foreach ($files as $file) {
        $norm = str_replace('\\', '/', $file);
        $skip = false;
        foreach ($exclude as $ex) {
            $nex = str_replace('\\', '/', $ex);
            if (str_starts_with($norm, $nex) || $norm === $nex) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }
        $content = (string) @file_get_contents($file);
        if ($content !== '' && preg_match('/GlpiPlugin\\\\Backupinfo\\\\(Vinchin|Veeam)\\\\/', $content)) {
            $offending[] = $file;
        }
    }
}

sort($offending);
check('core is brand-neutral (no Vinchin/Veeam namespace outside src/<Brand> and Registry)', $offending === [], implode("\n", $offending));

done();
