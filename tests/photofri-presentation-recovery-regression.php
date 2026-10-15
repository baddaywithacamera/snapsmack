<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';

$expect = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$trusted = (string)snapsmack_trusted_html(
    '<div class="pfd-faq"><details><summary><span class="pfd-q">Question</span>'
    . '<span class="pfd-arrow">+</span></summary><section class="pfd-answer">Answer</section></details></div>'
    . '<img class="thumb" src="https://example.test/portrait.jpg" alt="Portrait">'
);
$expect(str_contains($trusted, '<details>') && str_contains($trusted, '<summary>'),
    'Trusted CMS content flattened native FAQ disclosure controls.');
$expect(str_contains($trusted, 'class="pfd-answer"') && str_contains($trusted, 'class="pfd-q"'),
    'Trusted CMS content stripped FAQ presentation hooks.');
$expect(str_contains($trusted, 'class="thumb"'),
    'Trusted CMS content stripped the Board square-image hook.');

$view = snapsmack_build_skin_view([
    'status' => 200,
    'kind' => 'page',
    'is_homepage' => true,
    'item' => ['title' => 'Home', 'slug' => 'home', 'content' => snapsmack_trusted_html('<p>Front door</p>')],
    'navigation' => [],
], [
    'site_name' => 'PhotoFri',
    'language' => 'en',
    'direction' => 'ltr',
    'base_style_url' => '/assets/css/public-base.css',
    'skin_style_url' => '/skins/onyx/style.css',
    'skin_custom_style' => snapsmack_trusted_html(''),
    'skin_presentation' => ['style' => snapsmack_trusted_html('')],
    'owner_custom_code' => snapsmack_trusted_html(''),
    'registered_assets' => ['styles' => [], 'scripts' => []],
    'footer' => [],
]);
ob_start();
$rendered = snapsmack_render_strict_skin_template($root . '/skins/onyx', 'layout.php', $view);
$html = (string)ob_get_clean();
$expect($rendered && str_contains($html, 'class="onyx-v2 static-transmission homepage-static page-slug-home"'),
    'Onyx static homepage lost the body classes its landing design requires.');

$controller = (string)file_get_contents($root . '/core/public-controller.php');
$expect(str_contains($controller, "if (\$homepageMode === 'static_page')")
    && str_contains($controller, 'activePageById')
    && str_contains($controller, "'is_homepage' => true"),
    'Strict public routing no longer honours the configured static homepage.');

echo "PhotoFri presentation recovery regression passed.\n";
