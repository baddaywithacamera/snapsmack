<?php
declare(strict_types=1);
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

$root = dirname(__DIR__);
$base = ['page_title' => 'Test', 'navigation' => [['label' => 'Home', 'url' => '/']], 'status' => 200];
$cases = [
    'single' => $base + [
        'kind' => 'single',
        'post' => ['title' => 'Story', 'created_at' => '2026-09-28', 'featured_image_path' => '/media/cover.jpg'],
        'rendered_content' => snapsmack_trusted_html('<div class="snap-mosaic" data-mosaic="[]"><p>Hello</p></div>'),
        'colophon' => 'Notes', 'comments_enabled' => true,
        'comments' => [['comment_author' => '<Reader>', 'comment_text' => '<script>no</script>']],
    ],
    'feed' => $base + ['kind' => 'feed', 'posts' => [['title' => 'Story', 'slug' => 'story', 'url' => '/?post=story', 'image_url' => '/media/cover.jpg']]],
    'archive' => $base + ['kind' => 'archive', 'tiles' => [['full' => '/media/full.jpg', 'thumb' => '/media/thumb.jpg', 'title' => 'Photo']]],
    'not_found' => $base + ['kind' => 'not_found'],
];
set_error_handler(static function (int $severity, string $message): never { throw new ErrorException($message, 0, $severity); });
try {
    foreach ($cases as $kind => $response) {
        $view = snapsmack_build_skin_view($response, [
            'site_name' => 'Example', 'tagline' => 'Tagline', 'base_url' => '/',
            'language' => 'en', 'direction' => 'ltr', 'skin_style_url' => '/skins/telegram/style.css',
        ]);
        ob_start();
        $ok = snapsmack_render_strict_skin_template($root . '/skins/telegram', 'layout.php', $view);
        $html = (string)ob_get_clean();
        if (!$ok || !str_contains($html, '<!doctype html>')) throw new RuntimeException("{$kind} did not render.");
        if (stripos($html, '<script>no</script>') !== false) throw new RuntimeException("{$kind} emitted untrusted comment HTML.");
        if ($kind === 'single' && (!str_contains($html, 'snap-mosaic') || !str_contains($html, 'snap-comments'))) {
            throw new RuntimeException('Single view lost mosaic or moderated comments.');
        }
    }
} finally {
    restore_error_handler();
}
echo "TELEGRAM schema-v2 route rendering regression passed.\n";
