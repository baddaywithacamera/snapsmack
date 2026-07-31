/**
 * SNAPSMACK - Masonry Grid Engine (ss-engine-masonry.js)
 *
 * Hand-rolled CSS-Grid masonry — the in-house replacement for the external
 * fjGallery justified library. No dependencies. Each tile spans a number of
 * grid row-units proportional to its TRUE aspect ratio, so a portrait stands
 * ~2x taller than a landscape and images show full-frame (only a sub-row-unit
 * cover-crop from rounding). "Portraits span ~2 rows" falls out of the aspect
 * math — it is NOT a hard-coded class you can accidentally apply to everything.
 *
 * THE BUG THIS FIXES ("everything is a portrait"): aspect is derived ONLY from
 * reliable dimensions — the server's data-w/data-h first (flash-free, correct on
 * first paint), else the image's own natural size once it has actually decoded.
 * An unknown / zero ratio is treated as ONE row (landscape), NEVER portrait, and
 * re-measured on image load. A tile can never fall into the tall branch just
 * because its size wasn't ready yet.
 *
 * MARKUP CONTRACT (skin emits — zero inline JS/style, per SnapSmack rules):
 *   <div class="ss-masonry">
 *     <a class="ss-masonry-item" href="...">
 *       <img src="..." data-w="1200" data-h="800" alt="..." loading="lazy">
 *     </a>
 *     ...
 *   </div>
 * The engine sets one per-tile CSS var, --ss-rows. The SKIN STYLESHEET owns the
 * grid container and the span rule:
 *   .ss-masonry       { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
 *                       grid-auto-rows:8px; gap:6px; }
 *   .ss-masonry-item  { grid-row-end: span var(--ss-rows, 1); overflow:hidden; }
 *   .ss-masonry-item img { width:100%; height:100%; object-fit:cover; display:block; }
 * The engine reads the row-unit + gap straight from the grid's computed style
 * (grid-auto-rows / row-gap), so the CSS is the single source of truth — no data
 * attribute to keep in sync. Optional overrides: window.SS_MASONRY_CONFIG
 * { rowUnit, gap }.
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

    function intOf(v, fallback) {
        var n = parseInt(v, 10);
        return Number.isFinite(n) ? n : fallback;
    }
    function pxOf(v, fallback) {
        var n = parseFloat(v);
        return Number.isFinite(n) ? n : fallback;
    }

    /** Reliable aspect (w/h) for a tile, or 0 when not yet knowable. */
    function tileAspect(item, img) {
        // 1) Server-supplied dimensions — right on the first paint, no reflow.
        var w = intOf(item.getAttribute('data-w'), 0) || (img ? intOf(img.getAttribute('data-w'), 0) : 0);
        var h = intOf(item.getAttribute('data-h'), 0) || (img ? intOf(img.getAttribute('data-h'), 0) : 0);
        // 2) Natural size, but ONLY once the image has really decoded.
        if ((!w || !h) && img && img.naturalWidth > 0 && img.naturalHeight > 0) {
            w = img.naturalWidth;
            h = img.naturalHeight;
        }
        return (w > 0 && h > 0) ? (w / h) : 0;   // 0 == unknown → caller uses 1 row
    }

    function layoutGrid(grid) {
        var cs = window.getComputedStyle(grid);
        var rowUnit = pxOf(cs.gridAutoRows, intOf(cfg.rowUnit, 8));
        if (!(rowUnit >= 1)) rowUnit = 8;
        var gap = pxOf(cs.rowGap, intOf(cfg.gap, 4));
        if (!(gap >= 0)) gap = 4;

        var items = grid.querySelectorAll('.ss-masonry-item');
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var cw = item.getBoundingClientRect().width;   // real, responsive column width
            if (cw <= 0) continue;                          // grid not laid out yet — a later run catches it
            var aspect = tileAspect(item, item.querySelector('img'));
            // Unknown aspect => a single landscape row. NEVER the portrait branch.
            var pxHeight = (aspect > 0) ? (cw / aspect) : rowUnit;
            var span = Math.max(1, Math.round((pxHeight + gap) / (rowUnit + gap)));
            item.style.setProperty('--ss-rows', span);
        }
    }

    function init(grid) {
        if (grid.__ssMasonry) return;                       // idempotent — safe to re-init
        grid.__ssMasonry = true;

        var run = function () { layoutGrid(grid); };

        // Re-measure each image the instant it decodes — this is what stops a
        // not-yet-loaded image being mis-sized. Plus two stabilisation ticks for
        // web-font / late-CSS reflow, mirroring the old justified engine.
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
