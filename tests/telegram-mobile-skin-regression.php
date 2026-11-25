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

// SS-1495: this file read nothing but source text until now. The sanitized
// rich-content check below RENDERS the skin instead of grepping its layout, so
// it needs the same core the real request path loads.
define('SNAPSMACK_SKIN_RENDER', true);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';

$index    = file_get_contents($root . '/index.php');
$layout   = file_get_contents($root . '/skins/telegram/layout.php');
$style    = file_get_contents($root . '/skins/telegram/style.css');
$tilez    = file_get_contents($root . '/core/public-repository.php');
$tilezCss = file_get_contents($root . '/skins/tilez/style.css');
$tilezLayout = file_get_contents($root . '/skins/tilez/layout.php');
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
// ── SANITIZED RICH CONTENT, DRIVEN RATHER THAN GREPPED ──────────────────────
//
// This check used to be strpos($layout, "snap_render_html($view['response']
// ['rendered_content'])") -- the layout's exact source text. It failed the
// moment the layout gained a null-coalescing fallback (rendered_content ?? ''),
// which made the behaviour SAFER: a response with no rendered_content now
// falls back to an empty string instead of raising a warning. A check that
// reads source text called that improvement a regression, and it blocked an
// approved merge.
//
// So render the skin and ask the page. Both halves matter: the sanitized HTML
// must arrive, AND a response missing the key must not warn or crash. The
// first is what the old check was trying to say; the second is what the
// fallback is for, and nothing was holding it.
$tg_render = static function (array $response) use ($root): array {
    $view = snapsmack_build_skin_view($response, [
        'site_name' => 'Example', 'tagline' => 'T', 'language' => 'en',
        'direction' => 'ltr', 'skin_slug' => 'telegram',
        'skin_style_url' => '/skins/telegram/style.css',
        'skin_presentation' => snapsmack_skin_presentation([], 'telegram'),
    ]);
    $warnings = [];
    set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
        $warnings[] = $msg;
        return true;
    });
    ob_start();
    $ok = snapsmack_render_strict_skin_template($root . '/skins/telegram', 'layout.php', $view);
    $html = (string) ob_get_clean();
    restore_error_handler();
    return ['ok' => $ok, 'html' => $html, 'warnings' => $warnings];
};

$tg_marker = '<p>Sanitized paragraph.</p>';
$tg_page = $tg_render([
    'status' => 200, 'kind' => 'page', 'navigation' => [],
    'page_title' => 'About',
    'rendered_content' => snapsmack_trusted_html($tg_marker),
    'item' => ['id' => 11, 'title' => 'About', 'slug' => 'about'],
]);
$tg_missing = $tg_render([
    'status' => 200, 'kind' => 'page', 'navigation' => [],
    'page_title' => 'About',
    'item' => ['id' => 11, 'title' => 'About', 'slug' => 'about'],
]);

tg_check($tg_page['ok']
    && strpos($tg_page['html'], $tg_marker) !== false
    && $tg_page['warnings'] === []
    && in_array('smack-rows', $manifest['require_scripts'] ?? [], true),
    'TELEGRAM renders CMS-sanitized rich content and shared mosaic engines');
tg_check($tg_missing['ok']
    && $tg_missing['warnings'] === []
    && strpos($tg_missing['html'], $tg_marker) === false,
    'TELEGRAM survives a response with no rendered_content, without warning');
tg_check(strpos($layout, "snap_render_component('comments'") !== false
    && strpos($index, "=== 'not_found'") !== false,
    'TELEGRAM renders moderated CMS comments and the CMS owns error status');
tg_check(strpos($layout, "snap_render_component('navigation-tree'") !== false
    && strpos($layout, 'class="main-menu"') !== false
    && strpos($layout, 'class="mobile-navigation"') !== false
    && preg_match('/position\s*:\s*fixed/', $style) === 1,
    'TELEGRAM exposes CMS-owned navigation with its fixed mobile presentation');
tg_check(strpos($style, '--telegram-column:46rem') !== false
    && strpos($style, '.snap-mosaic') !== false,
    'TELEGRAM constrains essays and mosaics to its reading column');
tg_check(strpos($tilez, 'ORDER BY p.created_at DESC, p.id DESC') !== false,
    'TILEZ orders imported posts by publication date');
tg_check(strpos($tilezLayout, "snap_render_component('navigation-tree'") !== false
    && strpos($tilezLayout, 'class="main-menu"') !== false
    && strpos($tilezCss, 'font-synthesis: none') !== false
    && preg_match('/\.entry-content\s*>\s*\.snap-mosaic\s*,\s*\.entry-content\s*>\s*\.snap-mosaic-wall\s*\{[^}]*width:\s*100%[^}]*margin-top:\s*3em[^}]*margin-bottom:\s*3em/s', $tilezCss) === 1
    && preg_match('/\.entry-content\s*>\s*p:empty\s*\{\s*display:\s*none;\s*\}/s', $tilezCss) === 1,
    'TILEZ receives CMS navigation and keeps its readable presentation');

echo "TELEGRAM/TILEZ regression checks passed.\n";

// ===== SNAPSMACK EOF =====
