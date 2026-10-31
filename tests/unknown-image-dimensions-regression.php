<?php
declare(strict_types=1);
/**
 * A photograph with no stored dimensions gets the shape the code asks for.
 *
 * WHAT WAS WRONG (SS-1117, child of SS-1046). Two layout paths read the stored
 * pixel size like this:
 *
 *     $width  = max(1, (int)($item['img_width']  ?? 3));
 *     $height = max(1, (int)($item['img_height'] ?? 2));
 *
 * ?? is null-coalescing and the schema says img_width int DEFAULT '0'
 * (database/schema/snapsmack_canonical.sql:111-112). So on a real install the
 * key is PRESENT and holds 0, ?? never fires, max(1, 0) gives 1, and the tile
 * comes out 1/1. The 3/2 the code asks for is unreachable. 24 published
 * photographs on photowalk.ing have 0x0, measured, so this is not theoretical.
 *
 * Meanwhile the shared helpers already treat 0 as UNKNOWN and say nothing:
 * core/skin-render-helpers.php:313 writes data-w only when the value is > 0,
 * and core/mayhem-data.php:156 computes an aspect only when both are. So the
 * codebase held two different meanings for the same zero.
 *
 * ONE HONEST LIMIT ON THE IMPACT, because I reported this as affecting both
 * paths and it does not. The justified path's declared fallback is 400/400,
 * which is square -- the same shape the 1/1 bug produced. So nothing VISIBLE
 * changes there; only GLIDE, whose fallback is 3/2, drew a square where it
 * asked for a landscape. The justified path is included anyway because the
 * rule now lives in one function, and a fallback somebody changes later would
 * otherwise reintroduce the fault silently.
 *
 * Run:  php tests/unknown-image-dimensions-regression.php
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
$failures = [];
function dim_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

require_once $root . '/core/public-controller.php';

// ── The rule itself, in one place ──────────────────────────────────────────

dim_test(function_exists('snapsmack_layout_dimensions'),
    'snapsmack_layout_dimensions() has gone, so the two layout paths are each '
  . 'deciding what a zero means again');

if (function_exists('snapsmack_layout_dimensions')) {
    // Real dimensions pass through untouched. This is the case that must not
    // regress: almost every photograph has them.
    dim_test(snapsmack_layout_dimensions(['img_width' => 6000, 'img_height' => 4000], 3, 2)
             === [6000, 4000],
        'real stored dimensions no longer pass through unchanged');

    foreach ([
        'both zero -- the schema default, and what 24 live rows hold'
            => ['img_width' => 0, 'img_height' => 0],
        'the keys are absent'
            => [],
        'the keys are null'
            => ['img_width' => null, 'img_height' => null],
        'only the width is known, so the ratio is still unknowable'
            => ['img_width' => 6000, 'img_height' => 0],
        'only the height is known'
            => ['img_width' => 0, 'img_height' => 4000],
        'a negative value, which no photograph has and a bad import might'
            => ['img_width' => -6000, 'img_height' => -4000],
        'a non-numeric value'
            => ['img_width' => 'wide', 'img_height' => 'tall'],
    ] as $what => $item) {
        dim_test(snapsmack_layout_dimensions($item, 3, 2) === [3, 2],
            'unknown dimensions did not fall back to the declared shape when '
          . $what . ': got ' . json_encode(snapsmack_layout_dimensions($item, 3, 2)));
    }

    // And it never returns the 1/1 the bug produced, whatever it is handed.
    foreach ([[0, 0], [3, 2], [400, 400]] as $fallback) {
        $got = snapsmack_layout_dimensions(['img_width' => 0, 'img_height' => 0],
                                           $fallback[0], $fallback[1]);
        dim_test($got === $fallback,
            'the fallback the caller asked for was not the one returned: asked '
          . json_encode($fallback) . ', got ' . json_encode($got));
    }
}

// ── GLIDE asks for 3/2, and that is now what an unknown tile gets ─────────
//
// The glide branch sits inside snapsmack_public_runtime(), which needs a
// database and cannot be executed here, so the CALL is read off the source
// with comments stripped -- the prose above quotes the old code, and a check
// that can match its own explanation proves nothing.

$code = '';
foreach (token_get_all((string) file_get_contents($root . '/core/public-controller.php')) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $code .= is_array($t) ? $t[1] : $t;
}

dim_test(!preg_match('/max\s*\(\s*1\s*,\s*\(int\)\s*\(\s*\$item\s*\[\s*.img_(width|height)/', $code),
    'a layout path is back to max(1, (int)$item[img_width ...]), which turns a '
  . 'stored 0 into one pixel and makes the declared fallback unreachable');

// EACH CALL SITE IS PINNED BY ITS OWN ASSIGNMENT TARGET, not merely by the
// numbers it passes. The first version of this asked only whether the source
// contained "snapsmack_layout_dimensions($item, 3, 2)" anywhere -- and since
// TWO sites pass 3 and 2, changing the glide one to (1, 1) left the assertion
// green because it matched the other site instead. The mutation proof caught
// it. A check that can be satisfied by something other than the thing it
// names is not a check.
dim_test((bool) preg_match('/\[\s*\$width\s*,\s*\$height\s*\]\s*=\s*'
    . 'snapsmack_layout_dimensions\s*\(\s*\$item\s*,\s*3\s*,\s*2\s*\)/', $code),
    'the glide branch no longer asks for its declared 3/2 fallback -- it is the '
  . 'only site that assigns to [$width, $height] with 3 and 2');

dim_test((bool) preg_match('/\[\s*\$width\s*,\s*\$height\s*\]\s*=\s*'
    . 'snapsmack_layout_dimensions\s*\(\s*\$item\s*,\s*400\s*,\s*400\s*\)/', $code),
    'the justified-rows path no longer asks for its declared 400/400 fallback');

// THE THIRD SITE, which the assertion above found rather than a reading did.
// presentation_width/presentation_height carried the same max(1, ... ?? 3)
// shape, so every grid-frame skin got a square where the line asks for 3/2.
// [^;]* rather than .*? so the match cannot leave the statement. With .*? and
// /s it wandered down to the glide call site and matched ITS 3 and 2, so
// changing this site's fallback to (1, 1) left the assertion green. Found by
// the mutation proof, which is twice in one file that a loose pattern matched
// the wrong call.
dim_test((bool) preg_match('/presentation_width[^;]*presentation_height[^;]*=\s*'
    . 'snapsmack_layout_dimensions\s*\(\s*\$item\s*,\s*3\s*,\s*2\s*\)/', $code),
    'the presentation_width/presentation_height pair no longer goes through the '
  . 'shared rule with its declared 3/2, so a stored 0 is one pixel there again');

dim_test(substr_count($code, 'snapsmack_layout_dimensions(') >= 4,
    'the helper is defined but called fewer than three times, so at least one '
  . 'of the three layout paths is deciding for itself again (found '
  . substr_count($code, 'snapsmack_layout_dimensions(') . ' mentions, which '
  . 'includes the definition)');

// ── The justified path, executed end to end ───────────────────────────────

if (function_exists('snapsmack_justified_rows')) {
    // It returns ['rows' => [...], 'total_pages' => ..., ...], not a bare list
    // of rows -- iterating the top level walks total_pages as though it were a
    // row and finds nothing, which is what the first version of this did.
    $result = snapsmack_justified_rows([
        ['img_width' => 6000, 'img_height' => 4000],   // 3:2 landscape
        ['img_width' => 0,    'img_height' => 0],      // unknown
    ], ['justified_row_height' => 240, 'main_canvas_width' => 4000], 1);

    $flat = [];
    foreach (($result['rows'] ?? []) as $row) {
        foreach (($row['items'] ?? []) as $it) $flat[] = $it;
    }

    dim_test(count($flat) === 2, 'the justified path lost an item: ' . count($flat));
    if (count($flat) === 2) {
        dim_test(abs($flat[0]['presentation_aspect'] - 1.5) < 0.0001,
            'a 6000x4000 photograph is no longer laid out at 3:2: '
          . var_export($flat[0]['presentation_aspect'], true));
        dim_test(abs($flat[1]['presentation_aspect'] - 1.0) < 0.0001,
            'an unknown photograph on the justified path is no longer the '
          . 'declared 400/400: ' . var_export($flat[1]['presentation_aspect'], true));
    }
}

// ── And the shared helpers still mean what they meant ─────────────────────
//
// This is the convention the fix was aligned TO. If it changes, the layout
// paths are no longer consistent with it and somebody should know.

$helpers = (string) file_get_contents($root . '/core/skin-render-helpers.php');
dim_test((bool) preg_match('/\(int\)\(\$item\[.img_width.\]\s*\?\?\s*0\)\s*>\s*0/', $helpers),
    'core/skin-render-helpers.php no longer treats a non-positive img_width as '
  . 'unknown, so the convention this fix was aligned to has moved');

if ($failures) {
    fwrite(STDERR, "FAIL unknown image dimensions (" . count($failures) . ")\n  - "
        . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "PASS a photograph with no stored dimensions gets the shape the code asks for\n";
// ===== SNAPSMACK EOF =====
