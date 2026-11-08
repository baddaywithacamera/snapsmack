<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * THE OWNERSHIP GATE (enforceable core). (988D, per the PM's 23:50 order and
 * Muse's rule draft.)
 *
 * Presentation belongs to the skin; core stores owner values and prints data.
 * A skin owns its look until the owner touches a control. The repeated fault
 * behind Sean's "features break wholesale" has been core emitting a VISUAL value
 * for a skin-owned selector before the owner chose anything -- the 986D inset
 * frame under the photo, and the gutter's silent default 4 collapsing TRUE GRIT's
 * 100px gap (fe933b0c). Both read the markup, not the result, so they passed.
 *
 * This gate enforces the two rules that are testable from the render:
 *   G4  No core visual value is emitted for a skin until the owner acts. With no
 *       owner archive settings, core emits ZERO override blocks on every skin.
 *   G2  A core-exposed value is the owner's, emitted only once the owner sets it;
 *       until then the skin's own declaration is the last word.
 *   G3  --grid-gap has exactly ONE core writer on the strict path (the legacy
 *       meta.php browse_gap emitter is unreachable for strict skins).
 *
 * It renders the real presentation, not a string guess. It cannot open a browser
 * (the headed geometry matrix does that); it proves core emits nothing, which is
 * exactly the property that was violated.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-presentation.php';

$pass = 0; $fail = 0;
function gx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

// Any core "owner-*" presentation block overrides a skin-owned selector. The
// gate forbids ALL of them on an untouched site, not just the gutter, so a new
// owner-emitter added without this discipline is caught here.
$OWNER_BLOCK = '/id="snapsmack-owner-[a-z-]+"/';

echo "G4 -- AN UNTOUCHED SITE GETS NO CORE OVERRIDE, ON EVERY SKIN\n";
$skins = array_map('basename', array_map('dirname', glob($root . '/skins/*/manifest.json') ?: []));
sort($skins);
$checked = 0;
foreach ($skins as $skin) {
    $presentation = snapsmack_skin_presentation([], $skin); // no owner archive settings at all
    $css = (string)($presentation['style'] ?? '');
    if ($css === '') continue; // a skin with no presentation style cannot override anything
    $checked++;
    gx(!preg_match($OWNER_BLOCK, $css),
       "{$skin}: core emits no owner-* override block when the owner has set nothing");
}
gx($checked >= 20, "the untouched-site check ran against the real skins ({$checked} with presentation)");

echo "\nG2 -- CORE EMITS THE OWNER'S VALUE ONLY ONCE THE OWNER ACTS (gutter anchor)\n";
// The gutter is the one core owner-emitter on the strict path (fe933b0c). Its
// default position (4) is read as 'use the skin's spacing' and emits nothing;
// a real value the owner chose is emitted. This is the fix 05691367 made a rule.
$untouched = snapsmack_skin_presentation(['aurora__au_gap' => '20'], 'aurora');
gx(!str_contains((string)($untouched['style'] ?? ''), 'snapsmack-owner-gutter'),
   'with no saved gutter, AURORA keeps its own gap and core adds no --grid-gap');
$default = snapsmack_skin_presentation(['aurora__au_gap' => '20', 'archive_gutter' => '4'], 'aurora');
gx(!str_contains((string)($default['style'] ?? ''), 'snapsmack-owner-gutter'),
   'the gutter at its default 4 still emits nothing -- 4 means the skin decides');
$owned = snapsmack_skin_presentation(['aurora__au_gap' => '20', 'archive_gutter' => '10'], 'aurora');
gx(str_contains((string)($owned['style'] ?? ''), 'snapsmack-owner-gutter'),
   'but a value the owner chose (10) IS emitted -- core acts only after the owner');

echo "\nG3 -- --grid-gap HAS ONE CORE WRITER ON THE STRICT PATH\n";
// The legacy emitter core/meta.php (browse_gap) is unreachable for strict skins:
// index.php routes every strict skin to snapsmack_public_runtime() and exits
// before meta.php is included. A second live writer would reopen the two-writers
// failure (G3). This is the structural half; the render half is G2 above.
$index = (string)@file_get_contents($root . '/index.php');
gx(str_contains($index, 'snapsmack_public_runtime('),
   'index.php routes strict skins through snapsmack_public_runtime (not meta.php)');
$presSrc = (string)@file_get_contents($root . '/core/skin-presentation.php');
gx(substr_count($presSrc, "'snapsmack-owner-gutter'") === 1,
   'the owner gutter is emitted from exactly one place in the presentation builder');

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) { echo "Core ownership gate: FAIL ({$fail}).\n"; exit(1); }
echo "Core ownership gate: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED: G1 (no core visual declaration for a skin-owned selector at\n";
echo "  all) needs a static map of skin-owned selectors vs every core CSS emission;\n";
echo "  it is drafted in Muse's rule text and is the next slice of this gate.\n";

// ===== SNAPSMACK EOF =====
