/**
 * SNAPSMACK — BEATBOX visualiser engine (Layer 1 + Layer 2)
 * ss-engine-beatbox-viz.js
 *
 * Consumes the shared data bus (window.SnapBeatbox) owned by ss-engine-beatbox.js.
 * Renders on the 'beatbox:frame' event, which the core dispatches once per animation
 * frame WHILE AUDIO PLAYS — so both layers idle to zero CPU when paused (spec).
 *
 *   LAYER 1 — LED EQ tile borders. Writes CSS custom properties --bb-b0..--bb-b4
 *     (live band amplitudes, highs→bass) onto the grid carrier [data-beatbox-grid].
 *     ALL per-row / per-column rendering is done by nth-child + color-mix + clamp in
 *     the skin CSS — this engine performs NO per-tile DOM manipulation (spec build
 *     note). Row band = row % 5; column = VU segment (green/yellow/red thresholds).
 *
 *   LAYER 2 — Winamp-style canvas visualiser on [data-beatbox-viz]. Modes:
 *     oscilloscope, spectrum, geometric, generative. Generative here is a
 *     canvas-only approximation of the Milkdrop feel — NOT a Milkdrop port and NOT
 *     WebGL (see spec open question; a true generative mode is a later pass).
 *     Active when the master background mode is "viz" or "both".
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
/* Requires: ss-engine-beatbox.js */

(function () {
    'use strict';
    if (window.__ssBeatboxViz) return;
    window.__ssBeatboxViz = true;

    function fault(where, err) { try { if (window.console && console.error) console.error('[beatbox-viz] ' + where, err); } catch (e) {} }

    // Canvas viz palettes (mirror beatbox-config.php; Layer 1 colours live in skin CSS)
    var VIZ = {
        classic: ['#00FF41', '#FFD700', '#FF2020'],
        neon:    ['#00E5FF', '#FF00E5', '#7C4DFF'],
        fire:    ['#FFE24D', '#FF8A00', '#FF1E1E'],
        ice:     ['#CFFAFE', '#67E8F9', '#3B82F6']
    };

    var Bus = null, grid = null, canvas = null, cx = null, parts = [];
    var l1on = true, l2on = false, mode = 'spectrum';

    function bus() { return window.SnapBeatbox || null; }

    function resize() {
        if (!canvas) return;
        canvas.width = canvas.clientWidth || window.innerWidth;
        canvas.height = canvas.clientHeight || window.innerHeight;
    }

    function readSettings() {
        Bus = bus(); if (!Bus) return;
        var s = Bus.settings || {};
        l2on = (s.bgMode === 'viz' || s.bgMode === 'both');
        mode = s.vizMode || readVizMode();
        var gcarrier = document.querySelector('[data-beatbox-grid]');
        if (gcarrier) l1on = gcarrier.getAttribute('data-bb-l1') !== '0';
        if (canvas) canvas.classList.toggle('bb-viz-on', l2on);
        if (l2on && !cx) { cx = canvas.getContext('2d'); resize(); }
        if (!l2on && cx) cx.clearRect(0, 0, canvas.width, canvas.height);
    }
    function readVizMode() {
        var c = document.querySelector('[data-beatbox]');
        return (c && c.getAttribute('data-bb-viz-mode')) || 'spectrum';
    }
    function palette() {
        var p = (Bus && Bus.settings && Bus.settings.palette) || 'classic';
        return VIZ[p] || VIZ.classic;
    }

    // ── LAYER 1 : write band vars to the grid carrier ────────────────────────
    function layer1(b) {
        if (!grid) return;
        var v = l1on ? b : [0, 0, 0, 0, 0];
        grid.style.setProperty('--bb-b0', v[0].toFixed(3));
        grid.style.setProperty('--bb-b1', v[1].toFixed(3));
        grid.style.setProperty('--bb-b2', v[2].toFixed(3));
        grid.style.setProperty('--bb-b3', v[3].toFixed(3));
        grid.style.setProperty('--bb-b4', v[4].toFixed(3));
    }

    // ── LAYER 2 : canvas viz ─────────────────────────────────────────────────
    function layer2() {
        if (!l2on || !cx) return;
        var W = canvas.width, H = canvas.height, inten = (Bus.intensity != null ? Bus.intensity : 0.3);
        cx.clearRect(0, 0, W, H);
        cx.globalAlpha = Math.min(1, 0.35 + inten * 0.7);
        var pal = palette();
        if (mode === 'oscilloscope') osc(W, H, pal);
        else if (mode === 'spectrum') spectrum(W, H, pal);
        else if (mode === 'geometric') geo(W, H, pal, inten);
        else generative(W, H, pal, inten);
        cx.globalAlpha = 1;
    }

    function osc(W, H, pal) {
        var w = Bus.wave; if (!w) return; var n = w.length;
        cx.lineWidth = 2; cx.strokeStyle = pal[0]; cx.beginPath();
        for (var i = 0; i < n; i++) { var x = i / n * W, y = H / 2 + (w[i] - 128) / 128 * H * 0.32; i ? cx.lineTo(x, y) : cx.moveTo(x, y); }
        cx.stroke();
    }
    function spectrum(W, H, pal) {
        var f = Bus.freq; if (!f) return; var bars = 64, step = Math.floor(f.length * 0.6 / bars), bw = W / bars;
        for (var i = 0; i < bars; i++) {
            var m = 0; for (var j = 0; j < step; j++) m = Math.max(m, f[i * step + j]);
            var h = Math.pow(m / 255, 1.4) * H * 0.7;
            cx.fillStyle = i < bars * 0.4 ? pal[0] : (i < bars * 0.75 ? pal[1] : pal[2]);
            cx.fillRect(i * bw + 1, H - h, bw - 2, h);
        }
    }
    function geo(W, H, pal, inten) {
        var b = Bus.bands, cxp = W / 2, cyp = H / 2, t = tnow();
        for (var k = 0; k < 5; k++) {
            var amp = b[4 - k], r = (60 + k * 70) * (1 + amp * 1.6 * (0.5 + inten * 0.5));
            cx.strokeStyle = pal[k % 3]; cx.globalAlpha = 0.15 + amp * 0.6; cx.lineWidth = 2 + amp * 8;
            cx.beginPath(); var sides = 3 + k;
            for (var s = 0; s <= sides; s++) { var a = s / sides * Math.PI * 2 + t / 3000 * (k % 2 ? 1 : -1), x = cxp + Math.cos(a) * r, y = cyp + Math.sin(a) * r; s ? cx.lineTo(x, y) : cx.moveTo(x, y); }
            cx.closePath(); cx.stroke();
        }
        cx.globalAlpha = 1;
    }
    function generative(W, H, pal, inten) {
        var b = Bus.bands;
        if (parts.length < 70) for (var i = parts.length; i < 70; i++) parts.push({ x: (i * 97) % W, y: (i * 53) % H, a: (i % 12) / 12 * 6.28 });
        var energy = b[4] * 1.4 + b[2] + b[0];
        for (var p = 0; p < parts.length; p++) {
            var q = parts[p];
            q.a += 0.01 + b[2] * 0.08;
            q.x += Math.cos(q.a) * (0.5 + energy * 2.5); q.y += Math.sin(q.a * 1.3) * (0.5 + energy * 2.5);
            if (q.x < 0) q.x += W; if (q.x > W) q.x -= W; if (q.y < 0) q.y += H; if (q.y > H) q.y -= H;
            cx.fillStyle = pal[(q.x | 0) % 3]; cx.globalAlpha = 0.08 + energy * 0.25;
            cx.beginPath(); cx.arc(q.x, q.y, 2 + energy * 10, 0, 6.28); cx.fill();
        }
        cx.globalAlpha = 1;
    }
    var _t0 = 0;
    function tnow() { _t0 += 16.7; return _t0; }   // Date-free monotonic clock for rotation

    // ── frame ─────────────────────────────────────────────────────────────────
    function onFrame() {
        Bus = bus(); if (!Bus) return;
        layer1(Bus.bands);
        layer2();
    }

    function start() {
        grid = document.querySelector('[data-beatbox-grid]');
        canvas = document.querySelector('[data-beatbox-viz]');
        readSettings();
        document.addEventListener('beatbox:frame', onFrame);
        document.addEventListener('beatbox:settings', readSettings);
        document.addEventListener('beatbox:pause', function () { onFrame(); });  // settle Layer 1 to dim
        window.addEventListener('resize', resize, { passive: true });
        // hidden-tab safety: if the tab is backgrounded mid-play, keep Layer 1 coherent
        setInterval(function () { if (document.hidden && Bus && Bus.playing) layer1(Bus.bands); }, 250);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();

})();
// ===== SNAPSMACK EOF =====
