/**
 * SNAPSMACK — RACETRACK ambient background (photos, Frogger drift)
 *
 * Reuses the ORGANIZED MAYHEM photo pool — the SAME generated photos, moved a
 * different way. Instead of MAYHEM's slow-drifting tabletop, RACETRACK sends
 * the prints gliding across the viewport like Frogger traffic: EVERY photo has
 * its own heading (a random angle chosen in 5° steps — up/down, sideways, any
 * diagonal) and its own speed, so they slide PAST each other in opposing
 * directions. Depth-layered: nearer prints are bigger, faster, and drawn on
 * top; farther ones smaller, slower, fainter. Each print wraps around the
 * edges so the field never empties. No light trails — just photos in motion.
 *
 * Generic engine: any skin adopts it by emitting a [data-racetrack] carrier.
 * First consumer: INSTANT CAMERA (ic_bg_mode = racetrack).
 *
 * Reads from the [data-racetrack] carrier:
 *   data-api-url    ?ajax=mayhem JSON endpoint → { images:[{id,title,src,url}] }
 *   data-rt-speed   (1..100)  base drift speed        data-rt-count (3..40 photos)
 *   data-rt-size    (60..400) base print width px     data-rt-opacity (5..100 %)
 *
 * Honours prefers-reduced-motion: places a single static scatter, no loop.
 * Purely decorative (pointer-events:none) — never intercepts the page.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
    function rand(a, b) { return a + Math.random() * (b - a); }

    function init() {
        var host = document.querySelector('[data-racetrack]');
        if (!host) return;

        function num(attr, def) {
            var v = parseFloat(host.getAttribute(attr));
            return isNaN(v) ? def : v;
        }

        var apiUrl  = host.getAttribute('data-api-url') || '';
        var speed   = clamp(num('data-rt-speed', 40), 1, 100);
        var count   = clamp(Math.round(num('data-rt-count', 14)), 3, 40);
        var baseSz  = clamp(num('data-rt-size', 180), 60, 400);
        var opacity = clamp(num('data-rt-opacity', 70), 5, 100) / 100;

        var reduced = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // The host is the full-viewport background layer. Own our stacking box;
        // stay decorative so we never eat a scroll or a click.
        if (getComputedStyle(host).position === 'static') host.style.position = 'absolute';
        host.style.overflow = 'hidden';
        host.style.pointerEvents = 'none';

        function vw() { return host.clientWidth || window.innerWidth; }
        function vh() { return host.clientHeight || window.innerHeight; }

        function fetchPool() {
            return new Promise(function (resolve) {
                if (!apiUrl) { resolve([]); return; }
                var url = apiUrl + (apiUrl.indexOf('?') > -1 ? '&' : '?') + 'count=' + count + '&_=' + Date.now();
                fetch(url)
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var imgs = Array.isArray(data) ? data : (data && data.images) || [];
                        resolve(imgs);
                    })
                    .catch(function () { resolve([]); });
            });
        }

        function build(pool) {
            if (!pool.length) return;
            var W = vw(), H = vh();
            var prints = [];

            for (var i = 0; i < count; i++) {
                var img = pool[i % pool.length];
                if (!img || !img.src) continue;

                // Depth 0 (far) → 1 (near). Drives size, speed, opacity, layer.
                var depth = Math.random();
                var w = baseSz * (0.55 + depth * 0.75);

                // Own heading in 5° steps; own speed, depth-biased + jittered.
                var deg = 5 * Math.floor(Math.random() * 72);
                var rad = deg * Math.PI / 180;
                var pxPerSec = (10 + (speed / 100) * 70) * (0.45 + depth * 1.1) * rand(0.75, 1.25);

                var node = document.createElement('div');
                node.className = 'rt-print';
                node.style.cssText =
                    'position:absolute;left:0;top:0;width:' + w.toFixed(0) + 'px;' +
                    'will-change:transform;z-index:' + Math.round(depth * 100) + ';' +
                    'opacity:' + (opacity * (0.6 + depth * 0.4)).toFixed(3) + ';' +
                    'background:#fff;padding:' + Math.max(2, w * 0.03).toFixed(0) + 'px;' +
                    'box-shadow:0 ' + (2 + depth * 8).toFixed(0) + 'px ' + (6 + depth * 14).toFixed(0) +
                    'px rgba(0,0,0,' + (0.20 + depth * 0.18).toFixed(2) + ');';

                var el = document.createElement('img');
                el.src = img.src;
                el.alt = '';
                el.loading = 'lazy';
                el.draggable = false;
                el.style.cssText = 'display:block;width:100%;height:auto;background:rgba(127,127,127,.12);';
                node.appendChild(el);
                host.appendChild(node);

                prints.push({
                    node: node,
                    x: rand(-w, W),
                    y: rand(-w, H),
                    dx: Math.cos(rad) * pxPerSec,
                    dy: Math.sin(rad) * pxPerSec,
                    w: w,
                    tilt: rand(-7, 7)
                });
            }

            function place(p) {
                p.node.style.transform =
                    'translate3d(' + p.x.toFixed(1) + 'px,' + p.y.toFixed(1) + 'px,0) rotate(' + p.tilt.toFixed(2) + 'deg)';
            }
            prints.forEach(place);

            if (reduced) return;   // static scatter, no motion

            var last = 0;
            function frame(ts) {
                if (!last) last = ts;
                var dt = Math.min(0.05, (ts - last) / 1000);
                last = ts;
                if (!document.hidden) {
                    var w = vw(), h = vh();
                    for (var i = 0; i < prints.length; i++) {
                        var p = prints[i], m = p.w * 1.4;   // wrap margin ≈ print size
                        p.x += p.dx * dt;
                        p.y += p.dy * dt;
                        if (p.x < -m) p.x += w + 2 * m; else if (p.x > w + m) p.x -= w + 2 * m;
                        if (p.y < -m) p.y += h + 2 * m; else if (p.y > h + m) p.y -= h + 2 * m;
                        place(p);
                    }
                }
                requestAnimationFrame(frame);
            }
            requestAnimationFrame(frame);
        }

        fetchPool().then(build);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
// ===== SNAPSMACK EOF =====
