<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/** Regression guards for PHOTOGRAM mobile selection and post zoom. */

$root = dirname(__DIR__);
require_once $root . '/core/constants.php';

$oldServer = $_SERVER;

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 16; Pixel 9) AppleWebKit/537.36 Chrome/140 Mobile Safari/537.36';
$_SERVER['HTTP_SEC_CH_UA_MOBILE'] = '?1';
if (!snapsmack_is_mobile()) {
    fwrite(STDERR, "A Chromium phone request must select the mobile skin\n");
    exit(1);
}

$_SERVER['HTTP_SEC_CH_UA_MOBILE'] = '?0';
if (snapsmack_is_mobile()) {
    fwrite(STDERR, "Chrome Desktop site must escape the mobile skin\n");
    exit(1);
}

$_SERVER = $oldServer;

$index = file_get_contents($root . '/index.php');
$js = file_get_contents($root . '/assets/js/ss-engine-photogram.js');
$layout = file_get_contents($root . '/skins/photogram/layout.php');

if (strpos($index, '&& !$_snapsmack_desktop_hint') === false) {
    fwrite(STDERR, "A remembered PWA cookie can still override Desktop site\n");
    exit(1);
}
if (strpos($js, "querySelectorAll('[data-pg-lightbox]')") === false
    || substr_count($layout, 'data-pg-lightbox') < 2) {
    fwrite(STDERR, "Single and carousel post photographs must share the full-screen viewer\n");
    exit(1);
}

echo "Photogram mobile selection regression checks passed.\n";

// ===== SNAPSMACK EOF =====
