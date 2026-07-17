/**
 * SNAPSMACK — JIVE TURKEY tile-border engine (Layer 2): INSIDE COLOUR BORDER
 *
 * The 70s companion to the JIVE TURKEY background. Each tile carries a colour
 * frame on its inside edge that HOLDS at full width, SHRINKS to nothing, then
 * EXPANDS back in as the NEXT colourway colour (purple → shrink → blue → …),
 * with the change staggering across the grid as a wave (6 directions). Crisp and
 * graphic — no soft glow, no Instamatic frame.
 *
 * IMPORTANT: this drives each tile's existing `.jt-ring` overlay — an absolutely
 * positioned hollow frame (built with the padding + mask trick in style.css) that
 * matches the tile's corner radius and sits OVER the photo edge. We set only the
 * ring's colour (background) and thickness (padding). That means the photo never
 * resizes (the border is not on the image's box) and the corners never mismatch
 * (ring, tile and photo all share --tile-radius). Do NOT put a CSS border on
 * `.jt-tile` — that squeezes the image and shows dark corners.
 *
 * Colour-agnostic: reads the active colourway's colours and re-tints the instant
 * the background engine broadcasts a change (`jt:colourway` event /
 * window.__JT_COLOURWAY handshake), so SURPRISE / CYCLE keep the border matched
 * on the light field AND the dark REELS field.
 *
 * Config from the `.jt-jive-turkey-bg` carrier dataset (or defaults):
 *   data-jt-border-enabled  1|0    master on/off
 *   data-jt-border-width    px     full frame thickness (5–15)
 *   data-jt-border-speed    0..100 colour-change speed (higher = faster)
 *   data-jt-border-wave     0..100 stagger across the grid
 *   data-jt-border-dir      ltr|rtl|ttb|btt|dtlbr|dbrtl
 *   data-jt-colourway / data-jt-colourways   starting colours
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    var COLOURWAYS_DEFAULT = {
        BARF:    { cream:'#efe7cf', colors:['#c9b23a','#6e7f39','#6b4a2a'] },
        BLECH:   { cream:'#efe3cd', colors:['#6a3b86','#dd7328','#c39a3f'] },
        GROOVY:  { cream:'#f2e7d6', colors:['#7b3f9e','#e368a4','#3f7cc4'] },
        HARVEST: { cream:'#f2e2c0', colors:['#d99a2b','#bd4e1f','#6b3f24'] }
    };
    function orderVal(dir, r, c, rows, cols) {
        switch (dir) {
            case 'ltr':   return c;
            case 'rtl':   return (cols - 1 - c);
            case 'ttb':   return r;
            case 'btt':   return (rows - 1 - r);
            case 'dbrtl': return (rows - 1 - r) + (cols - 1 - c);
            default:      return r + c;
        }
    }

    function init() {
        var tiles = Array.prototype.slice.call(document.querySelectorAll('.jt-tile'));
        if (!tiles.length) return;

        var host = document.querySelector('.jt-jive-turkey-bg') || document.documentElement;
        function attr(n, d) { var v = host.getAttribute ? host.getAttribute(n) : null; return v == null ? d : v; }

        if (attr('data-jt-border-enabled', '1') === '0') return;   // borders off

        var W    = Math.max(1, Math.min(30, parseFloat(attr('data-jt-border-width', 12)) || 12));
        var SPD  = Math.max(0, Math.min(100, parseFloat(attr('data-jt-border-speed', 60))));
        var WAVE = Math.max(0, Math.min(100, parseFloat(attr('data-jt-border-wave', 45))));
        var DIR  = attr('data-jt-border-dir', 'dtlbr');

        var COLOURWAYS = COLOURWAYS_DEFAULT;
        try {
            var raw = JSON.parse(attr('data-jt-colourways', 'null'));
            if (raw && typeof raw === 'object' && Object.keys(raw).length) COLOURWAYS = raw;
        } catch (e) {}
        var curName = (attr('data-jt-colourway', '') || '').toUpperCase();
        var COLS = (COLOURWAYS[curName] && COLOURWAYS[curName].colors)
            ? COLOURWAYS[curName].colors.slice()
            : (COLOURWAYS.HARVEST ? COLOURWAYS.HARVEST.colors.slice() : ['#d99a2b','#bd4e1f','#6b3f24']);
        if (window.__JT_COLOURWAY && window.__JT_COLOURWAY.colors && window.__JT_COLOURWAY.colors.length) {
            COLS = window.__JT_COLOURWAY.colors.slice();
        }
        window.addEventListener('jt:colourway', function (ev) {
            if (ev && ev.detail && ev.detail.colors && ev.detail.colors.length) COLS = ev.detail.colors.slice();
        });

        // Resolve each tile's .jt-ring overlay (the hollow frame). Create a minimal
        // one only if the skin didn't provide it (keeps the engine self-sufficient).
        var rings = [];
        for (var i = 0; i < tiles.length; i++) {
            var ring = tiles[i].querySelector('.jt-ring');
            if (!ring) {
                ring = document.createElement('div');
                ring.className = 'jt-ring';
                ring.style.position = 'absolute';
                ring.style.inset = '0';
                ring.style.pointerEvents = 'none';
                ring.style.borderRadius = 'inherit';
                ring.style.webkitMask = 'linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0)';
                ring.style.webkitMaskComposite = 'xor';
                ring.style.mask = 'linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0)';
                ring.style.maskComposite = 'exclude';
                if (getComputedStyle(tiles[i]).position === 'static') tiles[i].style.position = 'relative';
                tiles[i].appendChild(ring);
            }
            ring.style.display = 'block';   // undo any earlier hide
            rings.push(ring);
        }

        // Infer grid geometry (robust to responsive column counts).
        var geo = [];
        function measure() {
            geo = [];
            var rowsMap = [], EPS = 6;
            for (var i = 0; i < tiles.length; i++) {
                var t = tiles[i], top = t.offsetTop, left = t.offsetLeft, row = -1;
                for (var r = 0; r < rowsMap.length; r++) { if (Math.abs(rowsMap[r].top - top) <= EPS) { row = r; break; } }
                if (row < 0) { row = rowsMap.length; rowsMap.push({ top: top, cells: [] }); }
                rowsMap[row].cells.push({ i: i, left: left });
            }
            rowsMap.sort(function (a, b) { return a.top - b.top; });
            var cols = 1;
            for (var rr = 0; rr < rowsMap.length; rr++) {
                rowsMap[rr].cells.sort(function (a, b) { return a.left - b.left; });
                cols = Math.max(cols, rowsMap[rr].cells.length);
                for (var c = 0; c < rowsMap[rr].cells.length; c++) geo[rowsMap[rr].cells[c].i] = { row: rr, col: c };
            }
            geo._rows = rowsMap.length; geo._cols = cols;
        }
        measure();
        var rt = null;
        window.addEventListener('resize', function () { if (rt) clearTimeout(rt); rt = setTimeout(measure, 150); });

        function paint(ring, w, col) {
            ring.style.padding = Math.max(0, w).toFixed(2) + 'px';   // frame thickness (mask hollows the centre)
            ring.style.background = col;                             // frame colour
        }

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) {
            for (var i2 = 0; i2 < rings.length; i2++) paint(rings[i2], W, COLS[0]);   // static full frame
            return;
        }

        var rafId = null;
        function frame(now) {
            var t = now / 1000;
            var D = 0.8 + Math.pow((100 - SPD) / 100, 2) * 18;   // seconds per colour
            var waveAmt = (WAVE / 100) * 0.9;
            var rows = geo._rows || 1, cols = geo._cols || 1;
            for (var i = 0; i < tiles.length; i++) {
                var g = geo[i] || { row: 0, col: 0 };
                var off = orderVal(DIR, g.row, g.col, rows, cols) * waveAmt;
                var local = t / D + off;
                var step = Math.floor(local), frac = local - step;
                var w, ci;
                if (frac < 0.55) { w = W; ci = step; }                                       // hold full, current colour
                else if (frac < 0.775) { var p = (frac - 0.55) / 0.225; w = W * (1 - p) * (1 - p); ci = step; }  // shrink to 0
                else { var p2 = (frac - 0.775) / 0.225; w = W * (2 * p2 - p2 * p2); ci = step + 1; }             // expand as next colour
                paint(rings[i], w, COLS[((ci % COLS.length) + COLS.length) % COLS.length]);
            }
            rafId = window.requestAnimationFrame(frame);
        }
        function start() { if (rafId === null) rafId = window.requestAnimationFrame(frame); }
        function stop() { if (rafId !== null) { window.cancelAnimationFrame(rafId); rafId = null; } }
        document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
        if (!document.hidden) start();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
