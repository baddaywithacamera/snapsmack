/**
 * SNAPSMACK — JIVE TURKEY tile-border engine (Layer 2): OUTWARD PULSE
 *
 * The 70s companion to the JIVE TURKEY background. Each tile emits a coloured
 * ring that radiates OUTWARD from the photo edge (pure box-shadow, so it sits
 * entirely outside the image with no layout shift), growing from the frame to
 * a set reach while it fades, cycling through the colourway colours, and
 * staggering across the grid as a wave (6 directions). NOT an inside border.
 *
 * Built as a generic pulse driver — a target, a colour, a clock — so a later
 * BEATBOX feature can drive the same rings from a beat signal instead of a timer.
 *
 * Colour-agnostic: reads the active colourway's colours and re-tints itself the
 * instant the background engine broadcasts a change (the `jt:colourway` event /
 * window.__JT_COLOURWAY handshake), so SURPRISE / CYCLE keep the pulse matched.
 *
 * Targets every `.jt-tile` on the page; grid row/col are inferred from layout
 * (any responsive column count). Each tile's existing base box-shadow (the
 * faux-instamatic print shadow) is captured once and always kept underneath the
 * rings. Config from the `.jt-jive-turkey-bg` carrier's dataset (or defaults):
 *   data-jt-border-enabled  1|0  master on/off
 *   data-jt-border-speed    0..100 pulse speed (higher = faster)
 *   data-jt-border-reach    px  how far the ring travels outward
 *   data-jt-border-thick    px  ring thickness
 *   data-jt-border-rings    1..3 concurrent ripples
 *   data-jt-border-wave     0..100 stagger across the grid
 *   data-jt-border-dir      ltr|rtl|ttb|btt|dtlbr|dbrtl
 *   data-jt-colourway / data-jt-colourways  starting colours
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
    function rgba(hex, a) {
        hex = String(hex).replace('#', '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        var r = parseInt(hex.slice(0,2),16), g = parseInt(hex.slice(2,4),16), b = parseInt(hex.slice(4,6),16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + a.toFixed(3) + ')';
    }

    function init() {
        var tiles = Array.prototype.slice.call(document.querySelectorAll('.jt-tile'));
        if (!tiles.length) return;

        var host = document.querySelector('.jt-jive-turkey-bg') || document.documentElement;
        function attr(n, d) { var v = host.getAttribute ? host.getAttribute(n) : null; return v == null ? d : v; }

        if (attr('data-jt-border-enabled', '1') === '0') return;   // borders off

        var SPD   = Math.max(0, Math.min(100, parseFloat(attr('data-jt-border-speed', 55))));
        var REACH = Math.max(2, parseFloat(attr('data-jt-border-reach', 22)) || 22);
        var THICK = Math.max(1, parseFloat(attr('data-jt-border-thick', 7)) || 7);
        var RINGS = Math.max(1, Math.min(3, parseInt(attr('data-jt-border-rings', 2), 10) || 2));
        var WAVE  = Math.max(0, Math.min(100, parseFloat(attr('data-jt-border-wave', 45))));
        var DIR   = attr('data-jt-border-dir', 'dtlbr');

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

        function applyShadow(tile, shadow) {
            var base = tile.__jtBase;
            tile.style.boxShadow = shadow + (base ? (shadow ? ', ' : '') + base : '');
        }

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) {
            // Static outer ring in the first colour so the colourway still frames the photo.
            for (var i2 = 0; i2 < tiles.length; i2++) {
                applyShadow(tiles[i2], '0 0 0 ' + THICK + 'px ' + rgba(COLS[0], 0.85));
            }
            return;
        }

        var rafId = null;
        function frame(now) {
            var t = now / 1000;
            var period = 0.6 + Math.pow((100 - SPD) / 100, 2) * 3.4;   // seconds per pulse
            var waveAmt = (WAVE / 100) * 0.9;
            var rows = geo._rows || 1, cols = geo._cols || 1;
            for (var i = 0; i < tiles.length; i++) {
                var g = geo[i] || { row: 0, col: 0 };
                var off = orderVal(DIR, g.row, g.col, rows, cols) * waveAmt;
                var local = t / period + off;
                var parts = '';
                for (var k = 0; k < RINGS; k++) {
                    var phase = ((local - k * 0.34) % 1 + 1) % 1;      // stagger ripples
                    var spread = phase * REACH;                        // grow outward
                    var alpha = Math.pow(1 - phase, 1.4) * 0.9;        // fade as it expands
                    var ci = Math.floor(local - k * 0.34);             // colour advances each pulse
                    var col = COLS[((ci % COLS.length) + COLS.length) % COLS.length];
                    parts += (parts ? ',' : '') +
                        '0 0 0 ' + (spread + THICK).toFixed(1) + 'px ' + rgba(col, alpha) +
                        ',0 0 0 ' + spread.toFixed(1) + 'px rgba(0,0,0,0)';
                }
                applyShadow(tiles[i], parts);
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
