/**
 * SNAPSMACK — KOMPROMAT background engine (Layer 1)
 *
 * The RED SCARE background for the GRAMOFSMACK KOMPROMAT skin. A drop-in
 * replacement for ss-engine-jive-turkey.js: it reads the SAME `.jt-jive-turkey-bg`
 * config carrier (so the skin's profile page needs no change) and renders ONE
 * thing — a full-frame sunburst of alternating spears revolving around a
 * hammer-and-sickle emblem, with the burst ANCHORED DIRECTLY BEHIND the emblem
 * so the rays appear to radiate out of it.
 *
 * The emblem is drawn as vector paths (public-domain Soviet hammer-and-sickle
 * device), never a font glyph — no dependency on a symbol font being installed.
 *
 * Reads from the carrier's dataset (set by skin-profile.php):
 *   data-jt-palette   JSON hex array — active colourway colours (spear red = [0])
 *   data-jt-field     hex — active colourway cream/base (the alternate spear)
 *   data-jt-speed     1..100 — revolution speed (40 = one turn per 60s)
 *   data-jt-anchor    "br"|"bl"|"tr"|"tl"|"c" — where the emblem/anchor sits
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
    var CREAM_DEFAULT = '#efe3cd';
    var GOLD_DEFAULT  = '#e8c14f';
    var INK           = '#4a0c09';
    var WEDGES        = 24;          // 12 red + 12 cream spears
    var TURN_SECONDS  = 60;          // one full revolution at speed 40

    var ANCHORS = {
        br: [0.76, 0.72], bl: [0.24, 0.72],
        tr: [0.76, 0.28], tl: [0.24, 0.28],
        c:  [0.50, 0.50]
    };

    /* Hammer-and-sickle drawn in a local space roughly -60..60, origin at the
       device's optical centre. Flat graphic: solid fill + dark keyline. */
    function emblem(ctx, gold) {
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        ctx.strokeStyle = INK;
        ctx.lineWidth = 4;
        ctx.fillStyle = gold;

        // --- sickle: a THIN crescent blade (concentric arcs) tapering to a
        //     point at the upper right, with its handle dropping bottom-left ---
        ctx.beginPath();
        ctx.moveTo(-56, 26);
        ctx.lineTo(-40, 30);
        ctx.lineTo(-30, 74);
        ctx.lineTo(-50, 78);
        ctx.closePath();
        ctx.fill(); ctx.stroke();                       // handle

        ctx.beginPath();
        ctx.arc(0, 26, 56, Math.PI, Math.PI * 1.90, false);   // outer edge
        ctx.arc(0, 26, 42, Math.PI * 1.82, Math.PI, true);    // inner edge (tip tapers)
        ctx.closePath();
        ctx.fill(); ctx.stroke();                       // blade

        // --- hammer: crossed on the diagonal, drawn last so its keyline
        //     separates it cleanly from the blade behind it ---
        ctx.save();
        ctx.translate(-6, 34);
        ctx.rotate(-Math.PI / 4);
        ctx.beginPath();
        ctx.rect(-54, -6, 74, 12);                      // handle
        ctx.fill(); ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(16, -18);
        ctx.lineTo(48, -14);
        ctx.lineTo(48, 14);
        ctx.lineTo(16, 18);
        ctx.closePath();                                // head (slight taper)
        ctx.fill(); ctx.stroke();
        ctx.restore();
    }

    function init() {
        var host = document.querySelector('.jt-jive-turkey-bg');
        if (!host) return;

        var cv = host.querySelector('canvas.jt-canvas');
        if (!cv) { cv = document.createElement('canvas'); cv.className = 'jt-canvas'; host.appendChild(cv); }
        var ctx = cv.getContext('2d');
        if (!ctx) return;

        var red = RED_DEFAULT, cream = CREAM_DEFAULT, gold = GOLD_DEFAULT;
        try {
            var pal = JSON.parse(host.dataset.jtPalette || '[]');
            if (pal[0]) red = pal[0];
            if (pal[2]) gold = pal[2];
        } catch (e) {}
        if (host.dataset.jtField) cream = host.dataset.jtField;

        var speed = Math.max(1, Math.min(100, parseFloat(host.dataset.jtSpeed) || 40));
        var anchor = ANCHORS[host.dataset.jtAnchor] || ANCHORS.br;

        // Artwork wins when a src is given; otherwise the emblem stays OFF
        // unless explicitly asked for ('vector'). The spears alone are the
        // strong graphic — no placeholder device is drawn by default.
        var emblemMode = host.dataset.jtEmblem
            || (host.dataset.jtEmblemSrc ? 'art' : 'off');
        var emblemScale = Math.max(0.05, Math.min(0.9,
            parseFloat(host.dataset.jtEmblemScale) || 0.34)) * 0.0094;
        var art = null;
        if (host.dataset.jtEmblemSrc) {
            art = new Image();
            art.src = host.dataset.jtEmblemSrc;   // same-origin skin asset
        }

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
            var ax = W * anchor[0], ay = H * anchor[1];

            // Radius = distance to the FARTHEST corner, so spears always reach
            // every edge from an off-centre anchor — the frame is never short.
            var R = 0, cs = [[0, 0], [W, 0], [0, H], [W, H]];
            for (var k = 0; k < 4; k++) {
                var dx = cs[k][0] - ax, dy = cs[k][1] - ay;
                R = Math.max(R, Math.sqrt(dx * dx + dy * dy));
            }

            ctx.fillStyle = cream;                 // base coat — a seam never reads black
            ctx.fillRect(0, 0, W, H);

            var step = (Math.PI * 2) / WEDGES;
            var rot = (ms / 1000) * (Math.PI * 2 / TURN_SECONDS) * (speed / 40);
            for (var i = 0; i < WEDGES; i += 2) {  // paint only the red spears
                var a0 = rot + i * step, a1 = a0 + step;
                ctx.beginPath();
                ctx.moveTo(ax, ay);
                ctx.lineTo(ax + R * Math.cos(a0), ay + R * Math.sin(a0));
                ctx.lineTo(ax + R * Math.cos(a1), ay + R * Math.sin(a1));
                ctx.closePath();
                ctx.fillStyle = red;
                ctx.fill();
            }

            // EMBLEM — three ways, in order of preference:
            //   1. an artwork file (data-jt-emblem-src) — a real PD hammer-and-
            //      sickle SVG/PNG drops in and is drawn as-is. Best result.
            //   2. the built-in vector (data-jt-emblem="vector").
            //   3. nothing (data-jt-emblem="off") — the spears alone.
            if (emblemMode !== 'off') {
                var s = Math.min(W, H) * emblemScale;
                if (art && art.complete && art.naturalWidth) {
                    var aw = art.naturalWidth, ah = art.naturalHeight;
                    var k = (Math.min(W, H) * emblemScale * 150) / Math.max(aw, ah);
                    ctx.drawImage(art, ax - aw * k / 2, ay - ah * k / 2, aw * k, ah * k);
                } else if (emblemMode === 'vector') {
                    ctx.save();
                    ctx.translate(ax, ay);
                    ctx.scale(s, s);
                    emblem(ctx, gold);
                    ctx.restore();
                }
            }
        }

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var rafId = null;
        function frame(ts) { draw(ts); rafId = window.requestAnimationFrame(frame); }
        function stop() { if (rafId !== null) { window.cancelAnimationFrame(rafId); rafId = null; } }
        function start() { if (rafId === null) rafId = window.requestAnimationFrame(frame); }

        draw(0);   // paint frame one immediately — never show an empty canvas
                   // while waiting for the first rAF (a tab opened in the
                   // background gets the full graphic, not a black hole).
        if (reduced) return;
        document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
        if (!document.hidden) start();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
