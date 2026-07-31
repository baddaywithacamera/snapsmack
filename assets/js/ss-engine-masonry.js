/**
 * SNAPSMACK - Masonry Grid Engine (ss-engine-masonry.js)
 *
 * Asymmetric tiled wall. NOT justified rows — a dense-packed CSS-Grid masonry
 * where landscapes run WIDE (span N columns) and portraits stay NARROW (1
 * column) and are deliberately capped SHORTER than landscapes are wide, so the
 * two orientations balance on screen instead of portraits out-muscling the wall.
 *
 * SIZE RULE (Sean's spec):
 *   - A landscape spans `landscapeCols` columns (default 2). Its height follows
 *     its TRUE aspect (native, minus a sub-row-unit cover crop).
 *   - A portrait spans 1 column. Its height is capped at `portraitRatio` (0.85)
 *     of a landscape's long side (= landscapeCols column-widths). Portraits look
 *     taller at equal size, so they get sized DOWN 15% to match visual weight.
 *     The image cover-crops a bit to fill — display quality is the priority.
 *   - Panoramas span `panoCols` (default 3), native height. Squares span 1.
 *
 * The engine sets two per-tile CSS vars — --ss-cols (column span) and --ss-rows
 * (row span). The SKIN STYLESHEET owns the grid container + the span rules:
 *   .ss-masonry      { display:grid; grid-auto-flow:dense;
 *                      grid-template-columns:repeat(auto-fill,minmax(160px,1fr));
 *                      grid-auto-rows:8px; gap:6px; }
 *   .ss-masonry-item { grid-column:span var(--ss-cols,1);
 *                      grid-row:span var(--ss-rows,1); overflow:hidden; }
 *   .ss-masonry-item img { width:100%; height:100%; object-fit:cover; display:block; }
 *
 * MARKUP CONTRACT (skin emits — zero inline JS, per SnapSmack rules):
 *   <div class="ss-masonry">
 *     <a class="ss-masonry-item" href="..."><img src="..." data-w="1200" data-h="800" loading="lazy"></a>
 *   </div>
 *
 * Column widths + gaps are read from the grid's own computed style, so the CSS
 * is the single source of truth. Tunable hooks (all optional):
 *   window.SS_MASONRY_CONFIG = {
 *     portraitRatio: 0.85,   // portrait long side ÷ landscape long side
 *     landscapeCols: 2,      // columns a landscape spans
 *     panoCols:      3,      // columns a panorama spans
 *     landscapeMin:  1.15,   // aspect ≥ this  => landscape
 *     portraitMax:   0.87,   // aspect ≤ this  => portrait
 *     panoMin:       2.2     // aspect ≥ this  => panorama
 *   }
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

    var portraitRatio = numOf(cfg.portraitRatio, 0.85);
    var landscapeCols = Math.max(1, Math.round(numOf(cfg.landscapeCols, 2)));
    var panoCols      = Math.max(landscapeCols, Math.round(numOf(cfg.panoCols, 3)));
    var landscapeMin  = numOf(cfg.landscapeMin, 1.15);
    var portraitMax   = numOf(cfg.portraitMax, 0.87);
    var panoMin       = numOf(cfg.panoMin, 2.2);

    /** Reliable aspect (w/h) for a tile, or 0 when not yet knowable. */
    function tileAspect(item, img) {
        var w = parseInt(item.getAttribute('data-w'), 10) || (img ? parseInt(img.getAttribute('data-w'), 10) : 0);
        var h = parseInt(item.getAttribute('data-h'), 10) || (img ? parseInt(img.getAttribute('data-h'), 10) : 0);
        if ((!w || !h) && img && img.naturalWidth > 0 && img.naturalHeight > 0) {
            w = img.naturalWidth;
            h = img.naturalHeight;
        }
        return (w > 0 && h > 0) ? (w / h) : 0;   // 0 == unknown → treat as square
    }

    function firstTrackPx(gridTemplateColumns) {
        var parts = String(gridTemplateColumns || '').split(/\s+/);
        for (var i = 0; i < parts.length; i++) {
            var n = parseFloat(parts[i]);
            if (Number.isFinite(n) && n > 0) return n;
        }
        return 0;
    }

    function layoutGrid(grid) {
        var cs      = window.getComputedStyle(grid);
        var colW    = firstTrackPx(cs.gridTemplateColumns);          // resolved 1-column width
        if (!(colW > 0)) return;                                     // not laid out yet — a later tick catches it
        var colGap  = numOf(cs.columnGap, 6);
        var rowGap  = numOf(cs.rowGap, 6);
        var rowUnit = numOf(cs.gridAutoRows, 8);
        if (!(rowUnit >= 1)) rowUnit = 8;

        // A landscape's long side (width) — the reference every portrait scales from.
        var landscapeLong = landscapeCols * colW + (landscapeCols - 1) * colGap;

        var items = grid.querySelectorAll('.ss-masonry-item');
        for (var i = 0; i < items.length; i++) {
            var item   = items[i];
            var aspect = tileAspect(item, item.querySelector('img'));

            var cols, pxHeight;
            if (aspect >= panoMin) {
                cols = panoCols;
                pxHeight = tileWidth(cols, colW, colGap) / aspect;      // native
            } else if (aspect >= landscapeMin) {
                cols = landscapeCols;
                pxHeight = tileWidth(cols, colW, colGap) / aspect;      // native
            } else if (aspect > 0 && aspect <= portraitMax) {
                cols = 1;
                pxHeight = portraitRatio * landscapeLong;               // CAPPED — the whole point
            } else {
                cols = 1;                                               // square / unknown
                pxHeight = colW;
            }

            var span = Math.max(1, Math.round((pxHeight + rowGap) / (rowUnit + rowGap)));
            item.style.setProperty('--ss-cols', cols);
            item.style.setProperty('--ss-rows', span);
        }
    }

    function tileWidth(cols, colW, colGap) {
        return cols * colW + (cols - 1) * colGap;
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
