<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// THE DOCK IS DRAWN TWICE AND THE TWO HALVES DISAGREED ABOUT WHO WINS.
//
//   floating, core/social-dock.php
//       $settings['social_dock_color_light'] ?? $dock['default_color_light'] ?? '#ffffff'
//       the OWNER first, then the skin's declared default.
//   strict, core/skin-view-model.php
//       $dock['color_dark'] ?? $settings['social_dock_color_dark'] ?? '#1a1a1a'
//       the SKIN first, and default_color_dark never read at all.
//
// Exactly backwards from each other, in one product. A skin on the legacy keys
// overrode the owner on strict skins and not on the floating dock; a skin on
// the new default_* keys was honoured on the floating dock and ignored on
// strict skins.
//
// WHAT IT DID TO A REAL SITE. SCROLL declares
//     {"position":"inline","color_mode":"dark","icon_style":"outline","shadow":false}
// and used car parts is the only site in the fleet that renders a dock. The
// admin hid all four controls as "(controlled by the SCROLL skin)". The strict
// renderer looked for default_color_mode, did not find it, and fell back to
// the owner's saved setting -- which the owner could not set, because the
// admin had hidden it on the skin's behalf. The skin asked for dark outline,
// the admin said the skin decides, the page rendered light solid, and nobody
// could change it from either end.
//
// THE ORDER, ruled 2026-11-24: owner setting, then default_*, then the legacy
// key, then the built-in. A skin declaration is a DEFAULT, never an override.
// That is also why the admin now locks only position.
//
// This file exists because two implementations that agree by inspection is
// precisely how they came to disagree. There is one resolver now, and these
// checks fail if a second one grows back.

$root = dirname(__DIR__);
require_once $root . '/core/social-dock-appearance.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};
$strip = static function (string $php): string {
    return (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $php);
};

// The two real manifests this turns on.
$SCROLL = ['position' => 'inline', 'color_mode' => 'dark',
           'icon_style' => 'outline', 'shadow' => false];
$ALFRED = ['position' => 'inline', 'default_color_mode' => 'dark',
           'default_icon_style' => 'solid', 'default_shadow' => false];

echo "One precedence, both dock paths\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "A SKIN'S DECLARATION APPLIES WHEN THE OWNER HAS NOT CHOSEN\n";
// ─────────────────────────────────────────────────────────────────────────────
// Both spellings, because three skins are still on the legacy one and a fix
// that only understood default_* would silently blank them.

$a = snapsmack_social_dock_appearance([], $SCROLL);
$check('SCROLL legacy color_mode dark is honoured', $a['mode'] === 'dark');
$check('  and its legacy icon_style outline', $a['icon_style'] === 'outline');
$check('  and its legacy shadow false', $a['shadow'] === false);

$b = snapsmack_social_dock_appearance([], $ALFRED);
$check('ALFRED default_color_mode dark is honoured', $b['mode'] === 'dark');
$check('  and its default_icon_style solid', $b['icon_style'] === 'solid');

// ─────────────────────────────────────────────────────────────────────────────
echo "\nBUT THE OWNER ALWAYS WINS WHEN THEY HAVE\n";
// ─────────────────────────────────────────────────────────────────────────────
// This is the half that was broken on strict skins, and the reason the admin
// stopped hiding the controls.

foreach (['SCROLL' => $SCROLL, 'ALFRED' => $ALFRED] as $name => $dock) {
    $o = snapsmack_social_dock_appearance(['social_dock_color_mode' => 'light'], $dock);
    $check("$name: owner's light beats the skin's dark", $o['mode'] === 'light');
}
$o = snapsmack_social_dock_appearance(['social_dock_icon_style' => 'solid'], $SCROLL);
$check("the owner's solid beats SCROLL's outline", $o['icon_style'] === 'solid');
$o = snapsmack_social_dock_appearance(['social_dock_shadow' => '1'], $SCROLL);
$check("the owner's shadow ON beats SCROLL's false", $o['shadow'] === true);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE VALUES THAT LOOK LIKE 'UNSET' AND ARE NOT\n";
// ─────────────────────────────────────────────────────────────────────────────
// A saved shadow of '0' and a saved opacity of 0 are real owner choices. isset
// or a truthiness test would hand both back to the skin, which is how an owner
// turns something off and watches it come back.

$o = snapsmack_social_dock_appearance(['social_dock_shadow' => '0'], []);
$check("a saved shadow of '0' means OFF, not unset", $o['shadow'] === false);
$o = snapsmack_social_dock_appearance(['social_dock_opacity' => '0'], $SCROLL);
$check('a saved opacity of 0 is kept, not replaced by 50', $o['opacity'] === 0);
$o = snapsmack_social_dock_appearance(['social_dock_color_mode' => ''], $SCROLL);
$check('an EMPTY saved value does fall through to the skin', $o['mode'] === 'dark');

// ─────────────────────────────────────────────────────────────────────────────
echo "\nBAD VALUES CANNOT REACH THE PAGE\n";
// ─────────────────────────────────────────────────────────────────────────────

$o = snapsmack_social_dock_appearance(['social_dock_color_dark' => 'red; }'], $SCROLL);
$check('a non-hex colour is replaced, not printed into CSS', $o['color'] === '#1a1a1a');
$o = snapsmack_social_dock_appearance(['social_dock_opacity' => '9999'], []);
$check('opacity is clamped to 100', $o['opacity'] === 100);
$o = snapsmack_social_dock_appearance(['social_dock_color_mode' => 'sideways'], []);
$check('an unknown mode falls back to light', $o['mode'] === 'light');

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAND NEITHER PATH KEEPS A PRECEDENCE CHAIN OF ITS OWN\n";
// ─────────────────────────────────────────────────────────────────────────────
// The actual regression guard. Both files must ASK, not decide. Comments are
// stripped first, so the narrative above a rule can never satisfy the check.

foreach (['core/skin-view-model.php', 'core/social-dock.php'] as $rel) {
    $src = $strip((string) file_get_contents($root . '/' . $rel));
    $check("$rel calls the shared resolver",
           str_contains($src, 'snapsmack_social_dock_appearance('));
    $check("  and no longer reaches for the skin's colour key itself",
           !preg_match('~\$(_dock_ovr|dock)\[.(default_)?color_(dark|light).\]~', $src));
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE ADMIN ONLY CLAIMS WHAT THE SKIN ACTUALLY OWNS\n";
// ─────────────────────────────────────────────────────────────────────────────
// It used to hide colour, icon style and shadow as "(controlled by skin)".
// They are defaults now, so hiding them left the owner with no way to change
// a value the renderer was taking from them anyway.

// DRIVEN, not read. The first version of this asserted that a list of
// skin-owned keys existed in the admin page, and a mutation that IGNORED the
// list and locked any manifest key SURVIVED it: the list was still sitting
// there, unused. Checking that a thing exists is not checking that anything
// consults it. So the decision moved into core, where a test can call it.

$owned = ['position' => 'inline', 'color_mode' => 'dark',
          'icon_style' => 'outline', 'shadow' => false];
$check('position IS the skin to own', snapsmack_social_dock_skin_owns('position', $owned));
foreach (['color_mode', 'icon_style', 'shadow', 'color_light', 'opacity'] as $k) {
    $check("  $k is NOT, even though SCROLL declares it",
           !snapsmack_social_dock_skin_owns($k, $owned));
}
$check('a skin that declares no position owns nothing',
       !snapsmack_social_dock_skin_owns('position', []));

$admin = $strip((string) file_get_contents($root . '/smack-social-dock.php'));
$check('and the admin asks core rather than deciding inline',
       str_contains($admin, 'snapsmack_social_dock_skin_owns('));

echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — one resolver, owner first, skin defaults second, legacy third.\n";
echo "STRICT ARTWORK CONTRACT: strict skins draw the shared single outline\n";
echo "sprite family. icon_style switches floating-dock artwork only and does\n";
echo "not restyle strict skins. The sprite geometry is proved separately.\n";
exit(0);

// ===== SNAPSMACK EOF =====
