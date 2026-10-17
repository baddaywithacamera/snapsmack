<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * The GRAMOFSMACK frame is passed as the owner's choice, not as CSS.
 *
 * The CMS used to take three dropdown choices -- photograph inset, border
 * thickness, drop shadow -- assemble a CSS declaration string in PHP, and
 * print it on every tile as a style attribute. Two standing rules broken in
 * one line, and the same mechanism that drew the double yellow frame on
 * RATIONAL GEO (OPAUDIT 022).
 *
 * Now the choice travels as a number in data-frame-size / -border / -shadow,
 * and assets/css/ss-frame-tokens.css turns it into the tile custom properties
 * the skins already consumed. The point is that a SKIN can now refuse any of
 * them: SUDDEN IMPACT declines drop shadows because it is a 1985 Okidata
 * dot-matrix printout and a soft CSS shadow has no business on it.
 *
 * The dangerous failure here is silent: a token with no matching rule in the
 * stylesheet does not error, it falls back to the skin default and quietly
 * restyles someone's photograph. So this check proves every value the CMS can
 * emit has a rule, across the controller's full clamp range -- not merely the
 * values the dropdown currently offers, and not merely the values in use.
 */

define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';
require_once dirname(__DIR__) . '/core/public-controller.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$root = dirname(__DIR__);
// The nine carousel skins. Hard-coded, like the family roster in
// gramofsmack-family-identity.php, so adding a tenth is a deliberate act.
$family = ['aurora', 'game-on', 'heuristic', 'instant-camera', 'jive-turkey',
           'parade', 'sliders', 'sudden-impact', 'the-grid'];

$tokensCss = (string)file_get_contents($root . '/assets/css/ss-frame-tokens.css');
$check($tokensCss !== '', 'assets/css/ss-frame-tokens.css is missing.');

// ---------------------------------------------------------------------------
// 1. The CMS passes a choice, never a frame declaration
// ---------------------------------------------------------------------------
$item = ['img_title' => 'P', 'img_slug' => 'p', 'img_file' => '/p.jpg',
         'img_size_pct' => 90, 'img_border_px' => 2, 'img_border_color' => '#123456',
         'img_bg_color' => '#abcdef', 'img_shadow' => '2'];
$framed = snapsmack_grid_frame_items([$item], ['tg_customize_level' => 'per_image'], 'the-grid');
$style = (string)($framed[0]['frame_colours'] ?? '');

foreach (['--tile-img-size', '--tile-border-w', '--tile-shadow'] as $banned) {
    $check(!str_contains($style, $banned),
        "The CMS is assembling {$banned} in PHP again. The owner's choice must travel as a token.");
}
// A full CSS value such as a box-shadow triple must never be built here.
$check(!preg_match('/\d+px\s+\d+px/', $style),
    'The CMS is assembling a multi-part CSS value in PHP again: ' . $style);
// frame_style is deliberately KEPT, unchanged, for skins not yet updated.
$check(str_contains((string)($framed[0]['frame_style'] ?? ''), '--tile-img-size'),
    'frame_style no longer carries the full declaration. A site running a grid skin it has '
    . 'not updated reads that key and would lose the inset, border and shadow on every photograph.');

$check((string)($framed[0]['frame_size'] ?? '') === '90', 'frame_size token not emitted.');
$check((string)($framed[0]['frame_border'] ?? '') === '2', 'frame_border token not emitted.');
$check((string)($framed[0]['frame_shadow'] ?? '') === '2', 'frame_shadow token not emitted.');
// The token must be the bare choice, not an expanded CSS value.
$check(!str_contains((string)($framed[0]['frame_shadow'] ?? ''), 'px'),
    'frame_shadow carries CSS instead of the owner\'s choice.');

// An unrecognised saved shadow must land on a token the stylesheet has, not
// pass an arbitrary string through into the markup.
$odd = snapsmack_grid_frame_items(
    [['img_file' => '/p.jpg', 'img_shadow' => 'nonsense', 'img_border_px' => 1]],
    ['tg_customize_level' => 'per_image'], 'the-grid');
$check((string)($odd[0]['frame_shadow'] ?? '') === '0',
    'An unrecognised shadow choice is passed through instead of falling back to 0.');

// ---------------------------------------------------------------------------
// 2. Every token the CMS can emit has a rule. This is the silent one.
// ---------------------------------------------------------------------------
// These ranges are the controller's own clamps in snapsmack_grid_frame_items().
foreach (range(1, 100) as $v) {
    $check(str_contains($tokensCss, '[data-frame-size="' . $v . '"]'),
        "No rule for data-frame-size=\"{$v}\" -- that photograph would silently fall back to the skin default.");
}
foreach (range(0, 100) as $v) {
    $check(str_contains($tokensCss, '[data-frame-border="' . $v . '"]'),
        "No rule for data-frame-border=\"{$v}\" -- that photograph would silently lose its border.");
}

// The four shadow steps must match the controller's map exactly, or a choice
// renders as a different shadow than the owner picked.
$shadowMap = ['0' => 'none', '1' => '3px 3px 8px rgba(0,0,0,.20)',
              '2' => '6px 6px 18px rgba(0,0,0,.40)', '3' => '12px 12px 32px rgba(0,0,0,.60)'];
foreach ($shadowMap as $token => $css) {
    $check(str_contains($tokensCss, '[data-frame-shadow="' . $token . '"]{--tile-shadow:' . $css . '}'),
        "Shadow step {$token} in the stylesheet does not match the CMS map value '{$css}'.");
}
$controller = (string)file_get_contents($root . '/core/public-controller.php');
foreach ($shadowMap as $css) {
    $check(str_contains($controller, $css),
        "The controller's shadow map no longer contains '{$css}'; the stylesheet and the CMS have drifted apart.");
}

// ---------------------------------------------------------------------------
// 3. Every family skin emits the tokens wherever it emits a frame, and says
//    so in its manifest (a skin that does not declare the stylesheet renders
//    no frame at all, because nothing defines the custom properties).
// ---------------------------------------------------------------------------
foreach ($family as $skin) {
    $layout = (string)file_get_contents($root . '/skins/' . $skin . '/layout.php');
    $frames = substr_count($layout, "'frame_colours'");
    $sizes  = substr_count($layout, 'data-frame-size');
    $check($frames === $sizes,
        strtoupper($skin) . ": {$frames} frame style attribute(s) but {$sizes} token group(s). "
        . 'Every framed element must carry the tokens or its frame silently disappears.');
    foreach (['data-frame-border', 'data-frame-shadow'] as $attr) {
        $check(substr_count($layout, $attr) === $sizes,
            strtoupper($skin) . " is missing {$attr} on at least one framed element.");
    }
    $manifest = json_decode((string)file_get_contents($root . '/skins/' . $skin . '/manifest.json'), true);
    // A skin must NOT name this asset. Two wrong turns got here: first a CSS-only
    // entry in the engine registry (declared-engine-reaches-skin-regression caught
    // it as a silently-dropped handle), then require_styles -- which SMACK CENTRAL's
    // Skin Packager refused on all nine skins at once, correctly: a skin may not name
    // a CMS asset the packaging authority has not heard of, and the authority runs an
    // older CMS than the one the asset was added to. The stylesheet is CMS-owned at
    // both ends now and core/meta.php ships it.
    $check(!in_array('asset:public:ss-frame-tokens', (array)($manifest['require_styles'] ?? []), true),
        strtoupper($skin) . ' names ss-frame-tokens in its manifest again. The Skin Packager '
        . 'will block it. The CMS ships that stylesheet; a skin does not ask for it.');
}

// The stylesheet has to be a registered public asset, and the CMS has to actually
// emit it, or every frame silently falls back to the skin default.
$inventory = json_decode((string)file_get_contents($root . '/assets/ASSET-INVENTORY.json'), true);
$registered = false;
foreach ((array)($inventory['css'] ?? []) as $entry) {
    if (($entry['file'] ?? '') === 'assets/css/ss-frame-tokens.css') {
        $registered = ($entry['scope'] ?? '') === 'public';
    }
}
$check($registered, 'ss-frame-tokens.css is not a public asset in ASSET-INVENTORY.json.');
$check(str_contains((string)file_get_contents($root . '/core/meta.php'), 'ss-frame-tokens.css'),
    'core/meta.php no longer ships ss-frame-tokens.css. Nothing else does, so every '
    . 'photograph on every grid skin would lose its inset, border and shadow.');

// ---------------------------------------------------------------------------
// 3b. The skin chooses which key it reads; the CMS does not sniff
// ---------------------------------------------------------------------------
// The CMS and skins ship through different channels, so this CMS will run
// against skins that have not been updated. It offers both keys and lets the
// template decide: an updated skin reads frame_colours and takes the rest
// from data-frame-* plus the token stylesheet; an older one reads frame_style
// and gets exactly what it always got.
//
// An earlier version had the CMS inspect the installed manifest instead. That
// worked, but it put the decision in core, and it assembled the declaration a
// second time -- which raised the CSS-in-PHP count from 21 to 22. A ratchet
// may only fall, so the design changed rather than the budget.
foreach ($family as $skin) {
    $layout = (string)file_get_contents($root . '/skins/' . $skin . '/layout.php');
    // frame_colours FIRST, frame_style only as the fallback. A packaged skin can land
    // on a site whose CMS has not been deployed yet -- the registry is fleet-wide and
    // a site updates when it chooses. Measured without this: CMS 0.7.898D + the new
    // PARADE rendered style="" and every photograph lost its inset, border, shadow
    // AND colours. The fallback makes the skin correct on both, so no human has to
    // get a deploy order right.
    $check(str_contains($layout, "'frame_colours'] ?? \$item['frame_style']")
        || str_contains($layout, "'frame_colours'] ?? \$photograph['frame_style']"),
        strtoupper($skin) . ' does not fall back to frame_style. Installed on a site still '
        . 'on an older CMS it would render no frame at all.');
    $check(!preg_match("/snap_escape_attr\(\$(item|photograph)\['frame_style'\] \?\? ''\)/", $layout),
        strtoupper($skin) . ' reads frame_style FIRST. The CMS declaration would override the '
        . 'tokens on a current CMS.');
    $check(substr_count($layout, "'frame_colours'") === substr_count($layout, 'data-frame-size'),
        strtoupper($skin) . ' reads frame_colours on a different number of elements than it '
        . 'emits tokens on.');
}
$check(!str_contains((string)file_get_contents($root . '/core/public-controller.php'),
    'snapsmack_skin_reads_frame_tokens'),
    'The CMS is inspecting the installed skin again. Which key to read is the skin\'s decision.');

// ---------------------------------------------------------------------------
// 4. SUDDEN IMPACT refuses drop shadows. Sean, 16 Oct 2026.
// ---------------------------------------------------------------------------
$si = (string)file_get_contents($root . '/skins/sudden-impact/style.css');
$check(!str_contains($si, 'var(--tile-shadow'),
    'SUDDEN IMPACT reads --tile-shadow again. It is a dot-matrix printout and refuses drop shadows.');
$check(!str_contains($si, 'var(--slide-shadow'),
    'SUDDEN IMPACT reads --slide-shadow again. Refusing shadows means the carousel too, not just the grid.');
// And the skin it was forked from must still honour them, or this is not a
// refusal by one skin, it is a change to the family.
$tg = (string)file_get_contents($root . '/skins/the-grid/style.css');
$check(str_contains($tg, 'var(--tile-shadow'),
    'THE GRID has stopped honouring drop shadows. Only SUDDEN IMPACT refuses them.');

if ($failures !== []) {
    fwrite(STDERR, "FRAME TOKEN CONTRACT FAILED\n");
    $shown = array_slice($failures, 0, 25);
    foreach ($shown as $f) fwrite(STDERR, "  - {$f}\n");
    if (count($failures) > count($shown)) {
        fwrite(STDERR, '  ... and ' . (count($failures) - count($shown)) . " more\n");
    }
    fwrite(STDERR, "\nA frame token with no rule does not error -- it falls back to the skin\n"
        . "default and quietly restyles a photograph. That is why this is exhaustive.\n");
    exit(1);
}

echo 'Frame token contract passed: ' . count($family) . ' skins, '
    . "201 size/border rules + 4 shadow steps, SUDDEN IMPACT refuses shadows.\n";
// ===== SNAPSMACK EOF =====
