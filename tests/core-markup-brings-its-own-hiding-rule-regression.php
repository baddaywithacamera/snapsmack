<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// CORE EMITS THE CLASS, SO CORE SHIPS THE RULE.
//
// WHAT THE OWNER SAW. On the used car parts homepage (SCROLL), the social
// links in the header rendered as the words "Vero Bluesky Linktree Website",
// jammed together and sitting on top of the navigation circles, with no icons.
// He said it had looked right a few weeks earlier.
//
// WHAT IT ACTUALLY WAS, measured on that live page at 1920 before the fix:
// core/skin-render-helpers.php emits <span class="screen-reader-text"> inside
// every dock link so the link has an accessible name. NOTHING HID IT. The span
// computed to position:static, 40.6px by 25px, overflow:visible. Each dock
// link is a 40px flex item that centres its contents, so an icon plus visible
// text overflowed it and pushed the glyph OUT of its own link -- 11 to 27
// pixels to the left -- onto the navigation circles beside it. The icons were
// never missing. They had been shoved sideways and the labels were lying over
// the top.
//
// Three skins of thirty-one define .screen-reader-text in their own
// stylesheet: ALFRED, TELEGRAM and TILEZ. On the other twenty-eight the label
// showed. Twenty-eight of the skins are strict, which is the same population
// the archive control row was stranded on.
//
// THE RULE THIS FILE DEFENDS. A class emitted by CORE markup must be defined
// on a CORE stylesheet with the same reach as that markup. Leaving it to each
// skin is the CHAPLIN fault again -- markup on the CMS rail, stylesheet on the
// skin rail -- and it fails silently, because a missing CSS rule throws
// nothing, logs nothing and passes every test that reads HTML.
//
// Proof the fix works is a browser measurement, not this file: with the rule
// in place the same live page puts every glyph at exactly +10px inside its
// 40px link, centred, and the labels are gone from view. A PHP test cannot lay
// out a page. What it CAN do is hold the contract: core emits the class, and
// the always-loaded core stylesheet hides it.

$root = dirname(__DIR__);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

/** CSS with comments removed, so a check can never match prose about a rule. */
$strip = static function (string $css): string {
    return (string) preg_replace('~/\*.*?\*/~s', '', $css);
};

echo "Core markup brings its own hiding rule\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "CORE REALLY DOES EMIT THE CLASS\n";
// ─────────────────────────────────────────────────────────────────────────────
// Driven through the real helper rather than grepped, because what matters is
// what it PRODUCES. A helper that stopped emitting the span would make the
// stylesheet rule dead weight, and this check would say so.

$markup = snapsmack_social_dock_markup([
    'social' => [
        ['url' => 'https://example.com/a', 'label' => 'Vero', 'icon' => 'vero'],
        ['url' => 'https://example.com/b', 'label' => 'Bluesky', 'icon' => 'bluesky'],
    ],
    'icon_sprite_url' => 'https://example.test/assets/icons/crop-circles.svg',
], 'inline', 'Social links');

$check('the dock helper renders links at all', str_contains($markup, 'dock-link'));
$check('and each one carries a screen-reader-text label',
       substr_count($markup, 'class="screen-reader-text"') === 2);
$check('  the label text is the real platform name, not a placeholder',
       str_contains($markup, '>Vero<') && str_contains($markup, '>Bluesky<'));
$check('  and the icon is a sprite reference, so the label is the ONLY text',
       str_contains($markup, '<use href='));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAND THE ALWAYS-LOADED CORE STYLESHEET HIDES IT\n";
// ─────────────────────────────────────────────────────────────────────────────
// public-base.css is the one that loads on every public page; core/meta.php
// says so in as many words. That is the same reach as the markup above, which
// is the whole point.

$base_path = $root . '/assets/css/public-base.css';
$check('public-base.css exists', is_file($base_path));
$base = $strip((string) file_get_contents($base_path));

$check('it defines .screen-reader-text', (bool) preg_match('~\.screen-reader-text\s*\{~', $base));

// Not merely "the selector appears" -- the DECLARATIONS have to do the hiding.
// A rule that set only a colour would satisfy a substring check and leave the
// owner looking at the same page.
if (preg_match('~\.screen-reader-text\s*\{([^}]*)\}~', $base, $m)) {
    $body = $m[1];
    $check('  it takes the label out of the flow (position: absolute)',
           (bool) preg_match('~position\s*:\s*absolute~', $body));
    $check('  it collapses the box (width and height 1px)',
           (bool) preg_match('~width\s*:\s*1px~', $body)
           && (bool) preg_match('~height\s*:\s*1px~', $body));
    $check('  it clips what is left (overflow hidden and clip)',
           (bool) preg_match('~overflow\s*:\s*hidden~', $body)
           && (bool) preg_match('~clip\s*:~', $body));
} else {
    $check('  the rule has a readable declaration block', false);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA KEYBOARD USER CAN STILL REACH IT\n";
// ─────────────────────────────────────────────────────────────────────────────
// Hiding a focusable element permanently is its own accessibility fault: a
// keyboard user tabs to something they cannot see. The class un-hides on focus.

$check('.screen-reader-text:focus brings it back',
       (bool) preg_match('~\.screen-reader-text:focus\s*\{~', $base));
if (preg_match('~\.screen-reader-text:focus\s*\{([^}]*)\}~', $base, $m)) {
    $check('  and the un-hide actually reverses the hide',
           (bool) preg_match('~position\s*:\s*static~', $m[1])
           && (bool) preg_match('~width\s*:\s*auto~', $m[1]));
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE RULE IS NOT LEFT TO THE SKINS\n";
// ─────────────────────────────────────────────────────────────────────────────
// The fault was that the rule existed ONLY in skins. Three had it and
// twenty-eight did not. Skins may still override it -- public-base.css says so
// in its own header -- but the floor has to be in core, so a new skin, or a
// skin that never thought about it, is covered on the day it ships.

$with_rule = [];
foreach (glob($root . '/skins/*/style.css') ?: [] as $sheet) {
    if (str_contains($strip((string) file_get_contents($sheet)), 'screen-reader-text')) {
        $with_rule[] = basename(dirname($sheet));
    }
}
$total = count(glob($root . '/skins/*/style.css') ?: []);
printf("        %d of %d skins define it themselves: %s\n",
       count($with_rule), $total, implode(', ', $with_rule) ?: '(none)');
$check('core covers every skin regardless of how few define it',
       (bool) preg_match('~\.screen-reader-text\s*\{~', $base));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — core emits the label and the always-loaded core stylesheet hides it.\n";
echo "NOT PROVED HERE: the pixels. The glyph positions were measured in a\n";
echo "browser on the live page, before and after, and are in the commit\n";
echo "message. A PHP test cannot lay out a page.\n";
exit(0);

// ===== SNAPSMACK EOF =====
