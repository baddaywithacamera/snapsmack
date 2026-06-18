/**
 * SNAPSMACK - AURORA Progressive Grid Reveal
 *
 * The grid ships every published post in the DOM (with loading="lazy" images),
 * which means a large blog renders at full height immediately — a 400,000px
 * scroll appears at once. This makes the page behave the familiar way instead:
 * only the first batch of tiles is laid out, and each subsequent batch is
 * revealed as the reader nears the bottom, so the page GROWS as you scroll.
 *
 * Pure client-side: no server endpoint, no core/index.php changes. Folded tiles
 * are display:none (removed from layout) via the .au-fold class. After each
 * reveal it fires `aurora:grid-updated` so aurora-wave.js re-scans the new tiles.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    var BATCH  = 120;   // tiles revealed per step (~40 rows of 3) — multiple of 3
    var MARGIN  = 1500;  // px from the bottom at which the next batch reveals

    function init() {
        var grid = document.querySelector('.au-grid');
        if (!grid) return;

        var tiles = [].slice.call(grid.querySelectorAll('.au-tile'));
        if (tiles.length <= BATCH) return;   // small grid — nothing to fold

        var shown = BATCH;
        for (var i = BATCH; i < tiles.length; i++) tiles[i].classList.add('au-fold');

        function reveal() {
            var next = Math.min(shown + BATCH, tiles.length);
            for (var i = shown; i < next; i++) tiles[i].classList.remove('au-fold');
            shown = next;
            // Let the border-wave engine pick up the newly laid-out tiles.
            document.dispatchEvent(new CustomEvent('aurora:grid-updated'));
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
