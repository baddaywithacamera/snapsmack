<?php
/**
 * Regression coverage for the passive IndieWeb semantic layer.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once dirname(__DIR__) . '/core/indieweb.php';

function iw_assert(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$settings = [
    'site_name'              => 'Sean & Co.',
    'social_dock_enabled'    => '1',
    'social_dock_mastodon'   => 'https://example.social/@sean',
    'social_dock_website'    => 'javascript:alert(1)',
    'social_dock_instagram'  => 'https://instagram.com/example',
];

$urls = snapsmack_indieweb_identity_urls($settings);
iw_assert(count($urls) === 2, 'only valid HTTP(S) owner identities are returned');
iw_assert(!in_array('javascript:alert(1)', $urls, true), 'active schemes are rejected');
iw_assert(snapsmack_indieweb_identity_urls(array_merge($settings, ['social_dock_enabled' => '0'])) === [],
    'disabled Social Dock identities remain unpublished');

ob_start();
snapsmack_indieweb_head_links($settings);
$head = ob_get_clean();
iw_assert(substr_count($head, 'rel="me"') === 2, 'head emits one rel=me per public identity');
iw_assert(strpos($head, 'javascript:') === false, 'head cannot emit active-scheme identity URLs');

if (!defined('BASE_URL')) define('BASE_URL', 'https://photos.example/');
$img = [
    'id'              => 7,
    'img_slug'        => 'a-photo',
    'img_title'       => 'Chrome & Glass',
    'img_description' => '<b>A photograph.</b>',
    'img_date'        => '2026-08-07 12:00:00',
    'img_file'        => 'uploads/photo.jpg',
];
ob_start();
snapsmack_indieweb_photo_properties($img, $settings);
$entry = ob_get_clean();
foreach (['u-url', 'p-name', 'e-content', 'dt-published', 'u-photo', 'p-author', 'h-card'] as $class) {
    iw_assert(strpos($entry, $class) !== false, "photo entry emits {$class}");
}
iw_assert(strpos($entry, 'Chrome &amp; Glass') !== false, 'entry values are escaped');

$root = dirname(__DIR__);
$meta = (string)file_get_contents($root . '/core/meta.php');
$dock = (string)file_get_contents($root . '/core/social-dock.php');
$footer = (string)file_get_contents($root . '/core/footer.php');
$publicPost = (string)file_get_contents($root . '/core/public-post.php');
$main = (string)file_get_contents($root . '/index.php');
iw_assert(strpos($meta, 'snapsmack_indieweb_head_links') !== false, 'shared head is wired');
iw_assert(strpos($dock, 'rel="me noopener"') !== false, 'visible Social Dock links verify identity');
iw_assert(strpos($dock, 'snapsmack_indieweb_url') !== false, 'visible identity links share strict URL validation');
iw_assert(strpos($footer, 'class="h-card') !== false, 'shared footer emits the owner h-card');
iw_assert(strpos($main, "' h-entry'") !== false, 'solo photo controller emits h-entry');
foreach (['h-entry', 'p-name', 'e-content', 'dt-published', 'u-photo', 'p-author h-card'] as $class) {
    iw_assert(strpos($publicPost, $class) !== false, "ActivityPub public post emits {$class}");
}

foreach (['alfred', 'tilez', 'stanley', 'writing-with-impact'] as $skin) {
    $preload = (string)file_get_contents($root . '/skins/' . $skin . '/preload.php');
    foreach (['h-entry', 'p-name', 'e-content', 'dt-published', 'u-photo', 'snapsmack_indieweb_longform_properties'] as $class) {
        iw_assert(strpos($preload, $class) !== false, "{$skin} longform emits {$class}");
    }
}

echo "IndieWeb semantic regression checks passed.\n";

// ===== SNAPSMACK EOF =====
