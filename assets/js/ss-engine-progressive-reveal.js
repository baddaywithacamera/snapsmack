/**
 * SNAPSMACK - Progressive Reveal (shared "grow-as-you-scroll" engine)
 *
 * One engine for both photo-stream layouts. A large blog ships every post in the
 * DOM at full height, so the page renders enormous and the scrollbar never shrinks.
 * This folds everything past the first batch (display:none removes it from layout)
 * and reveals the next batch as the reader nears the bottom — the page GROWS as you
 * scroll. loading="lazy" images in folded sections never fetch until revealed.
 *
 * Two modes, auto-detected (supersedes the old ss-engine-aurora-reveal +
 * ss-engine-justified-reveal):
 *
 *   GRID-FAMILY (au-/pa-/tg- CSS grid) — prefix P read from <P>-sticky-nav (same
 *   idiom as aurora-wave/grid-modal/grid-nav), falling back to the first
 *   [class*="-grid"]. Folds .<P>-grid .<P>-tile via the .<P>-fold class (each skin
 *   ships `.<P>-grid .<P>-tile.<P>-fold { display:none }`), and fires
 *   `<P>:grid-updated` after each reveal so aurora-wave re-scans the new tiles.
 *
 *   JUSTIFIED (Slickr/RG) — folds #justified-grid > .justified-row via inline
 *   display, no fold class needed (server sets each item's flex-grow, and rows are
 *   independent flex rows, so hiding whole rows never disturbs the packing).
 *
 * Pure client-side: no server endpoint. No-ops on pages with neither structure.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    var GRID_BATCH = 120;   // tiles revealed per step (~40 rows of 3) — multiple of 3
    var ROW_BATCH  = 25;    // justified rows revealed per step
    var MARGIN     = 1500;  // px from the bottom at which the next batch reveals

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

    // Generic fold/reveal loop shared by both modes.
    //   items   — ordered NodeList/array of foldable elements
    //   batch   — how many to reveal per step
    //   hide(el)/show(el) — how to fold/unfold one element
    //   afterReveal()     — optional hook fired after each batch is revealed
    function attachReveal(items, batch, hide, show, afterReveal) {
        if (items.length <= batch) return;   // small enough — nothing to fold

        var shown = batch;
        for (var i = batch; i < items.length; i++) hide(items[i]);

        function reveal() {
            var next = Math.min(shown + batch, items.length);
            for (var i = shown; i < next; i++) show(items[i]);
            shown = next;
            if (afterReveal) afterReveal();
        }

        function maybeReveal() {
            while (shown < items.length &&
                   window.innerHeight + window.scrollY >=
                       document.documentElement.scrollHeight - MARGIN) {
                reveal();
            }
            if (shown >= items.length) {
                window.removeEventListener('scroll', maybeReveal);
                window.removeEventListener('resize', maybeReveal);
            }
        }

        window.addEventListener('scroll', maybeReveal, { passive: true });
        window.addEventListener('resize', maybeReveal, { passive: true });
        maybeReveal();   // in case the first batch doesn't fill the viewport
    }

    function init() {
        // ── Grid-family tile mode ──────────────────────────────────────────
        var P = derivePrefix();
        if (P) {
            var grid = document.querySelector('.' + P + '-grid');
            if (grid) {
                var tiles = [].slice.call(grid.querySelectorAll('.' + P + '-tile'));
                if (tiles.length > GRID_BATCH) {
                    var foldClass = P + '-fold';
                    attachReveal(
                        tiles, GRID_BATCH,
                        function (el) { el.classList.add(foldClass); },
                        function (el) { el.classList.remove(foldClass); },
                        function () { document.dispatchEvent(new CustomEvent(P + ':grid-updated')); }
                    );
                    return;   // a page is one layout or the other, not both
                }
            }
        }

        // ── Justified row mode ─────────────────────────────────────────────
        var jgrid = document.getElementById('justified-grid');
        if (jgrid) {
            var rows = [].slice.call(jgrid.querySelectorAll(':scope > .justified-row'));
            attachReveal(
                rows, ROW_BATCH,
                function (el) { el.style.display = 'none'; },
                function (el) { el.style.display = ''; },
                null
            );
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
