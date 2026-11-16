<?php
/**
 * SNAPSMACK - GRID IMAGE SRCSET (SS-1386)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * Archive and landing grids serve the ~600px m_ derivative to normal tiles and
 * the 900px a_ derivative where tiles really paint large, by handing the
 * browser both and letting it choose. SS-1373 measured 1,536,000 bytes of
 * thumbnails against a 160,000 budget on 50 SHADES; this is the half of the
 * answer that is not "make a smaller file".
 *
 * WHY THE SKINS ARE NOT TOUCHED. Every skin already calls
 * snap_render_component('image', ['url' => ..., 'alt' => ...]). Adding a
 * srcset argument would mean editing around thirty layouts, bumping thirty
 * manifests, repackaging and reinstalling thirty skins per site -- and
 * OPAUDIT 025 is the record of what that delivers in practice, which is
 * almost nothing. So the preparation is READ by the component rather than
 * passed to it, exactly as snap_community_block() is. A CMS deploy is the
 * whole delivery.
 *
 * The map is keyed by the URL the skin will pass, which is the a_ path. A
 * photograph with no m_ derivative is simply absent from it, and the component
 * emits a plain src: the documented fallback, never a 404 and never a blank
 * tile. Most photographs on Sean's fleet are in that state until the desktop
 * backfill runs.
 */

// The sizes attribute. Declared here as well as in core/constants.php because
// constants.php is a protected path that an update does not overwrite, so a
// constant defined only there never reaches a site that updates.
//
// THIS DEFAULT IS NOT MEASURED. It describes the common grid -- roughly full
// width on a phone, half on a tablet, a third on a desktop -- and it is the
// value Codex's bytes gate should test and correct per skin. STANLEY and
// WRITING WITH IMPACT paint tiles much larger than a third of the viewport
// and should end up choosing a_; that is the thing to check, not assume.
if (!defined('SNAPSMACK_GRID_IMAGE_SIZES')) {
    define('SNAPSMACK_GRID_IMAGE_SIZES',
        '(max-width: 700px) 100vw, (max-width: 1200px) 50vw, 33vw');
}

if (!function_exists('snap_image_srcset_prepare')) {

/** url => srcset string, for the current request only. */
$GLOBALS['_snap_image_srcset'] = [];

/**
 * Record the candidates for one request's worth of photographs.
 *
 * @param array $map url => srcset string. Replaces anything already held, so
 *                   a second page in one process cannot inherit the first's.
 */
function snap_image_srcset_prepare(array $map): void {
    $GLOBALS['_snap_image_srcset'] = $map;
}

/**
 * The srcset for a URL, or '' when there is none.
 *
 * '' is the normal answer, not an error: it means this photograph has no m_
 * derivative yet, or the page is not a grid.
 */
function snap_image_srcset(string $url): string {
    $map = $GLOBALS['_snap_image_srcset'] ?? [];
    return is_array($map) ? (string)($map[$url] ?? '') : '';
}

/**
 * Build the map from a prepared response's photograph lists.
 *
 * Takes the lists rather than the response so it does not have to know which
 * keys a controller used; snap_tile_like_lists() does the same job for hearts
 * and the two were written to be read together.
 *
 * An item contributes a candidate only when it has BOTH paths. The a_ path is
 * the key because that is what the skin passes as the component's url; if a
 * skin ever passes the full img_file instead, that photograph is absent from
 * the map and serves one file, which is today's behaviour.
 */
function snap_image_srcset_map(array $lists): array {
    require_once __DIR__ . '/thumb-generator.php';   // snap_medium_thumb_box()
    $map = [];
    foreach ($lists as $list) {
        foreach ((array)$list as $item) {
            if (!is_array($item)) continue;
            $aspect = trim((string)($item['img_thumb_aspect'] ?? ''));
            $medium = trim((string)($item['img_thumb_medium'] ?? ''));
            if ($aspect === '' || $medium === '') continue;

            // THE w DESCRIPTOR IS THE CANDIDATE'S INTRINSIC PIXEL WIDTH, NOT
            // THE SIZE LIMIT IT WAS GENERATED UNDER. The first version of
            // this advertised every m_ as 600w and every a_ as 900w, which is
            // only true for landscape: the constants are LONG-EDGE limits, so
            // a 2:3 portrait m_ is about 400px wide and its a_ about 600px.
            // Telling the browser a 400px file is 600px wide invites it to
            // pick that file for a 600px slot and upscale it, which is a
            // BLURRY PHOTOGRAPH -- the one defect this product cannot ship.
            // Codex found it in the rendered gate before anyone saw it.
            //
            // Derived from the source dimensions the row already carries,
            // through the same box function the generator uses, so the number
            // advertised is the number written. No file is opened.
            $srcW = (int)($item['img_width'] ?? 0);
            $srcH = (int)($item['img_height'] ?? 0);
            if ($srcW < 1 || $srcH < 1) {
                // WITHOUT DIMENSIONS THERE IS NO HONEST DESCRIPTOR, so this
                // offers none and the grid serves a_ alone. Guessing here is
                // what the bug was.
                //
                // HONEST ABOUT THIS GUARD: it is defensive, not load-bearing,
                // and NO TEST CAN TELL IT APART from the equal-width check
                // below. A zero dimension makes snap_medium_thumb_box() floor
                // both candidates to the same width, which that check already
                // refuses. I am keeping it because it says what it means at
                // the point the reader needs to know it, not because a
                // mutation proves it -- removing it changes no outcome, and I
                // found that out by trying.
                continue;
            }
            [$mw] = snap_medium_thumb_box($srcW, $srcH, SNAPSMACK_THUMB_MEDIUM_LONG);
            [$aw] = snap_medium_thumb_box($srcW, $srcH, SNAPSMACK_THUMB_ASPECT_LONG);
            if ($mw < 1 || $aw < 1 || $mw === $aw) {
                // Equal widths describe one file twice: a photograph smaller
                // than both limits is copied at native size, so m_ and a_ are
                // the same image and a srcset would be noise.
                continue;
            }
            $map[$aspect] = $medium . ' ' . $mw . 'w, ' . $aspect . ' ' . $aw . 'w';
        }
    }
    return $map;
}

}
// ===== SNAPSMACK EOF =====
