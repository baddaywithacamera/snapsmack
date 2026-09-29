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
$layout   = file_get_contents($root . '/skins/telegram/layout.php');
$style    = file_get_contents($root . '/skins/telegram/style.css');
$tilez    = file_get_contents($root . '/core/public-repository.php');
$tilezCss = file_get_contents($root . '/skins/tilez/style.css');
$tilezHeader = file_get_contents($root . '/skins/tilez/skin-header.php');
$manifest = json_decode(file_get_contents($root . '/skins/telegram/manifest.json'), true);
$installer = file_get_contents($root . '/projects/snapsmack-ca/install-manifest.php');
$updater   = file_get_contents($root . '/core/updater.php');
$updateUi  = file_get_contents($root . '/smack-update.php');
$meta      = file_get_contents($root . '/core/meta.php');

tg_check(strpos($index, "=== 'smacktalk'") !== false
    && strpos($index, "'/skins/telegram'") !== false,
    'SMACKTALK selects TELEGRAM when it is installed');
tg_check(strpos($index, '$_snapsmack_mobile_skin = SNAPSMACK_MOBILE_SKIN') !== false,
    'mobile routing retains the PHOTOGRAM fallback');
tg_check(strpos($installer, "'smacktalk'            => 'telegram'") !== false
    && strpos($installer, "'photoblog', 'carousel' => 'photogram'") !== false
    && strpos($installer, "default                => ''") !== false,
    'fresh installs map TELEGRAM and PHOTOGRAM only to their blog modes');
tg_check(strpos($updater, "'smacktalk'             => 'telegram'") !== false
    && strpos($updater, "'photoblog', 'carousel' => 'photogram'") !== false
    && strpos($updateUi, "'smacktalk'             => 'telegram'") !== false,
    'existing blog installs self-repair the correct mobile renderer');
tg_check(strpos($meta, "'telegram'") !== false
    && strpos($meta, 'snapsmack_mobile_css_target_stamp($_mobile_render_slug)') !== false,
    'TELEGRAM receives mobile skin option CSS');
tg_check(($manifest['features']['mobile_only'] ?? false) === true
    && ($manifest['features']['post_modes'] ?? []) === ['longform'],
    'TELEGRAM declares mobile-only longform support');
tg_check(in_array('smack-lightbox', $manifest['require_scripts'] ?? [], true),
    'TELEGRAM loads the shared tap-to-enlarge lightbox');
tg_check(($manifest['schema_version'] ?? 0) === 2
    && ($manifest['security_policy'] ?? 0) === 2
    && ($manifest['cms_controller'] ?? '') === 'smacktalk'
    && ($manifest['view_model'] ?? '') === 'snapsmack.public.v1'
    && !is_file($root . '/skins/telegram/preload.php'),
    'TELEGRAM uses the strict CMS controller and has no legacy preload');
tg_check(strpos($layout, "snap_render_html(\$view['response']['rendered_content'])") !== false
    && in_array('smack-rows', $manifest['require_scripts'] ?? [], true),
    'TELEGRAM renders CMS-sanitized rich content and shared mosaic engines');
tg_check(strpos($layout, "snap_render_component('comments'") !== false
    && strpos($index, "=== 'not_found'") !== false,
    'TELEGRAM renders moderated CMS comments and the CMS owns error status');
tg_check(strpos($layout, "snap_render_component('navigation'") !== false
    && preg_match('/position\s*:\s*fixed/', $style) === 1,
    'TELEGRAM exposes CMS-owned navigation with its fixed mobile presentation');
tg_check(strpos($style, '--telegram-column:46rem') !== false
    && strpos($style, '.snap-mosaic') !== false,
    'TELEGRAM constrains essays and mosaics to its reading column');
tg_check(strpos($tilez, 'ORDER BY p.created_at DESC, p.id DESC') !== false,
    'TILEZ orders imported posts by publication date');
tg_check(strpos($tilezHeader, '<ul class="main-menu">') !== false
    && strpos($tilezHeader, 'class="tilez-icon-nav"') !== false
    && strpos($tilezCss, '.navigation .main-menu,.tilez-icon-nav { display:none !important; }') === false
    && strpos($tilezCss, 'font-synthesis: none') !== false
    && strpos($tilezCss, '.snap-inline-frame:has(+ .snap-inline-frame)') !== false,
    'TILEZ preserves its menu, adds round quick links, and keeps the readable post body');

echo "TELEGRAM/TILEZ regression checks passed.\n";

// ===== SNAPSMACK EOF =====
