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
$assert(($manifest['cover_aspect'] ?? '') === 'native', 'TILEZ editor framing follows the selected image rather than imposing a landscape crop');
$assert(str_contains($preload, 'img_thumb_aspect'), 'feed uses native-aspect thumbnails');
$assert(str_contains($preload, 'class="posts ss-masonry tilez-posts"'), 'feed exposes the columns-engine container');
$assert(str_contains($preload, 'data-w="<?php echo $tile_w; ?>"'), 'tiles publish source dimensions');
$assert(str_contains($preload, 'getimagesize($tile_asset)'), 'tile geometry is verified against the rendered thumbnail');
$assert(str_contains($style, '--ss-cols: 3'), 'desktop feed has three columns');
$assert(str_contains($style, '.header-image { display: none !important; }'), 'legacy full-screen backdrop is disabled');
$assert(str_contains($header, 'skins/tilez/assets/bad-day-masthead.png'), 'bundled masthead is the TILEZ fallback');
$assert(str_contains($header, "['label' => 'THE IDEA'"), 'old site menu labels are preserved');
$assert(str_contains($header, "['label' => 'CATEGORIES'"), 'categories menu label is present');
$assert(str_contains($header, "['label' => 'ALBUMS'"), 'albums menu label is present');
$assert(str_contains($header, "['label' => 'IMAGES'"), 'images menu label is present');
$assert(str_contains($header, 'class="tilez-icon-nav"'), 'top-right icon navigation is present');
$assert(str_contains($style, 'font-size: 16px;'), 'desktop text menu remains secondary to the masthead');
$assert(str_contains($style, '.navigation .section-inner { position: relative;')
    && str_contains($style, 'justify-content: flex-start;'),
    'round controls and text links share one uncrowded navigation row');
$navStart = strpos($header, '<nav class="navigation"');
$iconStart = strpos($header, '<div class="tilez-icon-nav"');
$assert($navStart !== false && $iconStart !== false && $iconStart > $navStart,
    'round controls live inside the navigation layer so their hit areas remain clickable');
$assert(str_contains($style, 'color: #b3261e;')
    && str_contains($style, 'content: attr(aria-label);'),
    'text and round navigation have visible hover feedback and named hints');
$assert(!str_contains($preload, '<figure class="featured-media"'), 'single posts start with their title instead of repeating the archive cover');
$assert(str_contains($style, 'grid-template-areas: "essay record"'), 'single posts use the editorial essay-and-record split');
$assert(str_contains($style, 'minmax(320px, 370px)'), 'the editorial record is wide enough for a composed title');
$assert(str_contains($style, '2.6vw, 2.7rem'), 'record title keeps the approved editorial scale');
$assert(str_contains($style, '.post-record') && str_contains($style, 'position: sticky;'), 'desktop post record remains visible beside the essay');
$titlePos = strpos($preload, '<h1 class="post-title p-name">');
$datePos = strpos($preload, '<p class="post-date">');
$assert($titlePos !== false && $datePos !== false && $titlePos < $datePos, 'single-post date sits below the title');
$assert(str_contains($preload, '<dt>Photos</dt>') && str_contains($preload, '<dt>Words</dt>'), 'post record includes photo and word counts');
$assert(str_contains($preload, '<dt>Category</dt>') && str_contains($preload, '<dt>Album</dt>') && str_contains($preload, '<dt>Author</dt>'), 'post record includes taxonomy and author');
$assert(str_contains($preload, '$_alfred_gear_note') && str_contains($style, '.post-mobile-gear'), 'camera notes move to the record and follow the essay on mobile');
$assert(str_contains($preload, '$_alfred_signature')
    && str_contains($preload, 'sean-mccormick-black-low-res')
    && str_contains($preload, 'post-signature post-signature--closing')
    && str_contains($style, '.post-signature--closing'),
    'the imported handwritten signature closes the essay instead of appearing in the sidebar record');
$assert(str_contains($preload, "LOWER(img_title) NOT LIKE '%signature%'")
    && str_contains($preload, "LOWER(img_title) NOT LIKE '%sean-mccormick-black-low-res%'"),
    'decorative signatures do not appear as black photograph tiles in the archive');
$assert(str_contains($preload, '$legacy_is_colophon')
    && str_contains($preload, 'photos? (?:taken|made|shot) with')
    && str_contains($preload, 'count($legacy_equipment_terms) >= 3'),
    'varied legacy closing equipment paragraphs are recognized as recurring post colophons');
$legacyFixture = '<div class="initial-letter"><p>Opening paragraph.</p><div><img src="photo.jpg"></div><p>The main camera was a Canon EOS 7D with a Helios lens and DJI drone.</p></div>';
$legacyPattern = '~(<p\b[^>]*>(?:(?!<p\b).)*?</p>)(?:\s*</div>\s*)*$~is';
$assert(preg_match($legacyPattern, $legacyFixture, $legacyMatch, PREG_OFFSET_CAPTURE) === 1
    && $legacyMatch[1][0] === '<p>The main camera was a Canon EOS 7D with a Helios lens and DJI drone.</p>',
    'legacy colophon matching captures only the final paragraph, never the whole wrapped article');
$assert(str_contains($style, '.post-gear-note'), 'closing equipment notes have a readable supporting style');
$assert(str_contains($style, "font-family: Georgia, 'Times New Roman', serif;"), 'longform body uses a lighter editorial serif stack');
$assert(str_contains($style, 'font-size: 21px;') && str_contains($style, 'font-weight: 400;'), 'desktop longform body is larger and normal-weight');
$assert(str_contains($style, 'font-size: 18px; line-height: 1.65;'), 'mobile longform body remains readable');
$assert(str_contains($style, '.snap-inline-frame:has(+ .snap-inline-frame)'), 'consecutive standalone photographs form desktop pairs');
$assert(str_contains($style, 'width: calc(50% - 6px) !important;'), 'desktop photograph pairs reserve room for inter-element whitespace');
$assert(str_contains($preload, 'data-merge-adjacent-mosaics'), 'TILEZ requests continuous adjacent mosaic rendering without shipping skin JavaScript');
$assert(str_contains($mosaicEngine, 'function mergeAdjacentMosaics()'), 'the shared mosaic engine owns adjacent-bundle continuity');
$assert(str_contains($mosaicEngine, ".snap-mosaic[data-mosaic], .snap-mosaic-wall")
    && str_contains($mosaicEngine, "while (cursor.firstChild) first.appendChild(cursor.firstChild)"),
    'shared continuity also joins adjacent column, row, and square MOSAIC walls');
$assert(!is_file($root . '/skins/tilez/assets/js/tilez-mosaic-continuity.js'), 'TILEZ does not ship JavaScript');
$assert(str_contains($style, '+ p:has(+ :is(.snap-inline-frame'), 'paragraphs between media receive symmetrical vertical spacing');
$assert(str_contains($style, '--tilez-content-start: 64px;')
    && str_contains($style, 'padding-top: var(--tilez-content-start);')
    && str_contains($style, 'margin: 0 0 40px;'),
    'feed, archive, static pages, and single posts share one menu-to-content baseline');
$assert(str_contains($style, 'filter: brightness(.66);')
    && str_contains($style, 'transform: scale(1.065);'),
    'landing tiles zoom and darken decisively on hover and keyboard focus');
$assert(str_contains($style, 'opacity: 1;')
    && str_contains($style, 'rgba(0,0,0,.72)'),
    'landing titles remain visible over a restrained dark gradient');
$assert(is_file($root . '/skins/tilez/assets/bad-day-masthead.png'), 'bundled masthead exists');

echo "PASS: TILEZ white columns portfolio\n";

// ===== SNAPSMACK EOF =====
