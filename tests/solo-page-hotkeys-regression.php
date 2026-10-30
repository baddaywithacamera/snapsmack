<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The keys that move you through a photoblog, and the one that should not.
 *
 * Sean, on a 50 Shades solo page: the spacebar does not go to the previous
 * image, the arrow keys do nothing, and "2" opens the comments pane although
 * comments are switched off in the admin.
 *
 * ARROWS AND SPACEBAR. ss-engine-comms.js read window.SNAP_DATA for the
 * previous and next addresses. Nothing has set that global since the pre-CMS
 * reference code -- it appears in no PHP file in the repository at any
 * revision checked -- so the engine was asking a question no page answered,
 * and the keys were dead on all 24 skins that declare the hotkey engine, not
 * only this one. It now reads the page's own previous/next links, which the
 * photo navigation already renders and now marks rel="prev" / rel="next".
 * A link that is on the page cannot drift out of step with the page.
 *
 * THE "2" KEY. Comments are one feature with two switches:
 * global_comments_enabled in Settings, and community_comments_enabled on the
 * community engine. Only the first was consulted, so turning the other off
 * hid the comments while leaving the COMMENTS link -- and its hotkey -- live,
 * opening a pane the site would never fill. The gate also ran for a
 * photograph only; a post and a collection carry the same navigation bar and
 * were never gated at all.
 */

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$nav = static function (bool $commentsEnabled): string {
    return (string)snap_render_component('photo-navigation', [
        'item'     => ['url' => '/here'],
        'previous' => ['url' => '/older'],
        'next'     => ['url' => '/newer'],
        'first'    => ['url' => '/first'],
        'last'     => ['url' => '/last'],
        'comments' => [],
        'comments_enabled' => $commentsEnabled,
    ]);
};

// --- the arrow keys need somewhere to go --------------------------------
$bar = $nav(true);
$check(preg_match('/<a [^>]*href="\/older"[^>]*rel="prev"/', $bar) === 1,
    'the previous link must carry rel="prev"; the arrow keys find it that way');
$check(preg_match('/<a [^>]*href="\/newer"[^>]*rel="next"/', $bar) === 1,
    'the next link must carry rel="next"');

// A link that would navigate to the page you are already on is dimmed, and a
// dimmed one must not advertise itself as somewhere to go.
$ends = (string)snap_render_component('photo-navigation', [
    'item' => ['url' => '/here'], 'previous' => [], 'next' => [],
    'first' => ['url' => '/first'], 'last' => ['url' => '/last'],
    'comments' => [], 'comments_enabled' => false,
]);
$check(!str_contains($ends, 'rel="prev"'), 'no previous photograph, no rel="prev" to chase');
$check(!str_contains($ends, 'rel="next"'), 'no next photograph, no rel="next" to chase');

// --- the engine must read those links -----------------------------------
$engine = (string)@file_get_contents($root . '/assets/js/ss-engine-comms.js');
$check(str_contains($engine, "a[rel=\"' + rel + '\"][href]"),
    'the hotkey engine must take the addresses from the page it is on');
$check(!preg_match('/if \(window\.SNAP_DATA && !sliderActive\)/', $engine),
    'arrow navigation must not be gated on a global nothing sets');
// SNAP_DATA is still honoured where something does set it. This used to pin
// the literal 'window.SNAP_DATA && window.SNAP_DATA.prevUrl', which lived
// inline in the keydown handler. The three address sources were factored out
// into ssNavUrl() so the F1 help modal could report exactly what the handler
// binds instead of guessing at it, and the literal moved with them. The
// guarantee is unchanged and is what is checked here: the resolver consults
// SNAP_DATA, and it is the resolver the arrows actually call.
$check((bool)preg_match('/function ssNavUrl\b/', $engine),
    'the three address sources must stay in one resolver, or the help modal starts guessing again');
$check((bool)preg_match('/ssNavUrl[\s\S]{0,400}window\.SNAP_DATA\s*&&\s*window\.SNAP_DATA\[/', $engine),
    'SNAP_DATA is still honoured where something does set it');
$check(str_contains($engine, "var prevUrl = ssNavUrl('prev')")
    && str_contains($engine, "var nextUrl = ssNavUrl('next')"),
    'the arrow keys must read their addresses through that resolver, not a second copy');
foreach (["e.key === 'ArrowLeft'", "e.key === 'ArrowRight'", "e.key === ' '"] as $key) {
    $check(str_contains($engine, $key), 'the engine must still bind ' . $key);
}

// --- and "2" must have nothing to open when comments are off ------------
$check(str_contains($nav(true), 'id="show-comments"'),
    'comments on: the COMMENTS link is there');
$check(!str_contains($nav(false), 'id="show-comments"'),
    'comments off: the COMMENTS link must be gone, or the "2" key opens a pane '
    . 'the site will never fill');
$check(str_contains($nav(false), 'id="show-details"'),
    'the INFO tray is a separate feature and stays');

// --- both switches, and all three solo kinds ----------------------------
$runtime = (string)@file_get_contents($root . '/core/public-runtime.php');
$check(str_contains($runtime, "\$settings['global_comments_enabled']"),
    'the Settings switch must be consulted');
$check(str_contains($runtime, "\$settings['community_comments_enabled']"),
    'the community switch must be consulted too; one feature, two switches');
$check(preg_match("/in_array\(\(\\\$response\['kind'\] \?\? ''\), \['photo', 'post', 'collection'\]/", $runtime) === 1,
    'a post and a collection carry the same navigation bar and must be gated as well');

// --- #infobox holds the PHOTO navigation, not the site menu -------------
// IMPACT PRINTER, NEW HORIZON, RATIONAL GEO and TRUE GRIT rendered the site
// menu into #infobox, which is where the prev/first/info/comments/last/next
// bar belongs -- 50 Shades has always put it there, and all four skins style
// #infobox for that bar's markup (.center, .filter-group, a.active). Sean saw
// it as "the top menu in place of the bottom footer". One substitution took
// four faults with it: the menu in the wrong place, the missing nav bar, the
// dead arrow keys (the bar carries rel="prev"/"next") and the comments
// hotkey (the bar honours comments_enabled).
foreach (['core/impact-printer-page-component.php', 'core/50-shades-page-component.php',
          'skins/new-horizon/layout.php', 'skins/rational-geo/layout.php',
          'skins/true-grit/layout.php'] as $template) {
    $src = (string)@file_get_contents($root . '/' . $template);
    $at = strpos($src, 'id="infobox"');
    $check($at !== false, $template . ' must still have an #infobox');
    if ($at === false) continue;
    $block = substr($src, $at, (int)strpos($src, '</div>', $at) - $at);
    $check(str_contains($block, "snap_render_component('photo-navigation'"),
        $template . ': #infobox must hold the photo navigation');
    $check(!str_contains($block, "snap_render_component('navigation'"),
        $template . ': #infobox must not hold the site menu, which belongs in the header');
}

// --- EXIF is withheld by the CMS when the owner switches it off ---------
// Only core/meta.php honoured exif_display_enabled, and no strict skin runs
// that file -- so every converted site published its camera data whatever
// the owner had chosen. A skin cannot print what it was not given.
$runtime = (string)@file_get_contents($root . '/core/public-runtime.php');
$check(str_contains($runtime, "\$settings['exif_display_enabled']"),
    'the CMS must honour the EXIF switch, not leave it to a file strict skins never run');
// The first repair emptied item['exif'] early in the runtime -- and the
// field is REBUILT from the raw img_exif column later in the same function,
// so the table kept rendering and I reported it fixed. The switch has to be
// read where the field is made.
$check(str_contains($runtime, '$exifOff = (($settings[' . "'" . 'exif_display_enabled' . "'" . '] ?? ' . "'1'" . ') !== ' . "'1'" . ')'),
    'the EXIF switch must be read inside the closure that builds the field');
$check(str_contains($runtime, '$exif = $exifOff ? [] : json_decode'),
    'the raw column must not be decoded at all when the switch is off, or a '
    . 'later line rebuilds what an earlier one cleared');
$check(str_contains($runtime, '$item[' . "'technical'" . '] = []; $item[' . "'img_exif'" . '] = ' . "''" . ';'),
    'the formatted block and the raw column must both be cleared with it');

// --- the engine reaches the skins that need it --------------------------
$declaring = 0;
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $path) {
    $manifest = json_decode((string)file_get_contents($path), true);
    if (in_array('smack-keyboard', $manifest['require_scripts'] ?? [], true)) $declaring++;
}
$check($declaring >= 20,
    'expected the hotkey engine on at least 20 skins, found ' . $declaring
    . ' -- this fault was never one skin');

printf("solo hotkeys: prev/next advertised, comments gated on both switches, %d skins carry the engine.\n",
    $declaring);

if ($failures) {
    fwrite(STDERR, "Solo page hotkeys regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Solo page hotkeys regression passed.\n";

// ===== SNAPSMACK EOF =====
