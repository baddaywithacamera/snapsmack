<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A skin that loads an engine must emit the markup that engine drives.
 *
 * GALLERIA's manifest has listed `smack-overlay` in require_scripts the whole
 * time. `assets/js/ss-engine-overlay.js` states its contract in its own
 * header -- `.htbs-tab[data-pane]` and `.htbs-pane#htbs-pane-{name}` -- and
 * the strict conversion stopped emitting any of it. So the engine loaded on
 * every page and bound nothing, 21 stylesheet rules matched nothing, and
 * #show-details / #show-comments opened an empty drawer. The description,
 * the EXIF table and the whole comment thread were behind a door that was no
 * longer there.
 *
 * Nothing failed. A missing feature emits no error -- the same shape as the
 * comment system in 0.7.910D and the filmstrip in 0.7.909D.
 *
 * This is the general check: for each engine that declares a required hook,
 * some skin that loads it must emit that hook.
 */

$root = dirname(__DIR__);
$fail = [];
$check = static function (bool $ok, string $what) use (&$fail): void {
    if (!$ok) $fail[] = $what;
};

// GALLERIA and its fork HIP TO BE SQUARE share the htbs- stylesheet and both
// declare the engine. Checking only one lets the other drift, which is how
// the fork came to render comments inline while 21 overlay rules sat dead.
$surfaces = [
    'GALLERIA' => ['src' => '/core/galleria-page-component.php', 'manifest' => '/skins/galleria/manifest.json'],
    'HIP TO BE SQUARE' => ['src' => '/skins/hip-to-be-square/layout.php', 'manifest' => '/skins/hip-to-be-square/manifest.json'],
];
foreach ($surfaces as $name => $where) {
$galleria = (string)file_get_contents($root . $where['src']);

// ── the overlay engine's documented contract ────────────────────────────────
foreach ([
    'id="htbs-info-overlay"'      => 'the overlay root the engine looks up by id',
    'htbs-overlay-backdrop'       => 'the backdrop, which is also its click-to-close target',
    'htbs-overlay-box'            => 'the box every one of the 21 overlay rules hangs off',
    'data-pane="info"'            => 'the INFO tab',
    'data-pane="comments"'        => 'the SIGNALS tab',
    'id="htbs-pane-info"'         => 'the INFO pane the engine toggles by id',
    'id="htbs-pane-comments"'     => 'the SIGNALS pane',
    'htbs-overlay-close'          => 'the close button',
] as $needle => $what) {
    $check(str_contains($galleria, $needle), $name . ' no longer emits ' . $what);
}

// The panes must carry what the overlay exists to show.
$check(str_contains($galleria, "\$view['response']['item']['exif']"),
    $name . ': the INFO pane dropped the EXIF table again');
$check(str_contains($galleria, "snap_render_component('comments'"),
    $name . ': the SIGNALS pane renders no comments, which is the whole point of it');
// The description belongs in the pane, not loose on the page -- style.css
// says so itself: "(Info & comments now rendered inside .htbs-overlay-box)".
$check(substr_count($galleria, 'static-transmission') === 1,
    $name . ': the description is emitted twice, or no longer inside the overlay');

// ── and the engine is actually loaded ───────────────────────────────────────
$manifest = json_decode((string)file_get_contents($root . $where['manifest']), true);
$check(in_array('smack-overlay', (array)($manifest['require_scripts'] ?? []), true),
    $name . ' stopped loading the overlay engine, so the markup drives nothing');
}

// ── the openers the engine binds to ─────────────────────────────────────────
// #show-details and #show-comments come from the shared photo-navigation
// component; keys 1 and 2 click them. GALLERIA hides the nav bar, so the
// hotkeys are the route in and these ids have to keep existing.
$helpers = (string)file_get_contents($root . '/core/skin-render-helpers.php');
$check(str_contains($helpers, 'id="show-details"'), 'the INFO opener id is gone');
$check(str_contains($helpers, 'id="show-comments"'), 'the COMMENTS opener id is gone');
$comms = (string)file_get_contents($root . '/assets/js/ss-engine-comms.js');
$check(str_contains($comms, "e.key === '1'") && str_contains($comms, "e.key === '2'"),
    'the 1 and 2 hotkeys are gone, leaving GALLERIA with no way to open the overlay');

// ── a solo page is one screen, and never without navigation ────────────────
// .htbs-single IS the single-screen layout -- the main region sized to what
// is left of the viewport, overflow hidden, footer at the bottom. 0.7.913D
// tied it to the filmstrip, so a site with htbs_show_filmstrip off lost the
// whole layout and the page began to scroll. Sean: "it used to all fit in a
// page without scrolling."
//
// The class is unconditional now. What must stay guarded is the rule that
// HIDES the navigation bar: that is only correct when a filmstrip is there
// to replace it.
foreach ($surfaces as $name => $where) {
    $src = (string)file_get_contents($root . $where['src']);
    $check(str_contains($src, 'htbs-single'), $name . ' no longer emits the single-screen stage class');
    $check(str_contains($src, 'has-filmstrip'),
        $name . ' does not mark whether a filmstrip is present, so the navigation '
        . 'bar cannot be hidden only when one replaces it');
}
foreach (['galleria', 'hip-to-be-square'] as $skin) {
    $css = (string)file_get_contents($root . '/skins/' . $skin . '/style.css');
    $check(!preg_match('/\.htbs-single\s+#infobox\s*\{[^}]*display:\s*none/', $css),
        $skin . ' hides the navigation bar on every solo page, including those '
        . 'with no filmstrip to replace it');
    $check(preg_match('/\.htbs-single\.has-filmstrip\s+#infobox\s*\{[^}]*display:\s*none/', $css) === 1,
        $skin . ' no longer steps the navigation bar aside for the filmstrip');
    // And the view has to be capped, or the column grows past the fold.
    $check(str_contains($css, 'body:has(.htbs-single)'),
        $skin . ' does not cap the single view to one screen, so it scrolls by '
        . 'the height of the header and footer');
}

if ($fail) {
    fwrite(STDERR, "FAIL: the overlay engine drives nothing\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "PASS: GALLERIA emits the overlay its engine, its stylesheet and its hotkeys expect.\n";
// ===== SNAPSMACK EOF =====
