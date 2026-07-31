/**
 * SNAPSMACK - Masonry Grid Engine (ss-engine-masonry.js)
 *
 * ASYMMETRIC free tessellation — NO visible columns, NO rows. The skin lays a
 * FINE CSS-grid unit (e.g. 40px square) with dense auto-flow; this engine spans
 * each tile a computed number of units in BOTH axes from its native aspect,
 * scaled to a base size. Because every tile spans many fine units at staggered
 * offsets, tiles pack tightly WITHOUT snapping into vertical columns or
 * horizontal rows — the mosaic reads as an asymmetric wall.
 *
 * BALANCE (Sean's spec): a LANDSCAPE's long side (width) = `base` — the
 * adjustable tile size. A PORTRAIT's long side (height) = `portraitRatio`
 * (0.85) × base. Portraits read taller at equal size, so they're sized down 15%
 * to balance the wall — NOT squeezed into skinny one-column strips. Each tile
 * keeps its native aspect; sizes round to the unit (a sub-unit cover crop).
 *
 * MARKUP CONTRACT (skin emits — zero inline JS):
 *   <div class="ss-masonry">
 *     <a class="ss-masonry-item" href="..."><img src="..." data-w="1200" data-h="800" loading="lazy"></a>
 *   </div>
 * SKIN STYLESHEET owns the container + span rules:
 *   .ss-masonry      { display:grid; grid-auto-flow:dense;
 *                      grid-template-columns:repeat(auto-fill,var(--ss-unit,40px));
 *                      grid-auto-rows:var(--ss-unit,40px); gap:var(--ss-gap,6px); }
 *   .ss-masonry-item { grid-column:span var(--ss-cols,1);
 *                      grid-row:span var(--ss-rows,1); overflow:hidden; }
 *   .ss-masonry-item img { width:100%; height:100%; object-fit:cover; display:block; }
 *
 * The engine reads unit, gap and --ss-base straight from the grid's computed
 * style, so the CSS is the single source of truth. Optional overrides:
 *   window.SS_MASONRY_CONFIG = { base, portraitRatio, unit }
 * or CSS vars --ss-base (landscape long side, px) / --ss-portrait-ratio.
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

    function layoutGrid(grid) {
        var cs   = window.getComputedStyle(grid);
        var unit = numOf(cs.gridAutoRows, numOf(cfg.unit, 40));
        if (!(unit >= 1)) unit = 40;
        var gap  = numOf(cs.rowGap, numOf(cfg.gap, 6));
        if (!(gap >= 0)) gap = 6;
        // Landscape long side (px) — the adjustable "tile size". CSS var wins,
        // then config, then a sensible ~20-unit default.
        var base = numOf(cfg.base, numOf(cs.getPropertyValue('--ss-base'), 20 * unit));
        if (!(base >= unit)) base = 20 * unit;
        var pr   = numOf(cfg.portraitRatio, numOf(cs.getPropertyValue('--ss-portrait-ratio'), 0.85));
        if (!(pr > 0)) pr = 0.85;

        var step = unit + gap;   // one unit's on-screen advance including the gap

        var items = grid.querySelectorAll('.ss-masonry-item');
        for (var i = 0; i < items.length; i++) {
            var item   = items[i];
            var aspect = tileAspect(item, item.querySelector('img'));
            if (!(aspect > 0)) aspect = 1;

            var tw, th;
            if (aspect >= 1) {           // landscape / square — long side is the WIDTH
                tw = base;
                th = base / aspect;
            } else {                      // portrait — long side is the HEIGHT, capped to pr·base
                th = pr * base;
                tw = th * aspect;
            }

            // Occasional HERO (bigger) and PIMPLE (tiny) tiles break up the wall
            // and help the dense pack fill. The skin flags a few — infrequent by
            // design; scales are CSS hooks so they tune skin-side.
            if (item.hasAttribute('data-hero')) {
                var hs = numOf(cs.getPropertyValue('--ss-hero-scale'), numOf(cfg.heroScale, 1.7));
                tw *= hs; th *= hs;
            } else if (item.hasAttribute('data-pimple')) {
                var ps = numOf(cs.getPropertyValue('--ss-pimple-scale'), numOf(cfg.pimpleScale, 0.55));
                tw *= ps; th *= ps;
            }

            var cols = Math.max(1, Math.round((tw + gap) / step));
            var rows = Math.max(1, Math.round((th + gap) / step));
            item.style.setProperty('--ss-cols', cols);
            item.style.setProperty('--ss-rows', rows);
        }
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
