/**
 * SNAPSMACK - Grid-family Progressive Grid Reveal (shared engine)
 *
 * The grid ships every published post in the DOM (with loading="lazy" images),
 * which means a large blog renders at full height immediately — a 400,000px
 * scroll appears at once. This makes the page behave the familiar way instead:
 * only the first batch of tiles is laid out, and each subsequent batch is
 * revealed as the reader nears the bottom, so the page GROWS as you scroll.
 *
 * PREFIX-DERIVED (shared lib): works for any Grid-family skin (au-, pa-, tg-, …)
 * with no fork. The prefix P is read from the page's `<P>-sticky-nav` (same idiom
 * as the shared grid engines ss-engine-aurora-wave / grid-modal / grid-nav),
 * falling back to the first `[class*="-grid"]` element. Folded tiles are
 * display:none (removed from layout) via the `.<P>-fold` class. After each reveal
 * it fires `<P>:grid-updated` so aurora-wave.js re-scans the new tiles.
 *
 * Each skin using this engine MUST ship the fold rule in its style.css:
 *     .<P>-grid .<P>-tile.<P>-fold { display: none; }
 * (AURORA = .au-fold, PARADE = .pa-fold, THE GRID = .tg-fold.)
 *
 * Pure client-side: no server endpoint, no core/index.php changes.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    var BATCH  = 120;   // tiles revealed per step (~40 rows of 3) — multiple of 3
    var MARGIN = 1500;  // px from the bottom at which the next batch reveals

    // Prefix derivation — mirrors ss-engine-aurora-wave.js so au-/pa-/tg- all
    // resolve from the same DOM idiom with no per-skin fork.
    function derivePrefix() {
        var nav = document.querySelector('nav[class*="-sticky-nav"]');
        if (nav) {
            var m = nav.className.match(/(?:^|\s)([a-z]+)-sticky-nav(?:\s|$)/);
            if (m) return m[1];
        }
        var grid = document.querySelector('[class*="-grid"]');
        if (grid) {
            var mg = grid.className.match(/(?:^|\s)([a-z]+)-grid(?:\s|$)/);
            if (mg) return mg[1];
        }
        return null;
    }

    function init() {
        var P = derivePrefix();
        if (!P) return;

        var grid = document.querySelector('.' + P + '-grid');
        if (!grid) return;

        var tiles = [].slice.call(grid.querySelectorAll('.' + P + '-tile'));
        if (tiles.length <= BATCH) return;   // small grid — nothing to fold

        var foldClass = P + '-fold';
        var shown = BATCH;
        for (var i = BATCH; i < tiles.length; i++) tiles[i].classList.add(foldClass);

        function reveal() {
            var next = Math.min(shown + BATCH, tiles.length);
            for (var i = shown; i < next; i++) tiles[i].classList.remove(foldClass);
            shown = next;
            // Let the border-wave engine pick up the newly laid-out tiles.
            document.dispatchEvent(new CustomEvent(P + ':grid-updated'));
        }

        function maybeReveal() {
            // Reveal in a loop so a tall viewport (or a fast jump to the bottom)
            // fills enough to keep scrolling; each reveal grows scrollHeight.
            while (shown < tiles.length &&
                   window.innerHeight + window.scrollY >=
                       document.documentElement.scrollHeight - MARGIN) {
                reveal();
            }
            if (shown >= tiles.length) {
                window.removeEventListener('scroll', maybeReveal);
                window.removeEventListener('resize', maybeReveal);
            }
        }

        window.addEventListener('scroll', maybeReveal, { passive: true });
        window.addEventListener('resize', maybeReveal, { passive: true });
        maybeReveal();   // in case the first batch doesn't fill the viewport
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
