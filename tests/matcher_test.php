<?php

require __DIR__ . '/bootstrap.php';
require BI_ROOT . '/src/Matching/AssetLookup.php';
require BI_ROOT . '/src/Matching/Matcher.php';
require BI_ROOT . '/src/Matching/DbAssetLookup.php';

use GlpiPlugin\Backupinfo\Matching\AssetLookup;
use GlpiPlugin\Backupinfo\Matching\DbAssetLookup;
use GlpiPlugin\Backupinfo\Matching\Matcher;

// Fake honouring the AssetLookup contract: names compare case-insensitively
// (DbAssetLookup relies on the DB's ci collation for the same behaviour).
final class FakeLookup implements AssetLookup
{
    public array $calls = [];
    private array $names;
    public function __construct(array $names, private array $ips)
    {
        $this->names = array_change_key_case($names, CASE_LOWER);
    }
    public function byName(string $name): array { $this->calls[] = "name:$name"; return $this->names[strtolower($name)] ?? []; }
    public function byIp(string $ip): array { $this->calls[] = "ip:$ip"; return $this->ips[$ip] ?? []; }
}

$vm  = ['itemtype' => 'Computer', 'items_id' => 1566];
$vm2 = ['itemtype' => 'Computer', 'items_id' => 1600];

$lookup = new FakeLookup(
    ['VM-TEST-01' => [$vm], 'dup-name' => [$vm, $vm2], 'renamed-new' => []],
    ['10.0.2.9' => [$vm, $vm2], '10.0.3.3' => [$vm], '10.0.4.4' => [$vm, $vm]]
);
$m = new Matcher($lookup);

$r = $m->match('VM-TEST-01', '10.0.2.9', 'hybrid_name');
check('name hit wins over ambiguous IP', $r === ['state' => 'matched', 'itemtype' => 'Computer', 'items_id' => 1566, 'source' => 'name']);

$r = $m->match('vm-test-01', '', 'hybrid_name');
check('name match is case-insensitive', $r['state'] === 'matched' && $r['items_id'] === 1566);

$r = $m->match('renamed-new', '10.0.3.3', 'hybrid_name');
check('no name hit falls back to unique IP', $r['state'] === 'matched' && $r['items_id'] === 1566 && $r['source'] === 'ip');

$r = $m->match('renamed-new', '10.0.2.9', 'hybrid_name');
check('ambiguous IP never guesses', $r === ['state' => 'review', 'itemtype' => '', 'items_id' => 0, 'source' => '']);

$r = $m->match('dup-name', '10.0.3.3', 'hybrid_name');
check('two Computers with the name and a unique IP: IP settles it', $r['state'] === 'matched' && $r['source'] === 'ip');

$r = $m->match('dup-name', '', 'hybrid_name');
check('two Computers with the name go to review', $r['state'] === 'review');

$r = $m->match('nothing', '10.9.9.9', 'hybrid_name');
check('no hit anywhere is unmatched', $r['state'] === 'unmatched');

$lookup->calls = [];
$r = $m->match('x', 'x', 'manual');
check('manual mode never looks up', $r['state'] === 'unmatched' && $lookup->calls === []);

$lookup->calls = [];
$m->match('   ', 'not-an-ip', 'hybrid_name');
check('blank name and invalid IP are not looked up', $lookup->calls === []);

$r = $m->match('x', '10.0.4.4', 'ip_only');
check('duplicate rows of one asset count as one', $r['state'] === 'matched' && $r['items_id'] === 1566);

$r = $m->match('VM-TEST-01', '', 'bogus_mode');
check('unknown mode never guesses', $r['state'] === 'unmatched');

// DbAssetLookup::ipHits (pure): Computer is the only itemtype.
$prn = ['itemtype' => 'Printer', 'items_id' => 9];
check('IP shared with a non-Computer stays ambiguous', count(DbAssetLookup::ipHits([$vm, $prn])) === 2);
check('single Computer owner is a hit', DbAssetLookup::ipHits([$vm]) === [$vm]);
check('single non-Computer owner is no hit', DbAssetLookup::ipHits([$prn]) === []);
check('same owner twice counts once', DbAssetLookup::ipHits([$vm, $vm]) === [$vm]);

done();
