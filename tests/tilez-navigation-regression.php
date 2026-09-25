<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$root = dirname(__DIR__);
$header = file_get_contents($root . '/skins/tilez/skin-header.php');
$css = file_get_contents($root . '/skins/tilez/style.css');
$manifest = json_decode(file_get_contents($root . '/skins/tilez/manifest.json'), true);

foreach (['HOME', 'THE IDEA', 'DIARY', 'CATEGORIES', 'ALBUMS', 'IMAGES'] as $label) {
    if (strpos($header, "'label' => '" . $label . "'") === false) {
        fwrite(STDERR, "Missing TILEZ navigation item: {$label}\n");
        exit(1);
    }
}
if (strpos($header, 'class="tilez-icon-nav"') === false || substr_count($header, 'aria-label=') < 7) {
    fwrite(STDERR, "TILEZ quick icon navigation is incomplete\n");
    exit(1);
}
if (!preg_match('/\.main-menu > li > a[^}]+font-size:\s*20px/s', $css)) {
    fwrite(STDERR, "TILEZ text navigation is not doubled to 20px\n");
    exit(1);
}
if (($manifest['version'] ?? '') !== '0.2.8') {
    fwrite(STDERR, "TILEZ manifest must be version 0.2.8\n");
    exit(1);
}

echo "TILEZ navigation regression checks passed.\n";

// ===== SNAPSMACK EOF =====
