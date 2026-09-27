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
$mosaicEngine = (string)file_get_contents($root . '/assets/js/ss-engine-mosaic.js');

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
$assert(str_contains($style, 'grid-template-areas: "essay record"'), 'single posts use the editorial essay-and-record split');
$assert(str_contains($style, 'minmax(320px, 370px)'), 'the editorial record is wide enough for a composed title');
$assert(str_contains($style, '2.15vw, 2.25rem'), 'record title uses a restrained scale that avoids one-word lines');
$assert(str_contains($style, '.post-record') && str_contains($style, 'position: sticky;'), 'desktop post record remains visible beside the essay');
$titlePos = strpos($preload, '<h1 class="post-title p-name">');
$datePos = strpos($preload, '<p class="post-date">');
$assert($titlePos !== false && $datePos !== false && $titlePos < $datePos, 'single-post date sits below the title');
$assert(str_contains($preload, '<dt>Photos</dt>') && str_contains($preload, '<dt>Words</dt>'), 'post record includes photo and word counts');
$assert(str_contains($preload, '<dt>Category</dt>') && str_contains($preload, '<dt>Album</dt>') && str_contains($preload, '<dt>Author</dt>'), 'post record includes taxonomy and author');
$assert(str_contains($preload, '$_alfred_gear_note') && str_contains($style, '.post-mobile-gear'), 'camera notes move to the record and follow the essay on mobile');
$assert(str_contains($style, '.post-gear-note'), 'closing equipment notes have a readable supporting style');
$assert(str_contains($style, "font-family: Georgia, 'Times New Roman', serif;"), 'longform body uses a lighter editorial serif stack');
$assert(str_contains($style, 'font-size: 21px;') && str_contains($style, 'font-weight: 400;'), 'desktop longform body is larger and normal-weight');
$assert(str_contains($style, 'font-size: 18px; line-height: 1.65;'), 'mobile longform body remains readable');
$assert(str_contains($style, '.snap-inline-frame:has(+ .snap-inline-frame)'), 'consecutive standalone photographs form desktop pairs');
$assert(str_contains($style, 'width: calc(50% - 6px) !important;'), 'desktop photograph pairs reserve room for inter-element whitespace');
$assert(str_contains($preload, 'data-merge-adjacent-mosaics'), 'TILEZ requests continuous adjacent mosaic rendering without shipping skin JavaScript');
$assert(str_contains($mosaicEngine, 'function mergeAdjacentMosaics()'), 'the shared mosaic engine owns adjacent-bundle continuity');
$assert(!is_file($root . '/skins/tilez/assets/js/tilez-mosaic-continuity.js'), 'TILEZ does not ship JavaScript');
$assert(str_contains($style, '+ p:has(+ :is(.snap-inline-frame'), 'paragraphs between media receive symmetrical vertical spacing');
$assert(str_contains($style, '--tilez-content-start: 40px;')
    && str_contains($style, 'padding-top: var(--tilez-content-start);')
    && str_contains($style, 'margin: 0 0 40px;'),
    'feed, archive, static pages, and single posts share one menu-to-content baseline');
$assert(is_file($root . '/skins/tilez/assets/bad-day-masthead.png'), 'bundled masthead exists');

echo "PASS: TILEZ white columns portfolio\n";

// ===== SNAPSMACK EOF =====
