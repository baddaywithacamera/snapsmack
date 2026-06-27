/**
 * SNAPSMACK - Justified Photostream Progressive Reveal (shared engine)
 *
 * The Slickr/RG-family photostream (#justified-grid) ships every published photo
 * in the DOM as server-packed .justified-row blocks. On a large blog that is
 * thousands of rows / hundreds of thousands of px laid out at once, so the page
 * renders at full height immediately and the scrollbar never shrinks as you read.
 *
 * This folds every row past the first batch (display:none removes it from layout,
 * so document height reflects only what's revealed) and reveals the next batch as
 * the reader nears the bottom — the page GROWS as you scroll. loading="lazy" on the
 * images means folded rows never fetch until revealed.
 *
 * Row-level folding is safe for the justified layout: each .justified-row is an
 * independent flex row (item flex-grow is set server-side), so hiding whole rows
 * never disturbs the packing of the rows that remain.
 *
 * Pure client-side: no server endpoint, works on any page that has #justified-grid
 * (Slickr landing + archive, and any skin that adopts the handle); no-ops elsewhere.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    var BATCH  = 25;    // rows revealed per step
    var MARGIN = 1500;  // px from the bottom at which the next batch reveals

    function init() {
        var grid = document.getElementById('justified-grid');
        if (!grid) return;

        var rows = [].slice.call(grid.querySelectorAll(':scope > .justified-row'));
        if (rows.length <= BATCH) return;   // small stream — nothing to fold

        var shown = BATCH;
        for (var i = BATCH; i < rows.length; i++) rows[i].style.display = 'none';

        function reveal() {
            var next = Math.min(shown + BATCH, rows.length);
            for (var i = shown; i < next; i++) rows[i].style.display = '';
            shown = next;
        }

        function maybeReveal() {
            // Reveal in a loop so a tall viewport (or a jump to the bottom) keeps
            // enough rows to scroll; each reveal grows scrollHeight.
            while (shown < rows.length &&
                   window.innerHeight + window.scrollY >=
                       document.documentElement.scrollHeight - MARGIN) {
                reveal();
            }
            if (shown >= rows.length) {
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
