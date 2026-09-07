/**
 * SNAPSMACK — KOMPROMAT background engine (Layer 1)
 *
 * The RED SCARE background for the GRAMOFSMACK KOMPROMAT skin. A drop-in
 * replacement for ss-engine-jive-turkey.js: it reads the SAME `.jt-jive-turkey-bg`
 * config carrier (so the skin's profile page needs no change) and renders ONE
 * thing — a full-frame sunburst of alternating spears in the active colourway,
 * slowly revolving, with a hammer-and-sickle emblem (the public-domain ☭ glyph)
 * anchored lower-right. Flat Soviet-constructivist graphic, never black gaps.
 *
 * Reads from the carrier's dataset (set by skin-profile.php):
 *   data-jt-palette   JSON hex array — the active colourway's colours (spear red = [0])
 *   data-jt-field     hex — the active colourway cream/base (the alternate spear)
 *   data-jt-speed     1..100 — revolution speed (40 ≈ one turn per 60s)
 *
 * Self-contained. No fetch / storage. Respects prefers-reduced-motion (one
 * static frame) and pauses on document.hidden. Additive — touches no other
 * skin or engine.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    var RED_DEFAULT   = '#c0201a';
    var CREAM_DEFAULT  = '#efe3cd';
    var EMBLEM_GOLD    = '#e8c14f';
    var EMBLEM_INK     = '#4a0c09';
    var WEDGES         = 24;               // 12 red + 12 cream spears
    var HAMMER_SICKLE  = '☭';         // ☭  U+262D — public-domain emblem glyph

    function init() {
        var host = document.querySelector('.jt-jive-turkey-bg');
        if (!host) return;

        var cv = host.querySelector('canvas.jt-canvas');
        if (!cv) { cv = document.createElement('canvas'); cv.className = 'jt-canvas'; host.appendChild(cv); }
        var ctx = cv.getContext('2d');
        if (!ctx) return;

        function colours() {
            var red = RED_DEFAULT, cream = CREAM_DEFAULT;
            try { var pal = JSON.parse(host.dataset.jtPalette || '[]'); if (pal[0]) red = pal[0]; } catch (e) {}
            if (host.dataset.jtField) cream = host.dataset.jtField;
            return { red: red, cream: cream };
        }
        var C = colours();
        var speed = Math.max(1, Math.min(100, parseFloat(host.dataset.jtSpeed) || 40));

        var W = 0, H = 0;
        function size() {
            var dpr = Math.max(1, window.devicePixelRatio || 1);
            W = host.clientWidth || window.innerWidth;
            H = host.clientHeight || window.innerHeight;
            cv.width = Math.round(W * dpr);
            cv.height = Math.round(H * dpr);
            cv.style.width = W + 'px';
            cv.style.height = H + 'px';
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        }
        size();
        window.addEventListener('resize', size);

        function draw(ms) {
            var cx = W / 2, cy = H / 2;
            var R = Math.sqrt(W * W + H * H);          // from centre, past every corner → fills the frame
            ctx.fillStyle = C.cream;                    // base coat so a seam never reads as black
            ctx.fillRect(0, 0, W, H);

            var step = (Math.PI * 2) / WEDGES;
            var rot = (ms / 1000) * (Math.PI * 2 / 60) * (speed / 40);   // ~one revolution / 60s at speed 40
            for (var i = 0; i < WEDGES; i++) {
                var a0 = rot + i * step, a1 = rot + (i + 1) * step;
                ctx.beginPath();
                ctx.moveTo(cx, cy);
                ctx.lineTo(cx + R * Math.cos(a0), cy + R * Math.sin(a0));
                ctx.lineTo(cx + R * Math.cos(a1), cy + R * Math.sin(a1));
                ctx.closePath();
                ctx.fillStyle = (i % 2 === 0) ? C.red : C.cream;
                ctx.fill();
            }

            var ex = W * 0.80, ey = H * 0.70, es = Math.max(64, Math.min(W, H) * 0.34);
            ctx.beginPath();
            ctx.arc(ex, ey, es * 0.64, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(74,12,9,0.5)';        // soft dark disc so the emblem reads over the spears
            ctx.fill();
            ctx.font = '700 ' + Math.round(es) + 'px "Segoe UI Symbol","Noto Sans Symbols2","Apple Symbols","Symbola",sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.lineJoin = 'round';
            ctx.lineWidth = Math.max(2, es * 0.02);
            ctx.strokeStyle = EMBLEM_INK;
            ctx.strokeText(HAMMER_SICKLE, ex, ey);
            ctx.fillStyle = EMBLEM_GOLD;
            ctx.fillText(HAMMER_SICKLE, ex, ey);
        }

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var rafId = null;
        function frame(ts) { draw(ts); rafId = window.requestAnimationFrame(frame); }
        function stop() { if (rafId !== null) { window.cancelAnimationFrame(rafId); rafId = null; } }
        function start() { if (rafId === null) rafId = window.requestAnimationFrame(frame); }

        if (reduced) { draw(0); return; }
        document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
        if (!document.hidden) start();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
