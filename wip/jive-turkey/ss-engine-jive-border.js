/**
 * SNAPSMACK — JIVE TURKEY tile-glow engine (Layer 2): NEON UNDER-GLOW
 *
 * The 70s companion to the JIVE TURKEY background. Each tile carries a soft
 * neon halo GLOWING out from behind the print (pure box-shadow blur, so it sits
 * entirely outside the image with no layout shift and no hard edge to fight a
 * ragged Instamatic frame). The glow holds a steady low ember, then BLOOMS
 * outward and brightens each time the colour SHIFTS to the next colourway
 * colour, with the shift staggering across the grid as a wave (6 directions).
 *
 * Built as a generic pulse driver — a target, a colour, a clock — so a later
 * BEATBOX feature can bloom the glow from a beat signal instead of a timer.
 *
 * Colour-agnostic: reads the active colourway's colours and re-tints the instant
 * the background engine broadcasts a change (`jt:colourway` event /
 * window.__JT_COLOURWAY handshake), so SURPRISE / CYCLE keep the glow matched.
 *
 * Targets every `.jt-tile`; grid row/col inferred from layout (any responsive
 * column count). Each tile's base box-shadow (the print drop-shadow) is captured
 * once and always kept underneath the glow. Config from the `.jt-jive-turkey-bg`
 * carrier's dataset (or defaults):
 *   data-jt-glow-enabled  1|0   master on/off
 *   data-jt-glow-speed    0..100 colour-shift speed (higher = faster)
 *   data-jt-glow-size     px    base glow radius
 *   data-jt-glow-punch    0..100 how hard it blooms outward on the shift
 *   data-jt-glow-steady   0..100 resting ember brightness between shifts
 *   data-jt-glow-layers   1..3  stacked halos (neon depth)
 *   data-jt-glow-wave     0..100 stagger across the grid
 *   data-jt-glow-dir      ltr|rtl|ttb|btt|dtlbr|dbrtl
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
    function rgbOf(hex) {
        hex = String(hex).replace('#', '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        return [parseInt(hex.slice(0,2),16), parseInt(hex.slice(2,4),16), parseInt(hex.slice(4,6),16)];
    }
    function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a.toFixed(3) + ')'; }
    function mix(a, b, t) { return [Math.round(a[0]+(b[0]-a[0])*t), Math.round(a[1]+(b[1]-a[1])*t), Math.round(a[2]+(b[2]-a[2])*t)]; }

    function init() {
        var tiles = Array.prototype.slice.call(document.querySelectorAll('.jt-tile'));
        if (!tiles.length) return;

        var host = document.querySelector('.jt-jive-turkey-bg') || document.documentElement;
        function attr(n, d) { var v = host.getAttribute ? host.getAttribute(n) : null; return v == null ? d : v; }

        if (attr('data-jt-glow-enabled', '1') === '0') return;   // glow off

        var SPD    = Math.max(0, Math.min(100, parseFloat(attr('data-jt-glow-speed', 45))));
        var SIZE   = Math.max(2, parseFloat(attr('data-jt-glow-size', 8)) || 8);
        var PUNCH  = Math.max(0, Math.min(100, parseFloat(attr('data-jt-glow-punch', 70)))) / 100;
        var STEADY = Math.max(0, Math.min(100, parseFloat(attr('data-jt-glow-steady', 35)))) / 100;
        var LAYERS = Math.max(1, Math.min(3, parseInt(attr('data-jt-glow-layers', 2), 10) || 2));
        var WAVE   = Math.max(0, Math.min(100, parseFloat(attr('data-jt-glow-wave', 50))));
        var DIR    = attr('data-jt-glow-dir', 'dtlbr');
        var WHITE  = [255, 255, 255];

        var COLOURWAYS = COLOURWAYS_DEFAULT;
        try {
            var raw = JSON.parse(attr('data-jt-colourways', 'null'));
            if (raw && typeof raw === 'object' && Object.keys(raw).length) COLOURWAYS = raw;
        } catch (e) {}
        var curName = (attr('data-jt-colourway', '') || '').toUpperCase();
        var hexes = (COLOURWAYS[curName] && COLOURWAYS[curName].colors)
            ? COLOURWAYS[curName].colors.slice()
            : (COLOURWAYS.HARVEST ? COLOURWAYS.HARVEST.colors.slice() : ['#d99a2b','#bd4e1f','#6b3f24']);
        if (window.__JT_COLOURWAY && window.__JT_COLOURWAY.colors && window.__JT_COLOURWAY.colors.length) {
            hexes = window.__JT_COLOURWAY.colors.slice();
        }
        var COLS = hexes.map(rgbOf);
        window.addEventListener('jt:colourway', function (ev) {
            if (ev && ev.detail && ev.detail.colors && ev.detail.colors.length) COLS = ev.detail.colors.map(rgbOf);
        });

        // Capture each tile's base shadow ONCE so the print drop-shadow survives.
        for (var i = 0; i < tiles.length; i++) {
            var bs = window.getComputedStyle(tiles[i]).boxShadow;
            tiles[i].__jtBase = (bs && bs !== 'none') ? bs : '';
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

        function applyShadow(tile, glow) {
            var base = tile.__jtBase;
            tile.style.boxShadow = glow + (base ? (glow ? ', ' : '') + base : '');
        }
        function glowFor(local) {
            var ci = Math.floor(local), frac = local - ci;
            var col = COLS[((ci % COLS.length) + COLS.length) % COLS.length];
            var pw = 0.4, p = frac < pw ? (1 - frac / pw) : 0; p = p * p;    // bloom at the shift, then ease
            var parts = '';
            for (var L = 0; L < LAYERS; L++) {
                var scale  = 0.6 + 0.7 * L;
                var spread = (SIZE * scale) * (0.55 + 0.9 * PUNCH * p);
                var blur   = spread * 1.6 + 6;
                var aBase  = (0.30 - 0.07 * L) * STEADY;
                var aPulse = (0.85 - 0.20 * L) * p * (0.4 + 0.9 * PUNCH);
                var a = Math.min(0.95, aBase + aPulse);
                var c = (L === 0) ? mix(col, WHITE, 0.25 * p) : col;          // hot near-white core at the peak
                parts += (parts ? ',' : '') + '0 0 ' + blur.toFixed(1) + 'px ' + spread.toFixed(1) + 'px ' + rgba(c, a);
            }
            return parts;
        }

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) {
            for (var i2 = 0; i2 < tiles.length; i2++) applyShadow(tiles[i2], glowFor(0.5));   // static ember
            return;
        }

        var rafId = null;
        function frame(now) {
            var t = now / 1000;
            var period = 0.7 + Math.pow((100 - SPD) / 100, 2) * 3.6;   // seconds per colour hold
            var waveAmt = (WAVE / 100) * 0.9;
            var rows = geo._rows || 1, cols = geo._cols || 1;
            for (var i = 0; i < tiles.length; i++) {
                var g = geo[i] || { row: 0, col: 0 };
                var off = orderVal(DIR, g.row, g.col, rows, cols) * waveAmt;
                applyShadow(tiles[i], glowFor(t / period + off));
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
