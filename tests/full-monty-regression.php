<?php

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$root = dirname(__DIR__);
function fm_ok(bool $ok, string $why): void { if (!$ok) { fwrite(STDERR, "FAIL: {$why}\n"); exit(1); } }
$manifest = json_decode((string)file_get_contents($root . '/skins/full-monty/manifest.json'), true);
fm_ok(is_array($manifest), 'manifest parses');
fm_ok(($manifest['name'] ?? '') === 'FULL MONTY', 'manifest identity');
fm_ok(in_array('smack-full-monty', $manifest['require_scripts'] ?? [], true), 'shared engine requested');
$inventory = include $root . '/core/manifest-inventory.php';
fm_ok(isset($inventory['scripts']['smack-full-monty']), 'engine registered');
fm_ok(is_file($root . '/' . $inventory['scripts']['smack-full-monty']['path']), 'engine file exists');
foreach (['layout.php','archive-layout.php','skin-header.php','skin-footer.php','skin-meta.php','style.css','help.php'] as $file) fm_ok(is_file($root . '/skins/full-monty/' . $file), "{$file} exists");
$layout = (string)file_get_contents($root . '/skins/full-monty/layout.php');
fm_ok(strpos($layout, 'archive.php') !== false, 'solo photograph opens archive');
fm_ok(strpos($layout, 'data-fm-stage') !== false, 'solo exposes engine carrier');
$archive = (string)file_get_contents($root . '/skins/full-monty/archive-layout.php');
fm_ok(strpos($archive, 'fm-diagonal') !== false && strpos($archive, 'img_thumb_square') !== false, 'square diagonal archive');
echo "PASS: FULL MONTY regression suite\n";
// ===== SNAPSMACK EOF =====
