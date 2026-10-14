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
$check(str_contains($engine, 'window.SNAP_DATA && window.SNAP_DATA.prevUrl'),
    'SNAP_DATA is still honoured where something does set it');
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
