<?php
/**
 * Regression checks for the TELEGRAM mobile longform skin and TILEZ menu.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

function tg_check(bool $ok, string $label): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

$root     = dirname(__DIR__);
$index    = file_get_contents($root . '/index.php');
$preload  = file_get_contents($root . '/skins/telegram/preload.php');
$header   = file_get_contents($root . '/skins/telegram/skin-header.php');
$style    = file_get_contents($root . '/skins/telegram/style.css');
$tilez    = file_get_contents($root . '/skins/tilez/preload.php');
$tilezCss = file_get_contents($root . '/skins/tilez/style.css');
$manifest = json_decode(file_get_contents($root . '/skins/telegram/manifest.json'), true);
$installer = file_get_contents($root . '/projects/snapsmack-ca/install-manifest.php');
$updater   = file_get_contents($root . '/core/updater.php');
$meta      = file_get_contents($root . '/core/meta.php');

tg_check(strpos($index, "=== 'smacktalk'") !== false
    && strpos($index, "'/skins/telegram'") !== false,
    'SMACKTALK selects TELEGRAM when it is installed');
tg_check(strpos($index, '$_snapsmack_mobile_skin = SNAPSMACK_MOBILE_SKIN') !== false,
    'mobile routing retains the PHOTOGRAM fallback');
tg_check(strpos($installer, "(\$mode === 'smacktalk') ? 'telegram' : 'photogram'") !== false,
    'fresh installs receive the mobile skin for their content mode');
tg_check(strpos($updater, "(\$site_mode === 'smacktalk')") !== false
    && strpos($updater, "? 'telegram'") !== false,
    'existing SMACKTALK installs self-repair TELEGRAM');
tg_check(strpos($meta, "'telegram'") !== false
    && strpos($meta, 'snapsmack_mobile_css_target_stamp($_mobile_render_slug)') !== false,
    'TELEGRAM receives mobile skin option CSS');
tg_check(($manifest['features']['mobile_only'] ?? false) === true
    && ($manifest['features']['post_modes'] ?? []) === ['longform'],
    'TELEGRAM declares mobile-only longform support');
tg_check(in_array('smack-lightbox', $manifest['require_scripts'] ?? [], true),
    'TELEGRAM loads the shared tap-to-enlarge lightbox');
tg_check(strpos($preload, "!== 'telegram'") !== false
    && strpos($preload, 'ss-engine-mosaic.js') !== false,
    'TELEGRAM claims its route and renders the shared mosaic engine');
tg_check(strpos($header, 'nav-toggle-label">MENU') !== false
    && preg_match('/position\s*:\s*fixed/', $style) === 1,
    'TELEGRAM exposes the labelled fixed top-right menu');
tg_check(strpos($style, '--telegram-column:46rem') !== false
    && strpos($style, '.snap-mosaic') !== false,
    'TELEGRAM constrains essays and mosaics to its reading column');
tg_check(strpos($tilez, 'ORDER BY p.created_at DESC, p.id DESC') !== false,
    'TILEZ orders imported posts by publication date');
tg_check(strpos($tilezCss, 'nav-toggle-label') !== false
    && preg_match('/font-size\s*:\s*21px/', $tilezCss) === 1,
    'TILEZ includes the enlarged menu and readable post body');

echo "TELEGRAM/TILEZ regression checks passed.\n";

// ===== SNAPSMACK EOF =====
