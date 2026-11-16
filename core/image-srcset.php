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
    $map = [];
    foreach ($lists as $list) {
        foreach ((array)$list as $item) {
            if (!is_array($item)) continue;
            $aspect = trim((string)($item['img_thumb_aspect'] ?? ''));
            $medium = trim((string)($item['img_thumb_medium'] ?? ''));
            if ($aspect === '' || $medium === '') continue;
            // The widths are the DECLARED long edges, which is what the
            // browser needs and what the files were generated at. Reading the
            // real pixel width of each file would mean opening every one of
            // them on every page load.
            $map[$aspect] = $medium . ' ' . SNAPSMACK_THUMB_MEDIUM_LONG . 'w, '
                          . $aspect . ' ' . SNAPSMACK_THUMB_ASPECT_LONG . 'w';
        }
    }
    return $map;
}

}
// ===== SNAPSMACK EOF =====
