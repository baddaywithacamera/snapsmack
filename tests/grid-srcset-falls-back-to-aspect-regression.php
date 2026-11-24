<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1386 step (b): THE GRID OFFERS m_ AND a_, AND SERVES a_ ALONE WHEN
 * THERE IS NO m_.
 *
 * The fallback is not a nicety here, it is the normal case. Every photograph
 * on Sean's fleet has no m_ derivative until the desktop backfill runs, so the
 * state this has to be right in FIRST is the one where the map is empty. The
 * spec says it plainly: never a 404, never a blank tile.
 *
 * WHAT THIS EXECUTES. snap_image_srcset_map() on real list shapes, and the
 * image component itself through snap_render_component(), with the map
 * prepared and with it empty. It is not a source check: the component's output
 * is read.
 *
 * WHAT IT CANNOT REACH, said plainly: the six repository SELECTs that carry
 * img_thumb_medium, and the capability probe that decides whether to name the
 * column at all, need a database. They are not executed here. The probe's
 * shape is checked as source only, which is weaker than I would like, and the
 * thing that would actually prove it is a rendered grid on a site without the
 * column -- Codex's gate, with a fixture missing m_, which the PM has asked
 * him for.
 */

require_once __DIR__ . '/../core/constants.php';
require_once __DIR__ . '/../core/image-srcset.php';
require_once __DIR__ . '/../core/skin-render-helpers.php';

$failures = [];

$aspect = 'uploads/2026/11/thumbs/a_photo.jpg';
$medium = 'uploads/2026/11/thumbs/m_photo.jpg';

// --- 1. both paths present: two candidates, smaller one first -------------
// A 3:2 LANDSCAPE, where the long edge IS the width.
$map = snap_image_srcset_map([[['img_thumb_aspect' => $aspect,
                               'img_thumb_medium' => $medium,
                               'img_width' => 3000, 'img_height' => 2000]]]);
if (!isset($map[$aspect])) {
    $failures[] = 'the map is not keyed by the a_ path, which is the url every '
        . 'skin passes the image component -- so no lookup would ever hit';
} else {
    $expected = $medium . ' 600w, ' . $aspect . ' 900w';
    if ($map[$aspect] !== $expected) {
        $failures[] = "the srcset is '{$map[$aspect]}', expected '{$expected}'";
    }
}

// --- 1b. A PORTRAIT, WHICH IS WHERE THE FIRST VERSION LIED ---------------
// The constants are LONG-EDGE limits. A 2:3 portrait m_ is 400px wide, not
// 600, and its a_ is 600px wide, not 900. Advertising the narrow file as 600w
// invites the browser to pick it for a 600px slot and upscale it, which is a
// blurry photograph. Codex found this in the rendered gate.
$pMap = snap_image_srcset_map([[['img_thumb_aspect' => $aspect,
                                 'img_thumb_medium' => $medium,
                                 'img_width' => 2000, 'img_height' => 3000]]]);
$pExpected = $medium . ' 400w, ' . $aspect . ' 600w';
if (($pMap[$aspect] ?? '') !== $pExpected) {
    $failures[] = "a 2:3 portrait advertised '" . ($pMap[$aspect] ?? '')
        . "', expected '{$pExpected}'. The w descriptor is the candidate's "
        . 'intrinsic pixel WIDTH, never the long-edge limit it was generated '
        . 'under, or the browser picks an undersized file and blurs it.';
}

// A square: long edge is the width either way.
$sMap = snap_image_srcset_map([[['img_thumb_aspect' => $aspect,
                                 'img_thumb_medium' => $medium,
                                 'img_width' => 2400, 'img_height' => 2400]]]);
if (($sMap[$aspect] ?? '') !== $medium . ' 600w, ' . $aspect . ' 900w') {
    $failures[] = "a square advertised '" . ($sMap[$aspect] ?? '') . "'";
}

// --- 1c. no dimensions, no descriptor -----------------------------------
// There is no honest w without them, and guessing is what the bug was.
foreach ([[], ['img_width' => 0, 'img_height' => 0],
          ['img_width' => 3000]] as $dims) {
    $row = array_merge(['img_thumb_aspect' => $aspect,
                        'img_thumb_medium' => $medium], $dims);
    if (snap_image_srcset_map([[$row]]) !== []) {
        $failures[] = 'a row without usable source dimensions was given a '
            . 'srcset anyway, so its w descriptors are guesses';
    }
}

// --- 1d. a photograph smaller than both limits ---------------------------
// It is copied at native size, so m_ and a_ are the same image. Offering it
// twice at one width is noise, and offering it twice at two widths is a lie.
$tiny = snap_image_srcset_map([[['img_thumb_aspect' => $aspect,
                                 'img_thumb_medium' => $medium,
                                 'img_width' => 400, 'img_height' => 300]]]);
if ($tiny !== []) {
    $failures[] = 'a photograph smaller than both limits got a srcset of two '
        . "identical files: '" . reset($tiny) . "'";
}

// --- 1e. the advertised width is the width the generator writes ----------
// The property that matters, asserted over real shapes rather than argued.
foreach ([[3000, 2000], [2000, 3000], [2400, 2400], [4000, 1200], [1200, 4000],
          [900, 1800], [1801, 900]] as $shape) {
    [$sw, $sh] = $shape;
    $row = ['img_thumb_aspect' => $aspect, 'img_thumb_medium' => $medium,
            'img_width' => $sw, 'img_height' => $sh];
    $got = snap_image_srcset_map([[$row]])[$aspect] ?? '';
    if ($got === '') continue;              // refused, which is allowed
    [$mBox] = snap_medium_thumb_box($sw, $sh, 600);
    [$aBox] = snap_medium_thumb_box($sw, $sh, 900);
    if ($got !== $medium . ' ' . $mBox . 'w, ' . $aspect . ' ' . $aBox . 'w') {
        $failures[] = "{$sw}x{$sh} advertised '{$got}', which is not the box "
            . "the generator writes ({$mBox}w and {$aBox}w)";
    }
}

// --- 2. no m_ path: absent from the map, and that is correct --------------
$dims = ['img_width' => 3000, 'img_height' => 2000];
$none = snap_image_srcset_map([[['img_thumb_aspect' => $aspect] + $dims]]);
if ($none !== []) {
    $failures[] = 'a photograph with no m_ derivative was given a srcset '
        . 'anyway, which would point the browser at a file that does not exist';
}
$blank = snap_image_srcset_map([[['img_thumb_aspect' => $aspect,
                                  'img_thumb_medium' => ''] + $dims]]);
if ($blank !== []) {
    $failures[] = 'an empty img_thumb_medium was treated as a path';
}
// A site whose column was never added returns no key at all, not even null.
$missing = snap_image_srcset_map([[['img_thumb_aspect' => $aspect,
                                    'img_width' => 3000,
                                    'img_height' => 2000]]]);
if ($missing !== []) {
    $failures[] = 'a row from a site without the column produced a srcset';
}

// --- 3. several lists, the shapes the runtime hands it --------------------
$many = snap_image_srcset_map([
    [['img_thumb_aspect' => 'a1', 'img_thumb_medium' => 'm1'] + $dims], // items
    [['img_thumb_aspect' => 'a2', 'img_thumb_medium' => 'm2'] + $dims], // a row
    [],                                                            // empty row
    ['not an item'],
]);
if (count($many) !== 2) {
    $failures[] = 'expected 2 candidates across the lists, got ' . count($many);
}

// --- 4. THE COMPONENT, with the map prepared ------------------------------
snap_image_srcset_prepare([$aspect => $medium . ' 600w, ' . $aspect . ' 900w']);
$withSrcset = (string)snap_render_component('image',
    ['url' => $aspect, 'alt' => 'A photograph']);
if (strpos($withSrcset, 'srcset="') === false) {
    $failures[] = 'the image component printed no srcset with a prepared map';
}
if (strpos($withSrcset, $medium) === false) {
    $failures[] = 'the m_ candidate is not in the tag';
}
// The LEADING SPACE matters. Without it this passed against a tag that
// printed data-sizes instead, because 'sizes="' is a substring of it -- found
// by mutation, which is the only way I would have known.
if (strpos($withSrcset, ' sizes="') === false) {
    $failures[] = 'srcset was printed with no sizes, so the browser has to '
        . 'assume 100vw and will choose the LARGER file on every tile -- the '
        . 'opposite of what SS-1386 is for';
}
if (strpos($withSrcset, 'src="' . $aspect . '"') === false) {
    $failures[] = 'the plain src is no longer the a_ path, so a browser that '
        . 'ignores srcset gets nothing it can use';
}
if (strpos($withSrcset, 'loading="lazy"') === false) {
    $failures[] = 'lazy loading was lost';
}

// --- 5. THE COMPONENT, with nothing prepared: today's tag, unchanged -----
snap_image_srcset_prepare([]);
$plain = (string)snap_render_component('image',
    ['url' => $aspect, 'alt' => 'A photograph']);
if (strpos($plain, ' srcset="') !== false || strpos($plain, ' sizes="') !== false) {
    $failures[] = 'the component printed a srcset with nothing prepared';
}
if (strpos($plain, 'src="' . $aspect . '"') === false) {
    $failures[] = 'the fallback tag does not serve the a_ path';
}

// A photograph on the page but not in the map -- the mixed state the fleet
// will be in while the backfill runs -- gets the plain tag, not a broken one.
snap_image_srcset_prepare(['uploads/other/thumbs/a_x.jpg' => 'x 600w, y 900w']);
$other = (string)snap_render_component('image', ['url' => $aspect, 'alt' => '']);
if (strpos($other, ' srcset="') !== false) {
    $failures[] = "a photograph absent from the map borrowed another's srcset";
}
snap_image_srcset_prepare([]);

// --- 5b. sizes is a real length, NEVER auto, and lazy ---------------------
//
// THIS PIN WAS REVERSED ON 24 NOVEMBER 2026, and the reason is written here
// rather than quietly edited, because a test made green by editing the test
// is the thing this repo fears most.
//
// It used to require 'auto, 100vw', and that was honest work: Codex's browser
// gate measured it at 0 failures across 30 skins and 90 viewport cases, and
// the fixed-breakpoint string it replaced produced 396 mis-hinted tiles and
// 12 undersized m_ selections in RATIONAL GEO and SCROLL. The gate measured
// candidate choice and transfer size. It did not measure LAYOUT, and that is
// where 'auto' does its damage.
//
// Chromium applies size containment -- contain:size, default
// contain-intrinsic-size 300px by 150px -- to any lazily-loaded img carrying
// sizes="auto". contain:size sizes an element as if it had NO CONTENTS, so
// the photograph is ignored and every grid that takes its row height from the
// image locks at 2:1. Measured on the live foundtextures.ca archive: 24 tiles
// at 300x150 while the images were 300x225 and 300x200, all loaded, with
// containment set by no CSS rule. Width/height attributes, height:auto,
// contain:none and a per-image CSS aspect-ratio were each driven against it
// and changed nothing; removing 'auto' fixed it on the spot.
//
// So the old rationale is not wrong about bytes, it is incomplete. Winning
// the saving back needs a mechanism that does not rely on 'auto'. Until then
// this pins the opposite: a real length, and never the keyword.
snap_image_srcset_prepare([$aspect => $medium . ' 400w, ' . $aspect . ' 600w']);
$measured = (string)snap_render_component('image', ['url' => $aspect, 'alt' => '']);
if (preg_match('/ sizes="([^"]*)"/', $measured, $_m) !== 1) {
    $failures[] = 'no sizes attribute on an image that has a srcset';
} elseif (preg_match('/(^|[\s,])auto([\s,]|$)/i', $_m[1])) {
    $failures[] = 'the sizes value contains the auto keyword (' . $_m[1]
        . '). On a lazily-loaded image that makes the browser apply size '
        . 'containment with a 300x150 placeholder, and every grid that sizes '
        . 'its rows from the image locks at 2:1. No CSS can override it; it '
        . 'broke the live foundtextures archive. See the note above.';
}
unset($_m);
// sizes=auto is defined only for lazily-loaded images. Without lazy it is
// ignored, the browser falls back to 100vw and picks the largest candidate --
// no saving at all, and nothing on screen to show it.
if (strpos($measured, 'loading="lazy"') === false) {
    $failures[] = 'srcset with sizes=auto is emitted on a tag that is not '
        . 'lazily loaded, so the auto hint is ignored and every tile takes '
        . 'the largest candidate';
}
snap_image_srcset_prepare([]);

// --- 6. the sizes value ships outside constants.php ----------------------
$srcsetFile = (string)@file_get_contents(dirname(__DIR__) . '/core/image-srcset.php');
if (strpos($srcsetFile, "if (!defined('SNAPSMACK_GRID_IMAGE_SIZES'))") === false) {
    $failures[] = 'SNAPSMACK_GRID_IMAGE_SIZES has no shipping fallback, so a '
        . 'site whose constants.php predates SS-1386 would emit srcset with '
        . 'no sizes at all';
}

// --- 7. the column is ASKED FOR, never assumed ---------------------------
// Source only, and I am saying so: this needs a database to execute.
$repo = (string)@file_get_contents(dirname(__DIR__) . '/core/public-repository.php');
if (strpos($repo, 'FROM snap_images WHERE 0') === false) {
    $failures[] = 'the repository does not probe whether img_thumb_medium is '
        . 'selectable. The grid SELECT lists are explicit, so naming the '
        . 'column on a site that lacks it makes every archive page Unknown '
        . 'column -- on 25 live sites at once.';
}
// The probe's own body, isolated. A file-wide search for INFORMATION_SCHEMA
// finds the COMMENT that explains why it is not used, which is why this reads
// the method rather than the file.
$probeStart = strpos($repo, 'private function imagesColumnSelectable(');
$probeEnd = $probeStart === false ? false : strpos($repo, "
    }", $probeStart);
$probe = ($probeStart !== false && $probeEnd !== false)
    ? substr($repo, $probeStart, $probeEnd - $probeStart) : '';
if ($probe === '') {
    $failures[] = 'could not isolate imagesColumnSelectable() to check it';
} else {
    if (strpos($probe, 'INFORMATION_SCHEMA') !== false) {
        $failures[] = 'the probe uses INFORMATION_SCHEMA, which is MySQL and '
            . 'MariaDB only; on any other engine it throws and reports the '
            . 'column absent for the wrong reason';
    }
    if (strpos($probe, 'FROM snap_images WHERE 0') === false) {
        $failures[] = 'the probe does not ask snap_images the question that '
            . 'matters, which is whether a query naming the column can be '
            . 'prepared at all';
    }
    if (strpos($probe, 'looksLikeUnknownColumn') === false) {
        $failures[] = 'the probe treats any failure as a missing column, so a '
            . 'dropped connection would silently turn the m_ candidate off';
    }
}
if (substr_count($repo, '{$med}') !== 6) {
    $failures[] = 'expected the guarded column in 6 grid queries, found '
        . substr_count($repo, '{$med}');
}
// Every query that names it must declare it first, or PHP interpolates an
// undefined variable into SQL.
foreach (['mediumThumbSelect(\'\')', 'mediumThumbSelect(\'i.\')'] as $call) {
    if (strpos($repo, $call) === false) {
        $failures[] = "no call to {$call}, so one of the aliased and "
            . 'unaliased query groups cannot be getting the column';
    }
}
if (substr_count($repo, '$med = $this->mediumThumbSelect(') !== 6) {
    $failures[] = 'there are ' . substr_count($repo, '$med = $this->mediumThumbSelect(')
        . ' declarations of $med for 6 uses; a query that interpolates it '
        . 'without declaring it would put the word null into its SELECT list';
}

if ($failures) {
    throw new RuntimeException("Grid srcset contract broken:\n  - "
        . implode("\n  - ", $failures));
}

echo "Grid srcset: m_ offered when it exists, a_ alone when it does not: passed.\n";
// ===== SNAPSMACK EOF =====
