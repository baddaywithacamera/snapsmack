/**
 * SNAPSMACK - RACETRACK ambient background (canvas)
 *
 * Long-exposure light trails lapping a rounded-rectangle circuit inset from
 * the viewport edges — car light-trails at night, the way a photographer
 * would shoot them. Cars run staggered lanes (slightly smaller circuits) for
 * depth; trails persist via a destination-out fade (the proven PARADE
 * fireworks technique). Generic engine: any skin can adopt it by emitting a
 * [data-racetrack] carrier. First consumer: INSTANT CAMERA (ic_bg_mode).
 *
 * Reads from the [data-racetrack] carrier:
 *   data-rt-speed    (1..100  lap speed)      data-rt-count   (1..24 cars)
 *   data-rt-trail    (5..100  persistence)    data-rt-width   (1..12 px)
 *   data-rt-opacity  (5..100  %)              data-rt-palette (JSON hex array)
 *
 * Honours prefers-reduced-motion: draws a single static circuit, no loop.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

    function init() {
        var host = document.querySelector('[data-racetrack]');
        if (!host) return;

        function num(attr, def) {
            var v = parseFloat(host.getAttribute(attr));
            return isNaN(v) ? def : v;
        }

        var speed   = clamp(num('data-rt-speed', 40), 1, 100);
        var count   = clamp(Math.round(num('data-rt-count', 8)), 1, 24);
        var trail   = clamp(num('data-rt-trail', 55), 5, 100);
        var lineW   = clamp(num('data-rt-width', 3), 1, 12);
        var opacity = clamp(num('data-rt-opacity', 70), 5, 100) / 100;

        var PAL = ['#ff2d95', '#00e5ff', '#ffe600', '#7cff00', '#ff6a00', '#b967ff'];
        try {
            var raw = JSON.parse(host.getAttribute('data-rt-palette') || '[]');
            if (Array.isArray(raw) && raw.length >= 1) PAL = raw;
        } catch (e) { /* keep default palette */ }

        var cv = host.querySelector('canvas.ic-canvas');
        if (!cv) { cv = document.createElement('canvas'); cv.className = 'ic-canvas'; host.appendChild(cv); }
        var ctx = cv.getContext('2d');
        if (!ctx) return;

        // Half-resolution canvas stretched by CSS — same perf posture as the
        // PARADE fireworks engine; the stretch + blur reads as glow.
        var SC = 0.5;
        function sizeCanvas() {
            cv.width  = Math.max(1, Math.round(window.innerWidth  * SC));
            cv.height = Math.max(1, Math.round(window.innerHeight * SC));
        }
        window.addEventListener('resize', sizeCanvas);
        sizeCanvas();

        // ── The circuit: rounded rect inset from the edges. Each lane runs a
        // slightly smaller circuit so trails never overlap exactly. ──────────
        function circuit(lane) {
            var w = cv.width, h = cv.height;
            var inset = Math.min(w, h) * (0.10 + lane * 0.035);
            var rw = Math.max(10, w - inset * 2), rh = Math.max(10, h - inset * 2);
            var r  = Math.min(rw, rh) * 0.22;
            var sw = rw - 2 * r, sh = rh - 2 * r;
            var arc = Math.PI * r / 2;
            return { x: inset, y: inset, rw: rw, rh: rh, r: r, sw: sw, sh: sh,
                     arc: arc, per: 2 * sw + 2 * sh + 4 * arc };
        }

        // Distance along the perimeter → point. Clockwise from the top edge.
        function pointAt(c, d) {
            d = ((d % c.per) + c.per) % c.per;
            var x = c.x, y = c.y, r = c.r, a;
            if (d < c.sw) return { x: x + r + d, y: y };                        // top
            d -= c.sw;
            if (d < c.arc) {                                                    // top-right
                a = -Math.PI / 2 + (d / c.arc) * Math.PI / 2;
                return { x: x + c.rw - r + Math.cos(a) * r, y: y + r + Math.sin(a) * r };
            }
            d -= c.arc;
            if (d < c.sh) return { x: x + c.rw, y: y + r + d };                 // right
            d -= c.sh;
            if (d < c.arc) {                                                    // bottom-right
                a = (d / c.arc) * Math.PI / 2;
                return { x: x + c.rw - r + Math.cos(a) * r, y: y + c.rh - r + Math.sin(a) * r };
            }
            d -= c.arc;
            if (d < c.sw) return { x: x + c.rw - r - d, y: y + c.rh };          // bottom
            d -= c.sw;
            if (d < c.arc) {                                                    // bottom-left
                a = Math.PI / 2 + (d / c.arc) * Math.PI / 2;
                return { x: x + r + Math.cos(a) * r, y: y + c.rh - r + Math.sin(a) * r };
            }
            d -= c.arc;
            if (d < c.sh) return { x: x, y: y + c.rh - r - d };                 // left
            d -= c.sh;
            a = Math.PI + (d / c.arc) * Math.PI / 2;                            // top-left
            return { x: x + r + Math.cos(a) * r, y: y + r + Math.sin(a) * r };
        }

        var cars = [];
        for (var i = 0; i < count; i++) {
            cars.push({
                lane: i % 4,
                frac: Math.random(),                 // position as perimeter fraction
                v: 0.75 + Math.random() * 0.5,       // per-car speed jitter
                col: PAL[i % PAL.length]
            });
        }

        // Laps per second: ~1/50s crawl at speed 1 up to ~1/5.5s at speed 100.
        var lapsPerSec = 0.02 + (speed / 100) * 0.16;
        // Trail persistence: longer trail = gentler destination-out fade.
        var fadeA = clamp(0.5 - (trail / 100) * 0.47, 0.03, 0.5);

        var reduced = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) {
            // Static long-exposure: stroke each lane's full circuit once, faintly.
            for (var s = 0; s < cars.length; s++) {
                var cc = circuit(cars[s].lane), steps = 160, p0 = pointAt(cc, 0);
                ctx.strokeStyle = cars[s].col;
                ctx.globalAlpha = opacity * 0.25;
                ctx.lineWidth = lineW * SC;
                ctx.beginPath(); ctx.moveTo(p0.x, p0.y);
                for (var q = 1; q <= steps; q++) {
                    var pq = pointAt(cc, cc.per * q / steps);
                    ctx.lineTo(pq.x, pq.y);
                }
                ctx.stroke();
            }
            return; // no animation loop
        }

        var last = 0;
        function frame(ts) {
            if (!last) last = ts;
            var dt = Math.min(0.05, (ts - last) / 1000);
            last = ts;

            // Fade existing trails.
            ctx.globalCompositeOperation = 'destination-out';
            ctx.globalAlpha = 1;
            ctx.shadowBlur = 0;
            ctx.fillStyle = 'rgba(0,0,0,' + fadeA + ')';
            ctx.fillRect(0, 0, cv.width, cv.height);

            // Advance + draw each car's head segment.
            ctx.globalCompositeOperation = 'lighter';
            ctx.lineCap = 'round';
            for (var j = 0; j < cars.length; j++) {
                var car = cars[j], c = circuit(car.lane);
                var newFrac = car.frac + lapsPerSec * car.v * dt;
                var p1 = pointAt(c, car.frac * c.per);
                var p2 = pointAt(c, newFrac * c.per);
                car.frac = newFrac % 1;

                ctx.strokeStyle = car.col;
                ctx.globalAlpha = opacity;
                ctx.lineWidth = Math.max(0.5, lineW * SC * (1 - car.lane * 0.12));
                ctx.shadowColor = car.col;
                ctx.shadowBlur = 6;
                ctx.beginPath();
                ctx.moveTo(p1.x, p1.y);
                ctx.lineTo(p2.x, p2.y);
                ctx.stroke();
            }
            requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
// ===== SNAPSMACK EOF =====
