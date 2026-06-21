/**
 * SNAPSMACK — Flag Wave Engine (cloth)
 * ss-engine-flag-wave.js
 *
 * Full-viewport waving-flag background. Built for PARADE's pride flags, but
 * DATA-DRIVEN so any skin can fly any flag (e.g. a Canada Day skin). One engine,
 * every flag — selected by data attributes; no inline JS lives in a skin.
 *
 * Technique (v2): a real 2D mass-spring CLOTH simulated with Verlet integration.
 * The pole edge (left) is pinned; gravity gives natural drape and a travelling
 * wind force flaps the free edge — corners lead, folds emerge from the physics
 * rather than a fixed sine. The flat flag is painted once to an offscreen canvas
 * and sampled per-frame through the cloth mesh via vertical slices (reliable,
 * dpr-safe, no skewed-quad math). Honours prefers-reduced-motion (near-still
 * drape), pauses on a hidden tab, and a frame-time watchdog coarsens the slice
 * width AND mesh under load. Physics is damped + NaN-guarded so it cannot blow up.
 *
 * MARKUP CONTRACT — mount on a container carrying [data-flag-wave]:
 *   data-flag        built-in: rainbow | bi | trans | canada                  [rainbow]
 *   data-stripes     custom flag as JSON [["#hex",weight], …] (overrides data-flag)
 *   data-orientation stripe direction for custom flags: h | v                       [h]
 *   data-emblem      optional centre emblem image URL (drawn into the cloth)
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

        // travelling-wind frequency. Kept near the wave engine's proven cadence
        // (omega = 0.00045·speed). The old 0.0016·speed flapped ~3.5× faster,
        // so under the cloth's damping + constraints each gust cancelled the
        // previous one before any billow could build — leaving only gravity, so
        // the flag just hung limp. Slower cadence lets the wind accumulate.
        var windFreq = 0.0005 * speedCfg;             // travelling-wind frequency
        var windAmp  = (ampCfg / 100);                // 0..1 wind strength scalar
        if (prefersReduced) { windFreq *= 0.06; windAmp *= 0.25; }

        // Motion mode: 'cloth' (Verlet sim, default) or 'wave' (the original,
        // lighter travelling-sine engine — kept so users can choose).
        var motion  = (d.motion === 'wave') ? 'wave' : 'cloth';
        var omega   = 0.00045 * speedCfg;          // wave-mode only
        var ampFrac = (ampCfg / 100) * 0.16;       // wave-mode only
        if (prefersReduced && motion === 'wave') omega *= 0.05;
        var waveLen = 0;                            // wave-mode only

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

        // ── Cloth mesh ───────────────────────────────────────────────────
        // COLS along the fly (pole→free), ROWS down the hoist. Flat arrays for
        // speed; index = r*COLS + c. Left column (c===0) is pinned to the pole.
        var COLS = 26, ROWS = 14;
        var px, py, ox, oy, restX, restY;             // positions, previous, rest lengths

        function buildMesh() {
            px = new Float64Array(ROWS * COLS);
            py = new Float64Array(ROWS * COLS);
            ox = new Float64Array(ROWS * COLS);
            oy = new Float64Array(ROWS * COLS);
            restX = W / (COLS - 1);
            restY = H / (ROWS - 1);
            for (var r = 0; r < ROWS; r++) {
                for (var c = 0; c < COLS; c++) {
                    var i = r * COLS + c;
                    px[i] = ox[i] = c * restX;
                    py[i] = oy[i] = r * restY;
                }
            }
        }

        function sizeToHost() {
            var w = host.clientWidth || window.innerWidth;
            var h = host.clientHeight || window.innerHeight;
            W = w; H = h;
            canvas.width  = Math.max(1, Math.round(w * dpr));
            canvas.height = Math.max(1, Math.round(h * dpr));
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            buildFlag();
            if (motion === 'cloth') buildMesh();
            else waveLen = Math.max(180, W / 1.6);
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

        // ── Physics (Verlet) ─────────────────────────────────────────────
        var DAMP = 0.985;          // velocity retention
        var ITERS = 3;             // constraint relaxation passes
        var meshBad = false;

        function simulate(dt, t) {
            // dt is already clamped by the caller. Normalise to ~16ms steps so
            // tuning is frame-rate independent without risking an explosion.
            var step = Math.min(dt, 32) / 16;
            // Light-fabric drape. The old 0.045 sagged the whole mesh below the
            // pole so the flag "hung down and jiggled" instead of flying out;
            // a flag is light and wind-dominated, so keep gravity small.
            var grav = 0.010 * step * step;                 // downward drape
            var i, r, c;

            // Integrate (skip pinned pole column).
            for (r = 0; r < ROWS; r++) {
                for (c = 1; c < COLS; c++) {
                    i = r * COLS + c;
                    var edge = c / (COLS - 1);                // 0 pole → 1 free edge
                    // Travelling wind: a phase that moves along the fly, modulated
                    // down the hoist so top and bottom flap out of sync.
                    var phase = (c * 0.55) - (windFreq * t) + (r * 0.30);
                    var gust  = 0.6 + 0.4 * Math.sin(windFreq * t * 0.37 + c * 0.2);
                    var wy = windAmp * edge * gust * 1.3 * Math.sin(phase) * step * step * H * 0.018;
                    var wx = windAmp * edge * 0.5 * Math.cos(phase) * step * step * W * 0.004;

                    var vx = (px[i] - ox[i]) * DAMP;
                    var vy = (py[i] - oy[i]) * DAMP;
                    ox[i] = px[i]; oy[i] = py[i];
                    px[i] += vx + wx;
                    py[i] += vy + grav * restY + wy;
                }
            }

            // Constraints: keep neighbours near rest length; re-pin the pole.
            for (var k = 0; k < ITERS; k++) {
                for (r = 0; r < ROWS; r++) {
                    for (c = 0; c < COLS; c++) {
                        i = r * COLS + c;
                        if (c + 1 < COLS) relax(i, i + 1, restX);
                        if (r + 1 < ROWS) relax(i, i + COLS, restY);
                    }
                }
                // Pin the pole column to its original hoist positions.
                for (r = 0; r < ROWS; r++) {
                    i = r * COLS;
                    px[i] = 0; py[i] = r * restY;
                    ox[i] = 0; oy[i] = r * restY;
                }
            }

            // NaN guard: if the sim ever destabilises, rebuild the flat mesh.
            if (!isFinite(px[ROWS * COLS - 1]) || !isFinite(py[ROWS * COLS - 1])) {
                meshBad = true;
            }
            if (meshBad) { buildMesh(); meshBad = false; }
        }

        function relax(a, b, rest) {
            var dx = px[b] - px[a], dy = py[b] - py[a];
            var dist = Math.sqrt(dx * dx + dy * dy) || rest;
            var diff = (rest - dist) / dist * 0.5;
            var ox2 = dx * diff, oy2 = dy * diff;
            var aPin = (a % COLS) === 0, bPin = (b % COLS) === 0;
            if (!aPin) { px[a] -= ox2; py[a] -= oy2; }
            if (!bPin) { px[b] += ox2; py[b] += oy2; }
        }

        // Sample the mesh's hoist profile (top→bottom dest-Y) at fly-fraction fx.
        // Returns dest-Y for each of RENDER_LEVELS evenly-spaced hoist levels.
        var RENDER_LEVELS = 4;
        var prof = new Float64Array(RENDER_LEVELS);
        function sampleProfile(fx) {
            var cf = fx * (COLS - 1);
            var c0 = Math.min(COLS - 2, Math.floor(cf));
            var ct = cf - c0;
            for (var lvl = 0; lvl < RENDER_LEVELS; lvl++) {
                var rf = (lvl / (RENDER_LEVELS - 1)) * (ROWS - 1);
                var r0 = Math.min(ROWS - 2, Math.floor(rf));
                var rt = rf - r0;
                var i00 = r0 * COLS + c0, i01 = i00 + 1;
                var i10 = i00 + COLS,     i11 = i10 + 1;
                var top = py[i00] + (py[i01] - py[i00]) * ct;
                var bot = py[i10] + (py[i11] - py[i10]) * ct;
                prof[lvl] = top + (bot - top) * rt;
            }
        }

        // ── Render ───────────────────────────────────────────────────────
        function render() {
            ctx.clearRect(0, 0, W, H);
            var bandSrcH = H / (RENDER_LEVELS - 1);
            var prevTopY = 0;
            for (var x = 0; x < W; x += sliceW) {
                sampleProfile(x / (W || 1));
                // Fold shading: brighten/darken from how fast the top edge rises.
                var slope = (prof[0] - prevTopY);
                prevTopY = prof[0];
                for (var lvl = 0; lvl < RENDER_LEVELS - 1; lvl++) {
                    var dTop = prof[lvl], dBot = prof[lvl + 1];
                    var dH = dBot - dTop;
                    if (dH <= 0.1) continue;
                    ctx.drawImage(off,
                        x * dpr, (lvl * bandSrcH) * dpr, sliceW * dpr, bandSrcH * dpr,
                        x, dTop, sliceW + 1, dH);
                }
                if (slope < -0.15)      { ctx.fillStyle = 'rgba(255,255,255,' + Math.min(0.18, -slope * 0.05).toFixed(3) + ')'; ctx.fillRect(x, prof[0], sliceW + 1, H); }
                else if (slope > 0.15)  { ctx.fillStyle = 'rgba(0,0,0,' + Math.min(0.22, slope * 0.06).toFixed(3) + ')'; ctx.fillRect(x, prof[0], sliceW + 1, H); }
            }
        }

        // ── Wave mode (original lighter engine) ──────────────────────────
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

                if (motion === 'cloth') { simulate(dt, t); render(); }
                else { renderWave(t); }
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
