<?php
declare(strict_types=1);

/**
 * SS-1619: Verify TELEGRAM mobile feed requirements and CMS-owned excerpt helper.
 *
 * Run: php tests/telegram-mobile-feed-regression.php
 *
 * SNAPSMACK_EOF_HEADER
 * // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/asset-registry.php';

$ctrl = (string)file_get_contents($root . '/core/smacktalk-public-controller.php');
if (!preg_match('/\nfunction snapsmack_smacktalk_excerpt\(.*?\n\}/s', $ctrl, $m)) {
    fwrite(STDERR, "FAIL: could not extract snapsmack_smacktalk_excerpt from core/smacktalk-public-controller.php\n");
    exit(1);
}
eval($m[0]);

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    if (!$ok) {
        $fail++;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
};

$check('excerpt strips shortcodes and HTML tags',
       snapsmack_smacktalk_excerpt('', '<p>[img:36|full|center]</p><p>Hello <em>world</em> &amp; friends.</p>') === 'Hello world & friends.');
$check('excerpt prefers explicit description when non-empty',
       snapsmack_smacktalk_excerpt('Custom summary', 'Body text ignored') === 'Custom summary');

$view = snapsmack_build_skin_view([
    'kind' => 'feed',
    'status' => 200,
    'navigation' => [['label' => 'Home', 'url' => '/', 'current' => true, 'children' => []]],
    'posts' => [[
        'id' => 1,
        'title' => 'Start your engines',
        'url' => '/start-your-engines',
        'image_url' => '/uploads/cover.jpg',
        'excerpt' => 'Short paddock notes.',
    ]],
], [
    'site_name' => 'baddaywithacamera',
    'tagline' => 'Tagline',
    'language' => 'en',
    'direction' => 'ltr',
    'skin_style_url' => '/skins/telegram/style.css',
    'base_style_url' => '/assets/css/public-base.css',
    'skin_custom_style' => snapsmack_trusted_html(''),
    'skin_presentation' => ['header_media' => ['image' => '', 'logo' => '', 'retina' => false, 'show_tagline' => true]],
    'footer' => ['text' => 'Copyright', 'lowercase' => false],
    'registered_assets' => [],
    'owner_custom_code' => snapsmack_trusted_html(''),
]);

ob_start();
$ok = snapsmack_render_strict_skin_template($root . '/skins/telegram', 'layout.php', $view);
$html = (string)ob_get_clean();

$check('strict template renders TELEGRAM feed', $ok);
$check('rendered HTML carries site name', str_contains($html, 'baddaywithacamera'));
$check('rendered HTML carries burger toggle', str_contains($html, 'class="nav-toggle"'));
$check('rendered HTML carries square thumb wrapper', str_contains($html, 'class="telegram-feed-thumb"'));
$check('rendered HTML carries post excerpt', str_contains($html, 'class="telegram-feed-excerpt">Short paddock notes.</p>'));

$css = (string)file_get_contents($root . '/skins/telegram/style.css');
$check('CSS gives .nav-toggle .bars display:block', (bool)preg_match('/\.nav-toggle\s+\.bars\s*\{[^}]*display:\s*block;/s', $css));
$check('CSS sets square 1/1 aspect-ratio on feed thumbs', str_contains($css, 'aspect-ratio:1 / 1'));
$check('CSS keeps feed titles un-underlined and sentence-case', str_contains($css, 'text-transform:none; text-decoration:none;'));

if ($fail > 0) {
    fwrite(STDERR, "FAILED: {$fail} check(s)\n");
    exit(1);
}
echo "PASS: SS-1619 TELEGRAM mobile feed regression (10 checks).\n";
// ===== SNAPSMACK EOF =====
