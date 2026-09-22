<?php
/** TILEZ must remain a native-aspect three-column SMACKTALK portfolio. */
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
$assert(in_array('smack-columns', $manifest['require_scripts'] ?? [], true), 'shared columns engine is required');
$assert(!in_array('smack-rows', $manifest['require_scripts'] ?? [], true), 'TILEZ does not request the rows engine');
$assert(str_contains($preload, "str_replace('ss-scroll-wall', 'ss-masonry'"), 'MOSAIC row markup is remapped to columns mode');
$assert(in_array('smack-image-fade-load', $manifest['require_scripts'] ?? [], true), 'images can be revealed after loading');
$assert(str_contains($preload, 'img_thumb_aspect'), 'feed uses native-aspect thumbnails');
$assert(str_contains($preload, 'class="posts ss-masonry tilez-posts"'), 'feed exposes the columns-engine container');
$assert(str_contains($preload, 'data-w="<?php echo $tile_w; ?>"'), 'tiles publish source dimensions');
$assert(str_contains($style, '--ss-cols: 3'), 'desktop feed has three columns');
$assert(str_contains($style, '.header-image { display: none !important; }'), 'legacy full-screen backdrop is disabled');
$assert(str_contains($header, 'skins/tilez/assets/bad-day-masthead.png'), 'bundled masthead is the TILEZ fallback');
$assert(str_contains($header, "['label' => 'THE IDEA'"), 'old site menu labels are preserved');
$assert(str_contains($header, "['label' => 'THE IMAGES'"), 'old images menu label is preserved');
$assert(is_file($root . '/skins/tilez/assets/bad-day-masthead.png'), 'bundled masthead exists');

echo "PASS: TILEZ white columns portfolio\n";
