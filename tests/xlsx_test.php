<?php

require __DIR__ . '/bootstrap.php';

require BI_ROOT . '/src/Xlsx.php';
require BI_ROOT . '/src/AuditPage.php';

use GlpiPlugin\Backupinfo\AuditPage;
use GlpiPlugin\Backupinfo\Xlsx;

$read = static fn (string $rel): string => (string) @file_get_contents(BI_ROOT . '/' . $rel);

// --- helpers
check('column letters', Xlsx::col(0) === 'A' && Xlsx::col(25) === 'Z' && Xlsx::col(26) === 'AA' && Xlsx::col(701) === 'ZZ' && Xlsx::col(702) === 'AAA');
check('sheet name: forbidden chars and 31 max', Xlsx::sheetName('7. Changes, access and alarms / [x]*?:\\', []) === '7. Changes, access and alarms _'
    && mb_strlen(Xlsx::sheetName(str_repeat('a', 40), [])) === 31);
check('sheet name: unique', Xlsx::sheetName('Info', ['Info']) === 'Info (2)' && Xlsx::sheetName(str_repeat('b', 31), [str_repeat('b', 31)]) === str_repeat('b', 27) . ' (2)');
check('sheet name: blank -> Sheet', Xlsx::sheetName('  ', []) === 'Sheet');

// --- workbook parts
$sheet = Xlsx::sheet('Failures', ['Report' => 'r', 'Period' => '2026-10'], ['A', 'B', 'C'], [
    ['=1+1', 5, null],
    ["a\x01b<&>\"", -2.5, 'é ü'],
]);
$parts = Xlsx::parts([$sheet]);
foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $p) {
    check("part $p present", isset($parts[$p]));
}
foreach ($parts as $name => $xml) {
    $doc = new DOMDocument();
    check("part $name is well-formed XML", @$doc->loadXML($xml) === true);
}
$ws = $parts['xl/worksheets/sheet1.xml'];
check('formula text stays a string, never a formula', str_contains($ws, '<t xml:space="preserve">=1+1</t>') && !str_contains($ws, '<f>'));
check('numbers stay numbers', str_contains($ws, '<c r="B5"><v>5</v></c>') && str_contains($ws, '<c r="B6"><v>-2.5</v></c>'));
check('control chars dropped, entities escaped', str_contains($ws, 'ab&lt;&amp;&gt;"'));
check('null cell left out', !str_contains($ws, 'r="C5"'));
check('header row bold (style 1) after meta and a blank row', str_contains($ws, '<c r="A4" s="1" t="inlineStr"><is><t xml:space="preserve">A</t></is></c>'));
check('meta labels bold', str_contains($ws, '<c r="A1" s="1" t="inlineStr">'));
check('pane frozen below the header', str_contains($ws, '<pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/>'));
check('autofilter over the table', str_contains($ws, '<autoFilter ref="A4:C6"/>'));
check('workbook lists the sheet', str_contains($parts['xl/workbook.xml'], '<sheet name="Failures" sheetId="1" r:id="rId1"/>'));
check('empty table: header only, filter on header', str_contains(Xlsx::parts([Xlsx::sheet('E', [], ['X'], [])])['xl/worksheets/sheet1.xml'], '<autoFilter ref="A1:A1"/>'));

$two = Xlsx::parts([Xlsx::sheet('Info', [], ['A'], []), Xlsx::sheet('Info', [], ['A'], [])]);
check('two sheets, unique names', isset($two['xl/worksheets/sheet2.xml']) && str_contains($two['xl/workbook.xml'], 'name="Info (2)"'));
check('content types list each sheet', substr_count($two['[Content_Types].xml'], 'spreadsheetml.worksheet+xml') === 2);

// --- packaging (needs ZipStream from GLPI's vendor, or ext-zip)
// Only ZipStream's own classes, from the local GLPI copy (GLPI's full autoload defines core constants).
$zipSrc = 'C:/Users/<user>/Downloads/glpi/vendor/maennchen/zipstream-php/src/';
spl_autoload_register(static function (string $class) use ($zipSrc): void {
    if (str_starts_with($class, 'ZipStream\\') && is_file($f = $zipSrc . str_replace('\\', '/', substr($class, 10)) . '.php')) {
        require $f;
    }
});
$tmp = sys_get_temp_dir() . '/bix-' . getmypid() . '.xlsx';
$fh  = fopen($tmp, 'wb');
$ok  = Xlsx::write($fh, $parts);
fclose($fh);
if (!$ok) {
    check('zip writer available (ZipStream or ext-zip)', false, 'neither found: packaging untested');
} else {
    $bin = (string) file_get_contents($tmp);
    check('output is a zip', str_starts_with($bin, "PK\x03\x04"));
    putenv('PYTHONIOENCODING=utf-8');
    putenv('OPENBLAS_NUM_THREADS=1'); // openpyxl pulls numpy, whose BLAS cannot start from here otherwise
    $py = trim((string) shell_exec('python -c "import openpyxl,sys; wb=openpyxl.load_workbook(sys.argv[1]); ws=wb[\'Failures\']; print(ws[\'A5\'].value, ws[\'A5\'].data_type, ws[\'B5\'].value, ws[\'C6\'].value, ws.freeze_panes, ws.auto_filter.ref)" ' . escapeshellarg($tmp) . ' 2>&1'));
    if ($py === '' || str_contains($py, 'No module') || str_contains($py, 'not found')) {
        echo "SKIP openpyxl read-back ($py)\n";
    } else {
        check('Excel reader opens it: string formula text, number, unicode, pane, filter', $py === '=1+1 s 5 é ü A5 A4:C6', $py);
    }
}
@unlink($tmp);

// --- scope note: why a page is empty
check('scope note: nothing marked', str_contains((string) AuditPage::scopeNote(0, 0), 'No Computer is marked'));
check('scope note: marked but outside the active entities', str_contains((string) AuditPage::scopeNote(42, 0), '42 Computers are marked') && str_contains((string) AuditPage::scopeNote(42, 0), 'entit'));
check('scope note: none when some are visible', AuditPage::scopeNote(42, 5) === null);

// --- page wiring
$page = $read('front/audit.php');
check('page: XLSX export before Html::header', ($e = strpos($page, 'Xlsx::write(')) !== false && $e < strpos($page, 'Html::header('));
check('page: XLSX falls back to CSV when no zip writer', str_contains($page, 'Csv::render('));
check('page: export of every section in one workbook', str_contains($page, "'all'"));
check('page: sections load one by one after the page', str_contains($page, "\$_GET['section']") && str_contains($page, 'fetch('));
check('page: fragment exits before Html::header', ($f = strpos($page, "\$_GET['section']")) !== false && $f < strpos($page, 'Html::header('));
check('page: scope note shown', str_contains($page, 'AuditPage::scopeNote('));
$data = $read('src/AuditData.php');
check('data: no live read on the page (snapshots instead)', !str_contains($data, 'AuditCache') && str_contains($data, 'DbSnapshotStore'));
check('data: no live read for coverage or copies when nothing is in scope', str_contains($data, '$this->computers === [] ? [] :'));
check('data: total before the entity filter kept', str_contains($data, 'public readonly int $mandatoryTotal;'));

done();
