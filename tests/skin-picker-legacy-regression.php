<?php
/**
 * Skin Manager legacy-skin regression (0.7.642D) — on an install whose skins
 * all predate manifest.json (the deployed fleet), the picker listed nothing,
 * array_key_first([]) fed load_skin_manifest(null), and the page 500'd —
 * locking the admin out of the one page that can update the skins
 * (foundtextures, 2026-09-05).
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$page = file_get_contents($root . '/smack-skin.php');
$mf   = file_get_contents($root . '/core/skin-manifest.php');

$fail = 0;
function sk_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

sk_test(substr_count($page, "(legacy — update it from the gallery)") >= 2,
    'legacy pre-manifest skins are listed in BOTH the main loop and the empty-list fallback');
sk_test(str_contains($page, '} elseif (!$is_carousel && !$is_smacktalk) {'),
    'legacy skins list only in photoblog mode (they predate carousel/smacktalk)');
sk_test(str_contains($page, '$manifest = $target_skin !== null ? load_skin_manifest($target_skin) : [];'),
    'a null target skin renders an empty manifest instead of fataling');
sk_test(str_contains($mf, 'function load_skin_manifest(?string $slug): array'),
    'load_skin_manifest tolerates null (returns empty manifest)');
sk_test(preg_match('/if \(\$slug === null\) \{\s*\n\s*return \[\];\s*\n\s*\}/s', $mf) === 1,
    'null slug short-circuits before the regex/cache path');

// Functional: the loader really returns [] for null and '' without throwing.
define('SNAPSMACK_MANIFEST_SCHEMA_VERSION_TEST_SHIM', 1);
require_once $root . '/core/skin-manifest.php';
try {
    sk_test(load_skin_manifest(null) === [], 'load_skin_manifest(null) returns []');
    sk_test(load_skin_manifest('') === [], "load_skin_manifest('') returns []");
    sk_test(load_skin_manifest('true-grit') !== [], 'a real manifest still loads');
} catch (Throwable $e) {
    sk_test(false, 'loader threw: ' . $e->getMessage());
}

echo $fail === 0 ? "ALL PASS\n" : ("{$fail} FAILURE(S)\n");
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
