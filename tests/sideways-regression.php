<?php
$root = dirname(__DIR__);
function sw_ok(bool $ok, string $why): void { if (!$ok) { fwrite(STDERR, "FAIL: {$why}\n"); exit(1); } }
$manifest = json_decode((string)file_get_contents($root . '/skins/sideways/manifest.json'), true);
sw_ok(is_array($manifest), 'manifest parses');
sw_ok(($manifest['name'] ?? '') === 'SIDEWAYS', 'manifest identity');
sw_ok(($manifest['modes'] ?? []) === ['photoblog'], 'SMACKONEOUT only');
sw_ok(($manifest['minimum_published_images'] ?? 0) === 200, '200 photograph activation floor');
sw_ok(($manifest['features']['post_modes'] ?? []) === ['image'], 'solo image posts only');
sw_ok(empty($manifest['features']['carousel']), 'not a carousel skin');
sw_ok(in_array('smack-sideways', $manifest['require_scripts'] ?? [], true), 'shared engine requested');
$inventory = include $root . '/core/manifest-inventory.php';
sw_ok(isset($inventory['scripts']['smack-sideways']), 'engine registered');
sw_ok(is_file($root . '/' . $inventory['scripts']['smack-sideways']['path']), 'engine file exists');
foreach (['landing.php','layout.php','skin-header.php','skin-footer.php','skin-meta.php','skin-page.php','style.css'] as $file) sw_ok(is_file($root . '/skins/sideways/' . $file), "{$file} exists");
$landing = (string)file_get_contents($root . '/skins/sideways/landing.php');
sw_ok(strpos($landing, 'array_fill(0, 4') !== false, 'exactly four rows');
sw_ok(strpos($landing, 'array_merge($row, $row, $row, $row, $row)') !== false, 'five seamless track copies');
$css = (string)file_get_contents($root . '/skins/sideways/style.css');
sw_ok(strpos($css, 'aspect-ratio:3/2') !== false, 'fixed 3:2 plates');
sw_ok(strpos($css, 'object-fit:contain') !== false, 'photographs are not cropped');
$js = (string)file_get_contents($root . '/assets/js/ss-engine-sideways.js');
sw_ok(strpos($js, 'var active = null') !== false, 'single active row gate');
sw_ok(strpos($js, "matches(':hover')") !== false, 'hover freezes candidate row');
sw_ok(strpos($js, 'Math.floor(Math.random() * 10)') !== false, 'one to ten plate travel');
echo "PASS: SIDEWAYS regression suite\n";
// ===== SNAPSMACK EOF =====
