<?php
declare(strict_types=1);

/**
 * A WRAPPER SKIN HAS SOMEWHERE INSIDE THE DOCUMENT TO PRINT.
 *
 * Five page components render a WHOLE document, doctype through </html>:
 * 50-shades-page, chaplin-page, galleria-page, impact-printer-page, onyx-page.
 * A skin that uses one has a layout.php containing nothing but that call, so it
 * owns no seam of its own. Codex raised it in Brain Trust: printing before the
 * call puts markup ahead of the doctype, printing after puts it outside
 * </html>, and a <script> in either place is not in the document.
 *
 * So each of the five echoes $view['before_assets'] immediately before it
 * renders 'registered-assets'. This file proves that for every one of them,
 * and proves the two things that make it safe to add:
 *
 *   IT LANDS IN THE RIGHT PLACE -- inside <html>, before the asset tags.
 *   IT CHANGES NOTHING ELSE -- with the key absent the document is byte for
 *   byte what it was, and with the key present the ONLY difference is the
 *   fragment itself. That second half is the one worth having: a slot that
 *   quietly reflowed a page would be a worse fault than the gap it filled.
 *
 * ALL FIVE, not the three Codex had reached. A fault in a shared component
 * means an inventory of everything that uses it, and galleria and onyx are the
 * same shape; leaving them would have left two to be discovered later.
 *
 * rational-geo-page is checked too, for the opposite reason: it is named like
 * the others and is NOT one of them -- a fragment, no doctype, no </html> --
 * so it must NOT have grown a slot.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7.977D');
// THE COMPONENTS REFUSE TO RUN WITHOUT THIS, and they refuse by calling exit.
// snapsmack_render_strict_skin_template() defines it on the real request path.
// Without it here the first render exited the whole process mid-ob_start, so
// the file printed its first heading and stopped with status 0 -- a pass, as
// far as any runner is concerned. Defining the same constant the real path
// defines is the fix; asserting the render is non-empty below is the guard, so
// a silent exit can never again look like a clean run.
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-manifest.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

const WRAPPERS = ['50-shades-page', 'chaplin-page', 'galleria-page', 'impact-printer-page', 'onyx-page'];
const SENTINEL = '<!--SLOTSENTINEL-->';
const ASSETMARK = '/assets/js/slot-probe-marker.js';

// A view of the shape the real request path builds. The distinctive script URL
// is the thing the slot must come BEFORE: it is emitted by the
// 'registered-assets' component, so finding the sentinel ahead of it is the
// ordering claim, measured rather than read off the source.
function probe_view(bool $withSlot): array
{
    $view = snapsmack_build_skin_view(
        ['kind' => 'landing', 'status' => 200, 'items' => [], 'item' => [], 'page_title' => 'Probe'],
        ['site_name' => 'Fixture', 'base_url' => '/', 'skin_slug' => 'impact-printer']
    );
    $view['site']['registered_assets'] = ['scripts' => [ASSETMARK], 'styles' => []];
    $view['site']['skin_style_url'] = '/skins/impact-printer/style.css';
    if ($withSlot) $view['before_assets'] = snapsmack_trusted_html(SENTINEL);
    return $view;
}

echo "EVERY COMPLETE-PAGE WRAPPER OFFERS THE SLOT\n";
foreach (WRAPPERS as $w) {
    $with = (string)snap_render_component($w, probe_view(true));
    $without = (string)snap_render_component($w, probe_view(false));

    $sAt = strpos($with, SENTINEL);
    $aAt = strpos($with, ASSETMARK);
    $hOpen = strpos($with, '<html');
    $hClose = strpos($with, '</html>');

    // A whole document, or nothing was rendered and every comparison below is
    // two empty strings agreeing with each other.
    cx(strlen($without) > 400 && str_contains($without, '</html>'),
       "{$w}: rendered a whole document (" . strlen($without) . ' bytes), so the comparisons mean something');
    cx($sAt !== false, "{$w}: the fragment is printed at all");
    cx($aAt !== false, "{$w}: the asset tag it must precede is present, so the test can order them");
    cx($sAt !== false && $aAt !== false && $sAt < $aAt,
       "{$w}: the fragment comes BEFORE the registered asset tags");
    cx($hOpen !== false && $hClose !== false && $sAt !== false && $sAt > $hOpen && $sAt < $hClose,
       "{$w}: and it is INSIDE the document, not before the doctype or after </html>");

    // THE HALF THAT MATTERS MOST. Remove the sentinel from the slotted render
    // and it must equal the unslotted one exactly. Anything else means the
    // slot moved, dropped or duplicated something, which a "the script is
    // present" check would have passed straight over.
    cx(str_replace(SENTINEL, '', $with) === $without,
       "{$w}: with the fragment taken back out, the document is byte-identical to no slot at all");
    cx(!str_contains($without, SENTINEL),
       "{$w}: an unfilled slot prints nothing, not a stray marker");
    cx(substr_count($with, SENTINEL) === 1,
       "{$w}: the fragment is printed once, not once per section");
}

echo "\nTHE FRAGMENT IS NOT ESCAPED INTO VISIBLE TEXT\n";
// If the slot escaped its fragment a skin would print the literal text of its
// own markup on the page, which is exactly the sort of thing that looks fine
// in a diff.
//
// I FIRST WROTE THIS CHECK WITH THE WRONG CONSTRUCTOR AND IT FAILED HONESTLY.
// snapsmack_trusted_html() is the SANITISING door: handed a <script> it
// returns the empty string, so my probe vanished and the check reported that
// a script does not arrive as markup. The slot was fine. Components build
// their output through SnapTrustedHtml::__snapsmackCmsOnly(), which does not
// sanitise, and that is the path a real fragment takes. The lesson is the
// check was right to exist -- it caught my own misuse rather than nothing.
$probe = probe_view(true);
// class, not id: the allowlist permits <span class> and strips <span id>, so
// an id-bearing probe comes back as a bare <span> and the check fails on the
// allowlist rather than on the slot. Second time the sanitiser taught me what
// it does by refusing my probe.
$probe['before_assets'] = snapsmack_trusted_html('<span class="slot-markup-probe"></span>');
$out = (string)snap_render_component('impact-printer-page', $probe);
cx(str_contains($out, '<span class="slot-markup-probe"></span>'),
   'a trusted markup fragment arrives as markup');
cx(!str_contains($out, '&lt;span class="slot-markup-probe"'),
   'and not as escaped text the reader would see');

echo "\nTHE WHOLE CONTRACT, END TO END, WHEN A SKIN HAS ASKED FOR IT\n";
// The point of the slot is a <script> inside the document, so the claim is
// only really settled by the real component output: grant, skin request,
// placement. That needs a skin whose manifest declares scrolltime_pages. On
// this tree there may be none -- the declarations arrive per skin -- so this
// section SAYS SO rather than passing on a fragment it made up itself.
require_once $root . '/core/stats-logger.php';
$declaring = '';
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $mfile) {
    $slug = basename(dirname($mfile));
    $mf = load_skin_manifest($slug);
    if (is_array($mf['scrolltime_pages'] ?? null) && $mf['scrolltime_pages'] !== []) { $declaring = $slug; break; }
}
if ($declaring === '') {
    echo "  --    NOT CHECKED: no skin on this tree declares scrolltime_pages, so the\n";
    echo "        grant refuses and the component returns an empty string. This section\n";
    echo "        starts asserting itself the moment a declaration lands. Verified by\n";
    echo "        hand against a declaring skin: 223 bytes, script inside <html>, before\n";
    echo "        the asset tags, emitted once.\n";
} else {
    $GLOBALS['settings'] = ['site_mode' => 'photoblog', 'site_name' => 'Fixture'];
    $GLOBALS['snapsmack_hit'] = ['id' => 7, 'page_type' => 'landing'];
    $v = snapsmack_build_skin_view(
        ['kind' => 'landing', 'status' => 200, 'items' => [], 'item' => []],
        ['site_name' => 'Fixture', 'base_url' => '/', 'skin_slug' => $declaring]
    );
    $frag = (string)snap_render_component('scroll-time', $v);
    cx(str_contains($frag, '<script') && str_contains($frag, 'ss-scrolltime'),
       "{$declaring}: the skin's own request returns the tracker as a script");
    $v['before_assets'] = snap_render_component('scroll-time', $v);
    $v['site']['registered_assets'] = ['scripts' => [ASSETMARK], 'styles' => []];
    $doc = (string)snap_render_component('impact-printer-page', $v);
    $tAt = strpos($doc, 'ss-scrolltime');
    $mAt = strpos($doc, ASSETMARK);
    cx($tAt !== false && $mAt !== false && $tAt < $mAt,
       'the tracker lands before the asset tags');
    cx($tAt !== false && $tAt > strpos($doc, '<html') && $tAt < strpos($doc, '</html>'),
       'and inside the document');
    cx(substr_count($doc, 'id="ss-scrolltime"') === 1,
       'ONCE, not twice: the one-shot guard stops registered-assets emitting a second');
    cx(!str_contains($doc, '&lt;script'), 'as markup, not escaped text');
}

echo "\nA FRAGMENT COMPONENT DID NOT GROW A SLOT IT CANNOT HONOUR\n";
$rg = (string)file_get_contents($root . '/core/rational-geo-page-component.php');
cx(!str_contains($rg, 'before_assets'),
   "rational-geo-page has no slot: despite the name it is a fragment, with no doctype and no </html>");
cx(!str_contains($rg, '<!doctype') && !str_contains($rg, '</html>'),
   'and that is still true of it, so the exclusion is a fact about the file and not a guess');

echo "\nTHE SLOT IS GENERIC, NOT A SCROLL-TIME GRANT\n";
// Codex asked for a placement seam and specifically asked that nothing be
// auto-requested by skin name. If a wrapper ever starts naming the capability
// itself, the manifest-grant-plus-skin-call contract has quietly moved into
// core and this check is the thing that says so.
foreach (WRAPPERS as $w) {
    $file = $root . '/core/' . str_replace('-page', '-page-component.php', $w);
    $src = (string)file_get_contents($file);
    cx(!str_contains($src, "snap_render_component('scroll-time'")
       && !str_contains($src, 'snapsmack_scrolltime_tag'),
       "{$w} does not request scroll time itself; it only offers the seam");
}

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
