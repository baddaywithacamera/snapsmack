<?php
/** TILEZ must remain a native-aspect three-column SMACKTALK portfolio. */
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents($root . '/skins/tilez/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$preload  = (string)file_get_contents($root . '/skins/tilez/preload.php');
$style    = (string)file_get_contents($root . '/skins/tilez/style.css');
$header   = (string)file_get_contents($root . '/skins/tilez/skin-header.php');

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(in_array('smack-columns', $manifest['require_scripts'] ?? [], true), 'shared columns engine is required');
$assert(in_array('smack-rows', $manifest['require_scripts'] ?? [], true), 'rows engine is required for row MOSAIC bundles');
$assert(in_array('smack-image-fade-load', $manifest['require_scripts'] ?? [], true), 'images can be revealed after loading');
$assert(str_contains($preload, 'img_thumb_aspect'), 'feed uses native-aspect thumbnails');
$assert(str_contains($preload, 'class="posts ss-masonry tilez-posts"'), 'feed exposes the columns-engine container');
$assert(str_contains($preload, 'data-w="<?php echo $tile_w; ?>"'), 'tiles publish source dimensions');
$assert(str_contains($style, '--ss-cols: 3'), 'desktop feed has three columns');
$assert(str_contains($style, '.header-image { display: none !important; }'), 'legacy full-screen backdrop is disabled');
$assert(str_contains($header, 'skins/tilez/assets/bad-day-masthead.png'), 'bundled masthead is the TILEZ fallback');
$assert(str_contains($header, "['label' => 'THE IDEA'"), 'old site menu labels are preserved');
$assert(str_contains($header, "['label' => 'CATEGORIES'"), 'categories menu label is present');
$assert(str_contains($header, "['label' => 'ALBUMS'"), 'albums menu label is present');
$assert(str_contains($header, "['label' => 'IMAGES'"), 'images menu label is present');
$assert(str_contains($header, 'class="tilez-icon-nav"'), 'top-right icon navigation is present');
$assert(str_contains($style, 'font-size: 20px;'), 'desktop text menu is doubled in size');
$assert(!str_contains($preload, '<figure class="featured-media"'), 'single posts start with their title instead of repeating the archive cover');
$assert(str_contains($style, 'font-size: clamp(3.25rem, 6vw, 6rem);'), 'single-post title is deliberately large');
$assert(is_file($root . '/skins/tilez/assets/bad-day-masthead.png'), 'bundled masthead exists');

echo "PASS: TILEZ white columns portfolio\n";

// ===== SNAPSMACK EOF =====
