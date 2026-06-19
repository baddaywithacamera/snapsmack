/**
 * SNAPSMACK — Flag Wave Engine
 * ss-engine-flag-wave.js
 *
 * Full-viewport waving-flag background (spec: _spec/parade-flag-spec-v0.1.docx).
 * Built for PARADE's pride flags, but DATA-DRIVEN so any skin can fly any flag
 * (e.g. a Canada Day skin: red/white/red + a maple-leaf emblem). One engine,
 * every flag — selected by data attributes; no inline JS lives in a skin.
 *
 * Technique: a flat flag is painted once to an offscreen canvas, then each frame
 * is re-drawn as vertical slices shifted by a travelling sine wave (left pole →
 * free edge), with per-slice fold shading. Continuous and physics-plausible —
 * no CSS keyframe seam. Honours prefers-reduced-motion (slows to a near-still
 * drape) and pauses on a hidden tab. A frame-time watchdog coarsens the slice
 * width under load.
 *
 * MARKUP CONTRACT — mount on a container carrying [data-flag-wave]:
 *   data-flag        built-in flag: rainbow | bi | trans | canada           [rainbow]
 *   data-stripes     custom flag as JSON [["#hex",weight], …] (overrides data-flag)
 *   data-orientation stripe direction for custom flags: h | v                     [h]
 *   data-emblem      optional centre emblem image URL (drawn into the cloth)
 *   data-speed       wave speed 1–100 (slow drift → active ripple)                [30]
 *   data-amplitude   wave amplitude 1–100                                         [40]
 *   data-opacity     background opacity 0–100 (default bold/full)                 [100]
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
        canada:  { o: 'v', stripes: [['#FF0000', 1], ['#FFFFFF', 2], ['#FF0000', 1]], emblem: '' } // emblem via data-emblem (maple leaf)
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

        var omega   = 0.00045 * speedCfg;            // radians/ms-ish
        var ampFrac = (ampCfg / 100) * 0.16;          // fraction of height
        if (prefersReduced) omega *= 0.05;            // near-still drape

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
        var sliceW = 2;                                // logical px per slice (watchdog can raise)
        var emblemImg = null;

        function sizeToHost() {
            var w = host.clientWidth || window.innerWidth;
            var h = host.clientHeight || window.innerHeight;
            W = w; H = h;
            canvas.width  = Math.max(1, Math.round(w * dpr));
            canvas.height = Math.max(1, Math.round(h * dpr));
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            buildFlag();
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
                // Centre the emblem, scaled to ~38% of the short side.
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

        // ── Wave render ──────────────────────────────────────────────────
        var waveLen = 0;
        function computeWaveLen() { waveLen = Math.max(180, W / 1.6); }

        var lastFrame = now(), slowStreak = 0, raf = null;

        function frame(ts) {
            var t = ts || now();
            var dt = t - lastFrame; lastFrame = t;
            try {
                if (document.hidden) { raf = requestAnimationFrame(frame); return; }

                // watchdog: sustained slow frames → coarser slices
                if (dt > 40) { slowStreak++; } else { slowStreak = Math.max(0, slowStreak - 1); }
                if (slowStreak > 40 && sliceW < 6) { sliceW += 1; slowStreak = 0; }

                ctx.clearRect(0, 0, W, H);
                var ampPx = ampFrac * H;
                for (var x = 0; x < W; x += sliceW) {
                    var edge = x / (W || 1);                       // 0 at pole (left) → 1 at free edge
                    var p = (x / waveLen) * Math.PI * 2 - omega * t;
                    var dy = ampPx * edge * (Math.sin(p) + 0.35 * Math.sin(p * 1.7 + 0.6));
                    // slice of the flat flag, shifted vertically
                    ctx.drawImage(off,
                        x * dpr, 0, sliceW * dpr, H * dpr,
                        x, dy, sliceW, H);
                    // fold shading from the wave slope (derivative of sin)
                    var slope = Math.cos(p) * edge;
                    if (slope > 0) { ctx.fillStyle = 'rgba(255,255,255,' + (slope * 0.16).toFixed(3) + ')'; }
                    else           { ctx.fillStyle = 'rgba(0,0,0,'       + (-slope * 0.22).toFixed(3) + ')'; }
                    ctx.fillRect(x, dy, sliceW + 1, H);
                }
            } catch (e) { fault('frame', e); }
            raf = requestAnimationFrame(frame);
        }

        // ── Resize (debounced) ───────────────────────────────────────────
        var rzTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(rzTimer);
            rzTimer = setTimeout(function () { dpr = Math.min(window.devicePixelRatio || 1, 2); sizeToHost(); computeWaveLen(); }, 150);
        });

        // ── Go ───────────────────────────────────────────────────────────
        sizeToHost();
        computeWaveLen();
        raf = requestAnimationFrame(frame);
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    function now() { return (window.performance && performance.now) ? performance.now() : Date.now(); }
    function clamp(v, lo, hi, def) { if (isNaN(v)) return def; return Math.max(lo, Math.min(hi, v)); }

})();
// ===== SNAPSMACK EOF =====
