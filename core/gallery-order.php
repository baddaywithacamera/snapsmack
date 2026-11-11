<?php
// SNAPSMACK_EOF_HEADER — preserve the canonical EOF marker below.
if (PHP_SAPI !== 'cli' && !empty($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * In what order does a post show its photographs?
 *
 * SS-1185. Six of the galleries migrating from mccormickphotography.com were
 * set to show their photographs in a random order on each view. That fact had
 * nowhere to live, so those galleries could not be imported truthfully.
 *
 * SHUFFLE IS PRESENTATION, NOT STORAGE. The authored order stays exactly as it
 * was saved; this decides only what a visitor is shown on one render. Two
 * reasons, and the second is the one that matters:
 *
 *   - Turning shuffle off has to give the authored order back. That is only
 *     true if nothing ever rewrote it.
 *   - A gallery whose stored order is re-randomised on every page view has no
 *     authored order left after the first view, and no way to get it back.
 *     An owner who turned shuffle on to try it would have destroyed the
 *     sequence he spent an afternoon arranging, silently, with no undo.
 *
 * Pulled out as ONE PURE FUNCTION on purpose: ids in, ids out, no database and
 * no globals. The risk in a shuffle is losing or duplicating a photograph, and
 * that is only provable if the thing doing the shuffling can be run on its own.
 * See tests/smackpress-gallery-shuffle-regression.php, which runs it 200 times
 * and checks the result is a permutation every time.
 */

/**
 * @param  array $image_ids Photograph ids in their AUTHORED order.
 * @param  bool  $shuffle   The post's stored gallery_shuffle flag. Default OFF.
 * @return array The same ids, in the order to render them.
 */
function snap_gallery_present_order(array $image_ids, bool $shuffle = false): array
{
    // array_values first: callers pass query results, and a shuffled array with
    // gaps in its keys would renumber unpredictably downstream.
    $ids = array_values($image_ids);

    if (!$shuffle || count($ids) < 2) {
        // OFF is the default and it is deterministic. A gallery of nought or
        // one photograph has no order to change, so do not pretend otherwise.
        return $ids;
    }

    // shuffle() works on the local copy, never on the caller's array, so a
    // render can never reach back and disturb what was stored.
    shuffle($ids);

    return $ids;
}

// ===== SNAPSMACK EOF =====
