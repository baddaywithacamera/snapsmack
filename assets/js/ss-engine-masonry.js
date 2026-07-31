/**
 * SNAPSMACK - Masonry Grid Engine (ss-engine-masonry.js)
 *
 * JUSTIFIED photo wall (v3). Each run of photos is sized so it fills the FULL
 * content width exactly — ZERO gaps, ZERO ragged edge, edge-to-edge (therefore
 * centred). Run heights vary with the photos in them, so it reads as an organic
 * asymmetric wall, not a rigid grid of rows or columns. This is the trick Flickr
 * / Google Photos use, and the only way to be gap-free AND edge-to-edge while
 * keeping native aspect (all three at once is geometrically impossible).
 *
 * SIZE: --ss-base ("Photo Size") sets a target run height (base / 1.5) so a
 * typical landscape's long side lands near --ss-base. Landscapes stay wide,
 * portraits tall+narrow; sharing a run height, they balance without a per-tile
 * cap. A run is closed the moment its photos at target height would overflow W,
 * so the exact-fit height is always <= target — no photo is ever upscaled past
 * its ~900px source into chunky mush. The last (short) run centres via the CSS.
 *
 * MARKUP CONTRACT (skin emits — zero inline JS):
 *   <div class="ss-masonry">
 *     <a class="ss-masonry-item" href="..."><img src="..." data-w="1200" data-h="800" loading="lazy"></a>
 *   </div>
 * SKIN STYLESHEET owns the container; the engine sets each item's px w/h:
 *   .ss-masonry      { display:flex; flex-wrap:wrap; gap:var(--ss-gap,6px);
 *                      align-content:flex-start; justify-content:center; }
 *   .ss-masonry-item { overflow:hidden; }
 *   .ss-masonry-item img { width:100%; height:100%; object-fit:cover; display:block; }
 *
 * Tunable hooks (CSS vars on .ss-masonry, or window.SS_MASONRY_CONFIG):
 *   --ss-base (px) target size · --ss-gap (px). All tuning is skin-side.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    var cfg = window.SS_MASONRY_CONFIG || {};

    function numOf(v, fallback) {
        var n = parseFloat(v);
        return Number.isFinite(n) ? n : fallback;
    }

    /** Reliable aspect (w/h), or 0 when not yet knowable. */
    function tileAspect(item, img) {
        var w = parseInt(item.getAttribute('data-w'), 10) || (img ? parseInt(img.getAttribute('data-w'), 10) : 0);
        var h = parseInt(item.getAttribute('data-h'), 10) || (img ? parseInt(img.getAttribute('data-h'), 10) : 0);
        if ((!w || !h) && img && img.naturalWidth > 0 && img.naturalHeight > 0) {
            w = img.naturalWidth;
            h = img.naturalHeight;
        }
        return (w > 0 && h > 0) ? (w / h) : 0;
    }

    /** Inner content width of the grid (excludes its own padding). */
    function contentWidth(grid, cs) {
        var w = grid.clientWidth
              - numOf(cs.paddingLeft, 0)
              - numOf(cs.paddingRight, 0);
        return w > 0 ? w : 0;
    }

    function layoutGrid(grid) {
        var cs   = window.getComputedStyle(grid);
        var W    = contentWidth(grid, cs);
        if (!(W > 0)) return;                                // not laid out yet — a later tick catches it

        var gap  = numOf(cs.getPropertyValue('--ss-gap'), numOf(cfg.gap, 6));
        if (!(gap >= 0)) gap = 6;
        // --ss-base ("Photo Size") ≈ a landscape's long side. A ~1.5 landscape at
        // run height H has width 1.5·H, so H ≈ base/1.5 lands the long side on base.
        var base = numOf(cfg.base, numOf(cs.getPropertyValue('--ss-base'), 460));
        if (!(base >= 80)) base = 460;
        var targetH = base / 1.5;

        var items = grid.querySelectorAll('.ss-masonry-item');
        var run = [], aspectSum = 0;

        function place(list, height) {
            for (var j = 0; j < list.length; j++) {
                var it = list[j];
                it.style.width  = Math.floor(height * it.__a) + 'px';
                it.style.height = Math.round(height) + 'px';
                it.style.flex   = '0 0 auto';
            }
        }

        // A run is closed once its photos, laid at targetH, would overflow W.
        // At that point the exact-fit height (fitH) is <= targetH, so the run
        // fills W edge-to-edge WITHOUT upscaling past targetH. The last run is
        // never stretched — it sits at targetH and centres via the CSS.
        function flush(isLast) {
            if (!run.length) return;
            var gaps = gap * (run.length - 1);
            var fitH = (W - gaps) / aspectSum;
            place(run, isLast ? Math.min(targetH, fitH) : fitH);
            run = []; aspectSum = 0;
        }

        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var a = tileAspect(item, item.querySelector('img'));
            if (!(a > 0)) a = 1;
            item.__a = a;

            run.push(item);
            aspectSum += a;

            var gaps = gap * (run.length - 1);
            if (aspectSum * targetH + gaps >= W) flush(false);
        }
        flush(true);
    }

    function init(grid) {
        if (grid.__ssMasonry) return;                       // idempotent
        grid.__ssMasonry = true;

        var run = function () { layoutGrid(grid); };

        var imgs = grid.querySelectorAll('.ss-masonry-item img');
        for (var i = 0; i < imgs.length; i++) {
            if (imgs[i].complete && imgs[i].naturalWidth > 0) continue;
            imgs[i].addEventListener('load', run, { once: true });
            imgs[i].addEventListener('error', run, { once: true });
        }

        run();
        setTimeout(run, 50);
        setTimeout(run, 300);

        var t;
        window.addEventListener('resize', function () {
            clearTimeout(t);
            t = setTimeout(run, 100);
        });
    }

    function boot() {
        var grids = document.querySelectorAll('.ss-masonry');
        for (var i = 0; i < grids.length; i++) init(grids[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
// ===== SNAPSMACK EOF =====
