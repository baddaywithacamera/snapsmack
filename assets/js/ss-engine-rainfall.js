/**
 * SNAPSMACK - RAINFALL ambient background (canvas)
 *
 * Rain on the window: falling streaks with wind angle, three parallax depth
 * layers (nearer drops run faster, thicker, brighter), and subtle splash
 * ticks where drops land at the bottom edge. Generic engine: any skin can
 * adopt it by emitting a [data-rainfall] carrier. First consumer:
 * INSTANT CAMERA (ic_bg_mode).
 *
 * Reads from the [data-rainfall] carrier:
 *   data-rf-density   (1..100)          data-rf-speed     (1..100)
 *   data-rf-angle     (-45..45 deg)     data-rf-thickness (1..8 px)
 *   data-rf-color     (hex)             data-rf-opacity   (5..100 %)
 *
 * Honours prefers-reduced-motion: draws a single static frame, no loop.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

    function hex2rgb(h) {
        h = String(h).replace('#', '');
        if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
        var n = parseInt(h, 16);
        if (isNaN(n) || h.length !== 6) return [111, 168, 220]; // fallback #6fa8dc
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }

    function init() {
        var host = document.querySelector('[data-rainfall]');
        if (!host) return;

        function num(attr, def) {
            var v = parseFloat(host.getAttribute(attr));
            return isNaN(v) ? def : v;
        }

        var density   = clamp(num('data-rf-density', 45), 1, 100);
        var speedIn   = clamp(num('data-rf-speed', 50), 1, 100);
        var angleDeg  = clamp(num('data-rf-angle', -12), -45, 45);
        var thickness = clamp(num('data-rf-thickness', 2), 1, 8);
        var rgb       = hex2rgb(host.getAttribute('data-rf-color') || '#6fa8dc');
        var opacity   = clamp(num('data-rf-opacity', 50), 5, 100) / 100;

        var cv = host.querySelector('canvas.ic-canvas');
        if (!cv) { cv = document.createElement('canvas'); cv.className = 'ic-canvas'; host.appendChild(cv); }
        var ctx = cv.getContext('2d');
        if (!ctx) return;

        // Half-resolution canvas stretched by CSS (PARADE engine posture).
        var SC = 0.5;
        function sizeCanvas() {
            cv.width  = Math.max(1, Math.round(window.innerWidth  * SC));
            cv.height = Math.max(1, Math.round(window.innerHeight * SC));
        }
        window.addEventListener('resize', sizeCanvas);
        sizeCanvas();

        // Wind: angle 0 = straight down; negative leans left, positive right.
        var rad = angleDeg * Math.PI / 180;
        var dirX = Math.sin(rad), dirY = Math.cos(rad);
        // Base fall speed in canvas px/sec: gentle drizzle → hard rain.
        var baseV = (120 + (speedIn / 100) * 680) * SC;

        function targetCount() {
            var byArea = (cv.width * cv.height) / 4000;
            return Math.round(clamp(byArea, 30, 400) * (density / 100)) || 1;
        }

        function makeDrop(anywhere) {
            var z = 0.4 + Math.random() * 0.6; // depth: far 0.4 … near 1.0
            return {
                x: Math.random() * (cv.width * 1.4) - cv.width * 0.2, // overshoot for wind drift
                y: anywhere ? Math.random() * cv.height : -20 - Math.random() * cv.height * 0.3,
                z: z,
                v: baseV * z * (0.85 + Math.random() * 0.3)
            };
        }

        var drops = [];
        function fillDrops(anywhere) {
            var want = targetCount();
            while (drops.length < want) drops.push(makeDrop(anywhere));
            if (drops.length > want) drops.length = want;
        }
        fillDrops(true);
        window.addEventListener('resize', function () { fillDrops(true); });

        var splashes = [];
        var MAX_SPLASH = 60;

        function drawDrop(d) {
            // Streak length rides speed + depth; drawn back along the fall vector.
            var len = (d.v / baseV) * (10 + speedIn * 0.22) * SC * 2;
            ctx.strokeStyle = 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ','
                            + (opacity * (0.35 + d.z * 0.65)).toFixed(3) + ')';
            ctx.lineWidth = Math.max(0.5, thickness * SC * d.z);
            ctx.beginPath();
            ctx.moveTo(d.x, d.y);
            ctx.lineTo(d.x - dirX * len, d.y - dirY * len);
            ctx.stroke();
        }

        var reduced = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) {
            ctx.lineCap = 'round';
            for (var s = 0; s < drops.length; s++) drawDrop(drops[s]);
            return; // single static frame, no loop
        }

        var last = 0;
        function frame(ts) {
            if (!last) last = ts;
            var dt = Math.min(0.05, (ts - last) / 1000);
            last = ts;

            ctx.clearRect(0, 0, cv.width, cv.height);
            ctx.lineCap = 'round';

            for (var i = 0; i < drops.length; i++) {
                var d = drops[i];
                d.x += dirX * d.v * dt;
                d.y += dirY * d.v * dt;

                if (d.y > cv.height) {
                    // Land: subtle splash tick for near-layer drops only.
                    if (d.z > 0.75 && splashes.length < MAX_SPLASH) {
                        splashes.push({ x: d.x, y: cv.height - 1, life: 0.35, z: d.z });
                    }
                    drops[i] = makeDrop(false);
                    continue;
                }
                // Wind drift wrap-around.
                if (d.x < -cv.width * 0.25) d.x += cv.width * 1.4;
                else if (d.x > cv.width * 1.15) d.x -= cv.width * 1.4;

                drawDrop(d);
            }

            // Splashes: a tiny widening arc that fades fast.
            for (var k = splashes.length - 1; k >= 0; k--) {
                var sp = splashes[k];
                sp.life -= dt;
                if (sp.life <= 0) { splashes.splice(k, 1); continue; }
                var t = 1 - sp.life / 0.35; // 0 → 1
                ctx.strokeStyle = 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ','
                                + (opacity * 0.5 * (1 - t)).toFixed(3) + ')';
                ctx.lineWidth = Math.max(0.5, SC * sp.z);
                ctx.beginPath();
                ctx.arc(sp.x, sp.y, (1 + t * 6 * sp.z) * SC * 2, Math.PI, Math.PI * 2);
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
