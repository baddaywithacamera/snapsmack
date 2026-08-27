<?php
$root = dirname(__DIR__);
$manifest_raw = file_get_contents($root . '/skins/onyx/manifest.json');
$manifest = json_decode($manifest_raw, true);
$landing = file_get_contents($root . '/skins/onyx/landing.php');
$css = file_get_contents($root . '/skins/onyx/style.css');
$fail = [];
$expect = function (bool $ok, string $message) use (&$fail): void { if (!$ok) $fail[] = $message; };
$expect(is_array($manifest), 'ONYX manifest must be valid JSON');
$expect(($manifest['features']['has_landing'] ?? false) === true, 'ONYX must enable its landing wall');
$expect(in_array('smack-columns', $manifest['require_scripts'] ?? [], true), 'ONYX must load the shared columns engine');
$expect(isset($manifest['options']['onyx_wall_columns'], $manifest['options']['onyx_wall_width'], $manifest['options']['onyx_wall_gap']), 'ONYX must expose masonry display controls');
$expect(str_contains($landing, 'class="ss-masonry onyx-photo-wall"'), 'ONYX landing must use the shared masonry hook');
$expect(str_contains($landing, "img_status='published' AND img_date<=:cutoff"), 'ONYX landing must exclude drafts and scheduled images');
$expect(str_contains($landing, "PDO::PARAM_INT"), 'ONYX paging limits must be integer-bound');
$expect(str_contains($css, '--onyx-wall-width'), 'ONYX must style its wall width');
if ($fail) { fwrite(STDERR, implode(PHP_EOL, $fail) . PHP_EOL); exit(1); }
echo "onyx masonry regression: ok\n";

