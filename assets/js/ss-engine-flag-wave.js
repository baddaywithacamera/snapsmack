/**
 * SNAPSMACK — Flag Wave Engine
 * ss-engine-flag-wave.js
 *
 * Full-viewport waving-flag background. Built for PARADE's pride flags, but
 * DATA-DRIVEN so any skin can fly any flag (e.g. a Canada Day skin). One engine,
 * every flag — selected by data attributes; no inline JS lives in a skin.
 *
 * Technique: the flat flag is painted once to an offscreen canvas, then drawn
 * per-frame as vertical slices vertically offset by a travelling sine — corners
 * lead, ripple amplitude grows toward the free edge — with light fold shading.
 * Reliable, dpr-safe, cheap. Honours prefers-reduced-motion (near-still),
 * pauses on a hidden tab, and a frame-time watchdog widens the slice under load.
 *
 * (A physics "cloth" mode was prototyped and then removed — it never read right.
 *  This engine is the lighter travelling-sine wave, which is the only mode.)
 *
 * MARKUP CONTRACT — mount on a container carrying [data-flag-wave]:
 *   data-flag        built-in: rainbow | bi | trans | canada                  [rainbow]
 *   data-stripes     custom flag as JSON [["#hex",weight], …] (overrides data-flag)
 *   data-orientation stripe direction for custom flags: h | v                       [h]
 *   data-emblem      optional centre emblem image URL (drawn into the flag)
 *   data-speed       wind speed 1–100 (slow drift → active flap)                   [30]
 *   data-amplitude   wind strength 1–100                                           [40]
 *   data-opacity     background opacity 0–100                                     [100]
 * The skin makes the container full-viewport + z-index below all content.
 *
 * Faults fail silently on the public UI; diagnostics go to the console only.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    var host = document.querySelector('[data-flag-wave]');
    if (!host) return;

    function fault(where, err) {
        try { if (window.console && console.error) console.error('[flag-wave] ' + where, err); } catch (e) {}
    }

    // Built-in flag registry. weight = relative stripe size. (Bi = 40/20/40.)
    var FLAGS = {
        rainbow: { o: 'h', stripes: [['#E40303', 1], ['#FF8C00', 1], ['#FFED00', 1], ['#008026', 1], ['#004DFF', 1], ['#750787', 1]] },
        bi:      { o: 'h', stripes: [['#D60270', 2], ['#9B4F96', 1], ['#0038A8', 2]] },
        trans:   { o: 'h', stripes: [['#55CDFC', 1], ['#F7A8B8', 1], ['#FFFFFF', 1], ['#F7A8B8', 1], ['#55CDFC', 1]] },
        canada:  { o: 'v', stripes: [['#FF0000', 1], ['#FFFFFF', 2], ['#FF0000', 1]], emblem: '' }
    };

    try { boot(); } catch (e) { fault('boot', e); }

    function boot() {
        var d = host.dataset;

        // ── Resolve the flag definition ──────────────────────────────────
        var flag = FLAGS[(d.flag || 'rainbow')] || FLAGS.rainbow;
        var stripes = flag.stripes;
        var orient  = flag.o || 'h';
        if (d.stripes) {
            try {
                var custom = JSON.parse(d.stripes);
                if (Array.isArray(custom) && custom.length) {
                    stripes = custom.map(function (s) { return [String(s[0]), Math.max(0.01, +s[1] || 1)]; });
                    orient  = (d.orientation === 'v') ? 'v' : 'h';
                }
            } catch (e) { fault('parse data-stripes', e); }
        }
        var emblemUrl = d.emblem || flag.emblem || '';

        var prefersReduced = window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // ── Config ───────────────────────────────────────────────────────
        var speedCfg = clamp(+d.speed, 1, 100, 30);
        var ampCfg   = clamp(+d.amplitude, 1, 100, 40);
        var opacity  = clamp(+d.opacity, 0, 100, 100) / 100;

        var omega   = 0.00045 * speedCfg;          // travelling-sine cadence
        var ampFrac = (ampCfg / 100) * 0.16;       // ripple depth as a fraction of H
        if (prefersReduced) omega *= 0.05;
        var waveLen = 0;

        // ── Canvas ───────────────────────────────────────────────────────
        var canvas = document.createElement('canvas');
        canvas.className = 'flag-wave-canvas';
        canvas.style.cssText = 'display:block;width:100%;height:100%;';
        canvas.style.opacity = String(opacity);
        host.appendChild(canvas);
        var ctx = canvas.getContext('2d');

        var off = document.createElement('canvas');   // the flat flag
        var offctx = off.getContext('2d');

        var W = 0, H = 0, dpr = Math.min(window.devicePixelRatio || 1, 2);
        var sliceW = 4;                                // logical px per render slice (watchdog raises)
        var emblemImg = null;

        function sizeToHost() {
            var w = host.clientWidth || window.innerWidth;
            var h = host.clientHeight || window.innerHeight;
            W = w; H = h;
            canvas.width  = Math.max(1, Math.round(w * dpr));
            canvas.height = Math.max(1, Math.round(h * dpr));
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            buildFlag();
            waveLen = Math.max(180, W / 1.6);
        }

        // Paint the flat flag (stripes + optional emblem) to the offscreen.
        function buildFlag() {
            off.width  = Math.max(1, Math.round(W * dpr));
            off.height = Math.max(1, Math.round(H * dpr));
            offctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            offctx.clearRect(0, 0, W, H);

            var total = 0, i;
            for (i = 0; i < stripes.length; i++) total += stripes[i][1];
            var pos = 0;
            for (i = 0; i < stripes.length; i++) {
                offctx.fillStyle = stripes[i][0];
                var frac = stripes[i][1] / total;
                if (orient === 'v') {
                    var bw = frac * W;
                    offctx.fillRect(Math.round(pos), 0, Math.ceil(bw) + 1, H);
                    pos += bw;
                } else {
                    var bh = frac * H;
                    offctx.fillRect(0, Math.round(pos), W, Math.ceil(bh) + 1);
                    pos += bh;
                }
            }

            if (emblemImg) {
                var s = Math.min(W, H) * 0.38;
                var iw = emblemImg.naturalWidth || s, ih = emblemImg.naturalHeight || s;
                var scale = s / Math.max(iw, ih);
                var ew = iw * scale, eh = ih * scale;
                try { offctx.drawImage(emblemImg, (W - ew) / 2, (H - eh) / 2, ew, eh); } catch (e) { fault('emblem draw', e); }
            }
        }

        if (emblemUrl) {
            emblemImg = new Image();
            emblemImg.onload = function () { buildFlag(); };
            emblemImg.onerror = function () { fault('emblem load', emblemUrl); emblemImg = null; };
            emblemImg.src = emblemUrl;
        }

        // ── Render (travelling sine) ──────────────────────────────────────
        function renderWave(t) {
            ctx.clearRect(0, 0, W, H);
            var ampPx = ampFrac * H;
            for (var x = 0; x < W; x += sliceW) {
                var edge = x / (W || 1);
                var p = (x / waveLen) * Math.PI * 2 - omega * t;
                var dy = ampPx * edge * (Math.sin(p) + 0.35 * Math.sin(p * 1.7 + 0.6));
                ctx.drawImage(off, x * dpr, 0, sliceW * dpr, H * dpr, x, dy, sliceW, H);
                var sl = Math.cos(p) * edge;
                ctx.fillStyle = sl > 0 ? 'rgba(255,255,255,' + (sl * 0.16).toFixed(3) + ')'
                                       : 'rgba(0,0,0,' + (-sl * 0.22).toFixed(3) + ')';
                ctx.fillRect(x, dy, sliceW + 1, H);
            }
        }

        // ── Loop + watchdog ──────────────────────────────────────────────
        var lastFrame = now(), slowStreak = 0, raf = null;

        function frame(ts) {
            var t = ts || now();
            var dt = t - lastFrame; lastFrame = t;
            try {
                if (document.hidden) { raf = requestAnimationFrame(frame); return; }

                if (dt > 40) { slowStreak++; } else { slowStreak = Math.max(0, slowStreak - 1); }
                if (slowStreak > 40 && sliceW < 10) { sliceW += 1; slowStreak = 0; }

                renderWave(t);
            } catch (e) { fault('frame', e); }
            raf = requestAnimationFrame(frame);
        }

        // ── Resize (debounced) ───────────────────────────────────────────
        var rzTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(rzTimer);
            rzTimer = setTimeout(function () {
                dpr = Math.min(window.devicePixelRatio || 1, 2);
                sizeToHost();
            }, 150);
        });

        // ── Go ───────────────────────────────────────────────────────────
        sizeToHost();
        raf = requestAnimationFrame(frame);
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    function now() { return (window.performance && performance.now) ? performance.now() : Date.now(); }
    function clamp(v, lo, hi, def) { if (isNaN(v)) return def; return Math.max(lo, Math.min(hi, v)); }

})();
// ===== SNAPSMACK EOF =====
