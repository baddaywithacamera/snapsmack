<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1386 step (c): THE SERVER MAKES m_ FOR WEB UPLOADS, AND ONLY THOSE.
 *
 * Sean's companion rule puts desktop work on the desktop: SYBU makes m_ for
 * anything it uploads (SS-1371) and the backlog is filled by a desktop tool,
 * not a batch job on shared hosting. The server's share is the web upload
 * path, which is core/image-ingest.php.
 *
 * WHAT RUNS HERE. snap_medium_thumb_box() -- the geometry, which is where the
 * decisions are -- on real numbers. The GD calls and the INSERT need an image
 * on disk and a database, so those are checked as source, and I am saying so
 * rather than implying the whole path is proven.
 *
 * THE NO-UPSCALE RULE IS THE ONE THAT WOULD BITE SILENTLY. If m_ could come
 * out larger than a_, the srcset would offer the browser a 600w candidate that
 * is really bigger than the 900w one, and every small photograph would load
 * the wrong file -- slower than before SS-1386, with nothing on screen looking
 * wrong. Sean's library has plenty of images under 600px.
 */

require_once __DIR__ . '/../core/thumb-generator.php';

$failures = [];
$long = 600;

// --- 1. landscape, portrait, square ---------------------------------------
[$w, $h] = snap_medium_thumb_box(3000, 2000, $long);
if ([$w, $h] !== [600, 400]) {
    $failures[] = "3000x2000 gave {$w}x{$h}, expected 600x400";
}
[$w, $h] = snap_medium_thumb_box(2000, 3000, $long);
if ([$w, $h] !== [400, 600]) {
    $failures[] = "2000x3000 gave {$w}x{$h}, expected 400x600";
}
[$w, $h] = snap_medium_thumb_box(2000, 2000, $long);
if ([$w, $h] !== [600, 600]) {
    $failures[] = "a square gave {$w}x{$h}, expected 600x600";
}

// --- 2. NEVER UPSCALED ----------------------------------------------------
[$w, $h] = snap_medium_thumb_box(400, 300, $long);
if ([$w, $h] !== [400, 300]) {
    $failures[] = "a 400x300 source was resized to {$w}x{$h}; m_ must never be "
        . 'bigger than the photograph, or the srcset offers a 600w candidate '
        . 'that is larger than the 900w one and every small photograph loads '
        . 'the wrong file';
}
// Exactly at the limit on one edge is NOT smaller than the limit, so it is
// fitted rather than copied -- and fitting it is a no-op on that edge.
[$w, $h] = snap_medium_thumb_box(600, 400, $long);
if ($w !== 600 || $h !== 400) {
    $failures[] = "a 600x400 source gave {$w}x{$h}";
}

// --- 3. m_ is never larger than a_ ---------------------------------------
// The property the srcset depends on, asserted over real shapes rather than
// argued about in a comment.
$aspectLong = defined('SNAPSMACK_THUMB_ASPECT_LONG') ? SNAPSMACK_THUMB_ASPECT_LONG : 900;
foreach ([[3000, 2000], [2000, 3000], [1000, 1000], [700, 300], [300, 700],
          [601, 599], [599, 601], [120, 4000], [4000, 120], [1, 5000],
          // A 5000x1 landscape: the fitted height rounds to 0.12, and without
          // a floor of 1 imagecreatetruecolor refuses it and the upload dies.
          // Found by mutation -- every other shape here survived dropping
          // that floor on the landscape branch.
          [5000, 1]] as $shape) {
    [$sw, $sh] = $shape;
    [$mw, $mh] = snap_medium_thumb_box($sw, $sh, $long);
    [$aw, $ah] = snap_medium_thumb_box($sw, $sh, $aspectLong);
    if ($mw > $aw || $mh > $ah) {
        $failures[] = "{$sw}x{$sh}: the medium box {$mw}x{$mh} is larger than "
            . "the aspect box {$aw}x{$ah}";
    }
    if ($mw < 1 || $mh < 1) {
        $failures[] = "{$sw}x{$sh} produced a {$mw}x{$mh} box; "
            . 'imagecreatetruecolor refuses a zero edge and the upload would '
            . 'fail on a photograph that is merely very thin';
    }
}

// --- 4. nonsense in, something usable out --------------------------------
foreach ([[0, 0], [-5, 10], [10, 0]] as $shape) {
    [$mw, $mh] = snap_medium_thumb_box($shape[0], $shape[1], $long);
    if ($mw < 1 || $mh < 1) {
        $failures[] = "a {$shape[0]}x{$shape[1]} source produced a zero edge "
            . 'rather than a usable box';
    }
}

// --- 5. the web ingest path does the work and records it ------------------
// Source, because GD and the INSERT need an image and a database.
$ingest = (string)@file_get_contents(dirname(__DIR__) . '/core/image-ingest.php');
if (strpos($ingest, "'m_' . \$new_file_name") === false) {
    $failures[] = 'core/image-ingest.php writes no m_ file, so a web upload '
        . 'produces no medium thumbnail';
}
if (strpos($ingest, 'snap_medium_thumb_box(') === false) {
    $failures[] = 'the ingest repeats the geometry instead of calling the one '
        . 'function the tests above cover';
}
if (strpos($ingest, 'img_thumb_medium') === false) {
    $failures[] = 'the ingest never records the path, so the grid cannot find '
        . 'the file it just made';
}
// The path is recorded only if the file is really there. A failed write would
// otherwise store a path the grid puts in a srcset, and the browser would
// choose a 404 over a file that exists -- worse than no m_ at all.
if (!preg_match('/is_file\(\$thumb_dir \. \'m_\'/', $ingest)) {
    $failures[] = 'the ingest records the m_ path without checking the file '
        . 'exists; a failed write would put a 404 in a srcset';
}

// Arity, because adding a column to an INSERT and forgetting a placeholder is
// a fatal on every upload and the kind of thing a reader skims past.
if (preg_match('/INSERT INTO snap_images \((.*?)\) VALUES \((.*?)\)/s', $ingest, $m)) {
    $cols = count(array_filter(array_map('trim', explode(',', $m[1])), 'strlen'));
    $holes = substr_count($m[2], '?');
    if ($cols !== $holes) {
        $failures[] = "the ingest INSERT names {$cols} columns and binds "
            . "{$holes} placeholders";
    }
} else {
    $failures[] = 'could not isolate the ingest INSERT to check its arity';
}

// And the BOUND VALUES, which is a third number that has to agree. Comparing
// columns to placeholders alone passed a mutation that deleted a bound
// parameter: the SQL still balanced and every upload would have thrown
// "number of bound variables does not match".
$exec = strpos($ingest, '$stmt->execute([', strpos($ingest, 'INSERT INTO snap_images'));
$execEnd = $exec === false ? false : strpos($ingest, "
    ]);", $exec);
if ($exec === false || $execEnd === false) {
    $failures[] = 'could not isolate the ingest execute() list';
} else {
    $bound = substr_count(substr($ingest, $exec, $execEnd - $exec + 1), ",
");
    if (isset($holes) && $bound !== $holes) {
        $failures[] = "the ingest INSERT binds {$bound} values for {$holes} "
            . 'placeholders; every upload would throw';
    }
}

// --- 6. and the desktop upload paths are NOT changed ---------------------
// The owner's companion rule: SYBU makes m_ on the desktop under SS-1371. If
// the shared generator started making it for every caller, the server would be
// doing desktop work on shared hosting, which is the thing he ruled out.
$gen = (string)@file_get_contents(dirname(__DIR__) . '/core/thumb-generator.php');
$genStart = strpos($gen, 'function snapsmack_generate_thumbs(');
$genBody = $genStart === false ? '' : substr($gen, $genStart, 6000);
if ($genBody === '') {
    $failures[] = 'could not isolate snapsmack_generate_thumbs() to check it';
} elseif (strpos($genBody, "'/m_'") !== false || strpos($genBody, "'m_'") !== false) {
    $failures[] = 'the shared generator now writes m_ for every caller, which '
        . 'puts desktop-upload work on the server -- the opposite of the '
        . 'companion rule. Step (c) is web uploads only.';
}

// --- 7. THE PATH SEAN ACTUALLY POSTS THROUGH -----------------------------
// smack-post-solo.php, not just the Gallery uploader. SYBU posts here
// (tools/sybu/poster.py:493) and SYBU has been his only image source since
// 2026-08-28, so if this path makes no m_ then the photographs he adds every
// day are the ones without one and the backfill never stops growing. Both web
// paths are checked the same way, and both for arity, because adding a column
// to an INSERT and missing a placeholder is a fatal on every post.
foreach (['core/image-ingest.php', 'smack-post-solo.php'] as $pathFile) {
    $src = (string)@file_get_contents(dirname(__DIR__) . '/' . $pathFile);
    if ($src === '') {
        $failures[] = "could not read {$pathFile}";
        continue;
    }
    // THE WRITE, not just the string. Checking that "'m_' . $new_file_name"
    // appears anywhere passed a mutation that pointed imagejpeg at 'x_' and
    // left the is_file guard and the recorded path reading m_: every post
    // would then store a path to a file that was never written. All three
    // encoders are required, because a PNG or WebP upload takes a different
    // branch and would otherwise be the one that silently produces nothing.
    $writes = preg_match_all(
        '/image(?:jpeg|png|webp)\(\$m_thumb,\s*\$thumb_dir \. .m_./',
        $src);
    if ($writes !== 3) {
        $failures[] = "{$pathFile} writes the medium thumbnail to an m_ path "
            . "in {$writes} of its 3 encoder branches";
    }
    if (strpos($src, 'snap_medium_thumb_box(') === false) {
        $failures[] = "{$pathFile} repeats the geometry instead of calling the "
            . 'one function the tests above cover, so the two paths can drift';
    }
    if (!preg_match('/is_file\(\$thumb_dir \. \x27m_\x27/', $src)) {
        $failures[] = "{$pathFile} records the m_ path without checking the "
            . 'file exists; a failed write would put a 404 in a srcset';
    }
    if (strpos($src, 'img_thumb_medium') === false) {
        $failures[] = "{$pathFile} never records the path";
    }
    if (preg_match('/INSERT INTO snap_images\s*\((.*?)\)\s*VALUES\s*\((.*?)\)/s', $src, $mm)) {
        $nCols = count(array_filter(array_map('trim', explode(',', $mm[1])), 'strlen'));
        $nHoles = substr_count($mm[2], '?');
        if ($nCols !== $nHoles) {
            $failures[] = "{$pathFile}: the INSERT names {$nCols} columns and "
                . "binds {$nHoles} placeholders -- a fatal on every post";
        }
    } else {
        $failures[] = "could not isolate {$pathFile}'s INSERT to check arity";
    }
}


if ($failures) {
    throw new RuntimeException("Medium thumbnail generation contract broken:\n  - "
        . implode("\n  - ", $failures));
}

echo "Medium thumbnail: generated on web upload, never upscaled, never bigger than a_: passed.\n";
// ===== SNAPSMACK EOF =====
