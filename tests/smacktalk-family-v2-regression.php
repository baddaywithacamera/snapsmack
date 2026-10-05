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
    'single' => $base + ['kind' => 'single', 'post' => ['title' => 'Story', 'created_at' => '2026-09-28', 'featured_image_path' => '/media/cover.jpg'], 'rendered_content' => snapsmack_trusted_html('<p>CMS content</p>'), 'signature' => ['url' => '/media/signature.png', 'alt' => 'Signature'], 'photo_count' => 3, 'word_count' => 250, 'categories' => ['Diary'], 'albums' => ['Ray'], 'categories_label' => 'Diary', 'albums_label' => 'Ray', 'author' => 'Sean', 'colophon' => 'Notes', 'previous' => ['title' => 'Earlier', 'slug' => 'earlier', 'url' => '/?post=earlier'], 'next' => ['title' => 'Later', 'slug' => 'later', 'url' => '/?post=later'], 'comments_enabled' => true, 'comments' => [['comment_author' => '<Reader>', 'comment_text' => '<script>no</script>']]],
    'feed' => $base + ['kind' => 'feed', 'show_titles' => true, 'posts' => [['title' => 'Story', 'slug' => 'story', 'url' => '/?post=story', 'image_url' => '/media/cover.jpg', 'width' => 1600, 'height' => 1200, 'created_label' => 'Sep 28, 2026']]],
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
        if ($skin === 'tilez' && $kind === 'feed' && (!str_contains($html, 'ss-masonry-item') || !str_contains($html, 'post-overlay'))) {
            throw new RuntimeException('TILEZ feed items no longer satisfy the shared columns-engine markup contract.');
        }
        if ($skin === 'tilez' && $kind === 'feed' && (!str_contains($html, 'data-w="1600"') || !str_contains($html, 'archive-post-date') || !str_contains($html, 'show-preview-titles'))) {
            throw new RuntimeException('TILEZ feed lost native-aspect metadata, dates, or its saved title mode.');
        }
        if ($skin === 'tilez' && $kind === 'single' && (!str_contains($html, 'post-record') || !str_contains($html, 'post-facts') || !str_contains($html, 'post-signature--closing') || !str_contains($html, 'post-navigation'))) {
            throw new RuntimeException('TILEZ single view lost its editorial record, signature, facts, or adjacent navigation.');
        }
        if ($skin === 'tilez' && (!str_contains($html, 'tilez-icon-nav') || !str_contains($html, 'custom-logo-link'))) {
            throw new RuntimeException('TILEZ lost its commissioned masthead or quick-navigation structure.');
        }
        $hook = $skin === 'stanley' ? 'id="stanley-page"' : ($skin === 'writing-with-impact' ? 'id="wwi-page"' : ($skin === 'telegram' ? 'telegram-' : 'header section-inner'));
        if (!str_contains($html, $hook)) throw new RuntimeException("{$skin}/{$kind} lost its presentation structure.");
    }
}
if (count(array_unique($styles)) !== count($skins)) throw new RuntimeException('SMACKTALK family no longer has distinct presentation styles.');

$tilezBlogroll = snapsmack_build_skin_view($base + [
    'kind' => 'blogroll',
    'blogroll_groups' => [['label' => 'Friends', 'items' => [[
        'name' => 'Away With A Camera', 'url' => 'https://awaywithacamera.com/',
        'description' => 'Local day trips and fine photographs.',
    ]]]],
], [
    'site_name' => 'Example', 'tagline' => 'Tagline', 'base_url' => '/',
    'language' => 'en', 'direction' => 'ltr', 'skin_slug' => 'tilez',
    'skin_style_url' => '/skins/tilez/style.css', 'favicon_url' => '/media/adorable.ico',
]);
ob_start();
$tilezBlogrollOk = snapsmack_render_strict_skin_template($root . '/skins/tilez', 'layout.php', $tilezBlogroll);
$tilezBlogrollHtml = (string)ob_get_clean();
foreach (['class="content tilez-blogroll"', 'Away With A Camera', 'Local day trips and fine photographs.', 'rel="icon" href="/media/adorable.ico"', '/blogroll'] as $hook) {
    if (!$tilezBlogrollOk || !str_contains($tilezBlogrollHtml, $hook)) throw new RuntimeException("TILEZ blogroll/favicon presentation missing: {$hook}");
}

$tilezPage = snapsmack_build_skin_view($base + [
    'kind' => 'page', 'page_title' => 'About', 'item' => ['title' => 'About'],
    'rendered_content' => snapsmack_trusted_html('<p>Page body</p>'),
], [
    'site_name' => 'Example', 'base_url' => '/', 'language' => 'en', 'direction' => 'ltr',
    'skin_slug' => 'tilez', 'skin_style_url' => '/skins/tilez/style.css',
    'footer' => ['slots' => [
        ['kind' => 'copyright', 'year' => '2026', 'site_name' => 'Example'],
        ['kind' => 'link', 'label' => 'RSS', 'url' => '/feed'],
    ]],
]);
ob_start();
$tilezPageOk = snapsmack_render_strict_skin_template($root . '/skins/tilez', 'layout.php', $tilezPage);
$tilezPageHtml = (string)ob_get_clean();
foreach (['class="page tilez-page-view tilez-v2"', 'class="content tilez-page"', 'class="page-container post-inner"', 'class="page-record post-record"', '<h1 class="post-title">About</h1>', '<p>Page body</p>', 'footer-metadata-bar', '&copy; 2026', '>RSS</a>'] as $hook) {
    if (!$tilezPageOk || !str_contains($tilezPageHtml, $hook)) throw new RuntimeException("TILEZ page/footer presentation missing: {$hook}");
}

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
