<?php
declare(strict_types=1);
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

$root = dirname(__DIR__);
$skins = ['alfred', 'stanley', 'writing-with-impact', 'tilez', 'telegram'];
$base = ['page_title' => 'Test', 'navigation' => [['label' => 'Home', 'url' => '/']], 'status' => 200];
$cases = [
    'single' => $base + ['kind' => 'single', 'post' => ['title' => 'Story', 'created_at' => '2026-09-28', 'featured_image_path' => '/media/cover.jpg'], 'rendered_content' => snapsmack_trusted_html('<p>CMS content</p>'), 'colophon' => 'Notes', 'comments_enabled' => true, 'comments' => [['comment_author' => '<Reader>', 'comment_text' => '<script>no</script>']]],
    'feed' => $base + ['kind' => 'feed', 'posts' => [['title' => 'Story', 'slug' => 'story', 'url' => '/?post=story', 'image_url' => '/media/cover.jpg']]],
    'archive' => $base + ['kind' => 'archive', 'tiles' => [['full' => '/media/full.jpg', 'thumb' => '/media/thumb.jpg', 'title' => 'Photo']]],
    'not_found' => $base + ['kind' => 'not_found'],
];
$styles = [];
foreach ($skins as $skin) {
    $dir = $root . '/skins/' . $skin;
    $manifest = json_decode((string)file_get_contents($dir . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['schema_version'] ?? 0) !== 2 || ($manifest['security_policy'] ?? 0) !== 2 || ($manifest['cms_controller'] ?? '') !== 'smacktalk' || ($manifest['view_model'] ?? '') !== 'snapsmack.public.v1') throw new RuntimeException("{$skin} is not schema-v2.");
    $php = glob($dir . '/*.php') ?: [];
    if (count($php) !== 1 || basename($php[0]) !== 'layout.php') throw new RuntimeException("{$skin} retains executable PHP outside its strict layout.");
    $layoutSource = (string)file_get_contents($dir . '/layout.php');
    if (str_contains($layoutSource, "snap_render_component('smacktalk-page'")) throw new RuntimeException("{$skin} still delegates its document skeleton to the CMS.");
    if (!str_contains($layoutSource, "snap_render_component('registered-assets'")) throw new RuntimeException("{$skin} does not mount registered CMS behavior assets.");
    $styles[$skin] = hash_file('sha256', $dir . '/style.css');
    foreach ($cases as $kind => $response) {
        $view = snapsmack_build_skin_view($response, ['site_name' => 'Example', 'tagline' => 'Tagline', 'base_url' => '/', 'language' => 'en', 'direction' => 'ltr', 'skin_slug'=>$skin, 'skin_style_url' => '/skins/' . $skin . '/style.css']);
        ob_start();
        $ok = snapsmack_render_strict_skin_template($dir, 'layout.php', $view);
        $html = (string)ob_get_clean();
        if (!$ok || !str_contains($html, '<!doctype html>')) throw new RuntimeException("{$skin}/{$kind} did not render.");
        if (stripos($html, '<script>no</script>') !== false) throw new RuntimeException("{$skin}/{$kind} emitted untrusted HTML.");
        if ($kind === 'single' && (!str_contains($html, 'CMS content') || !str_contains($html, 'snap-comments'))) throw new RuntimeException("{$skin} lost CMS content or comments.");
        $hook = $skin === 'stanley' ? 'id="stanley-page"' : ($skin === 'writing-with-impact' ? 'id="wwi-page"' : ($skin === 'telegram' ? 'telegram-' : 'header section-inner'));
        if (!str_contains($html, $hook)) throw new RuntimeException("{$skin}/{$kind} lost its presentation structure.");
    }
}
if (count(array_unique($styles)) !== count($skins)) throw new RuntimeException('SMACKTALK family no longer has distinct presentation styles.');

foreach (['stanley' => 'stanley-sidebar', 'writing-with-impact' => 'wwi-sidebar'] as $skin => $sidebarId) {
    $dir = $root . '/skins/' . $skin;
    foreach ([false, true] as $enabled) {
        $view = snapsmack_build_skin_view($cases['feed'], [
            'site_name' => 'Example', 'tagline' => 'Tagline', 'base_url' => '/',
            'language' => 'en', 'direction' => 'ltr', 'skin_slug' => $skin,
            'skin_style_url' => '/skins/' . $skin . '/style.css',
            'skin_presentation' => ['options' => ['show_sidebar' => $enabled]],
        ]);
        ob_start();
        $ok = snapsmack_render_strict_skin_template($dir, 'layout.php', $view);
        $html = (string)ob_get_clean();
        if (!$ok || (str_contains($html, 'id="' . $sidebarId . '"') !== $enabled)) {
            throw new RuntimeException("{$skin} ignored the saved sidebar control.");
        }
    }
}
echo "SMACKTALK schema-v2 family regression passed.\n";
