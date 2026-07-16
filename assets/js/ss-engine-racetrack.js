/**
 * SNAPSMACK — RACETRACK ambient background (photos, opposing-lane drift)
 *
 * Reuses the ORGANIZED MAYHEM photo pool — the SAME generated photos, moved a
 * different way, and deliberately NOT Mayhem's rotated tabletop.
 *
 * TWO LAYERS:
 *   1. A STATIC FLOOR — a full-coverage tiling of prints that never moves, so
 *      the background is ALWAYS solid (no gaps, ever), whatever the movers do.
 *   2. A DRIFTING layer on top — prints gliding along ONE random axis chosen per
 *      page load (5° steps: up/down, sideways, any diagonal), each in ONE of the
 *      TWO opposing directions (θ or θ+180°) at its own speed, so they slide past
 *      each other like traffic. They wrap around the field.
 *
 * Prints are opaque, un-rotated and axis-aligned — EXCEPT a drifting print in
 * the reverse lane rides upside-down (180°) so the two streams read distinct.
 * Sizes vary (depth). No trails, no white frame, no random tilt.
 *
 * Reads from the [data-racetrack] carrier:
 *   data-api-url    ?ajax=mayhem JSON endpoint → { images:[{id,title,src,url}] }
 *   data-rt-speed   (1..100)  drift speed         data-rt-count (20..150 movers)
 *   data-rt-size    (60..400) print size          data-rt-opacity (5..100 %)
 *
 * Honours prefers-reduced-motion: floor + a static overlay, no motion.
 * Purely decorative (pointer-events:none).
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
        var count   = clamp(Math.round(num('data-rt-count', 55)), 20, 150);
        var baseSz  = clamp(num('data-rt-size', 180), 60, 400);
        var opacity = clamp(num('data-rt-opacity', 100), 5, 100) / 100;

        var reduced = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (getComputedStyle(host).position === 'static') host.style.position = 'absolute';
        host.style.overflow = 'hidden';
        host.style.pointerEvents = 'none';

        function vw() { return host.clientWidth || window.innerWidth; }
        function vh() { return host.clientHeight || window.innerHeight; }

        function fetchPool() {
            return new Promise(function (resolve) {
                if (!apiUrl) { resolve([]); return; }
                // Request a healthy pool for variety across BOTH layers (endpoint caps at what exists).
                var want = Math.max(count, 120);
                var url = apiUrl + (apiUrl.indexOf('?') > -1 ? '&' : '?') + 'count=' + want + '&_=' + Date.now();
                fetch(url)
                    .then(function (r) { return r.json(); })
                    .then(function (data) { resolve(Array.isArray(data) ? data : (data && data.images) || []); })
                    .catch(function () { resolve([]); });
            });
        }

        // Build one print node (opaque, natural aspect, no white frame).
        function makeNode(host, img, w, depth, z, rot) {
            var node = document.createElement('div');
            node.className = 'rt-print';
            node.style.cssText =
                'position:absolute;left:0;top:0;width:' + w.toFixed(0) + 'px;will-change:transform;' +
                'z-index:' + z + ';opacity:' + (opacity).toFixed(3) + ';' +
                'box-shadow:0 ' + (2 + depth * 7).toFixed(0) + 'px ' + (5 + depth * 14).toFixed(0) +
                'px rgba(0,0,0,' + (0.16 + depth * 0.18).toFixed(2) + ');';
            var el = document.createElement('img');
            el.src = img.src; el.alt = ''; el.loading = 'lazy'; el.draggable = false;
            el.style.cssText = 'display:block;width:100%;height:auto;';
            node.appendChild(el);
            host.appendChild(node);
            return node;
        }

        function build(pool) {
            if (!pool.length) return;
            host.querySelectorAll('.rt-print').forEach(function (n) { n.remove(); });

            var W = vw(), H = vh();
            var MARGIN = 0.30;
            var fieldW = W * (1 + MARGIN * 2), fieldH = H * (1 + MARGIN * 2);
            var ox = -W * MARGIN, oy = -H * MARGIN;

            // One drift axis for this load (movers only), in 5° steps.
            var deg = 5 * Math.floor(Math.random() * 72);
            var rad = deg * Math.PI / 180;
            var ux = Math.cos(rad), uy = Math.sin(rad);

            var sizeMul = baseSz / 180;
            var cellT   = baseSz * 1.35 * sizeMul;              // ~print cell target
            var basePx  = 8 + (speed / 100) * 55;

            // ── Layer 1: STATIC FLOOR — full grid coverage, never moves ──────
            var sCols = Math.max(1, Math.ceil(fieldW / cellT));
            var sRows = Math.max(1, Math.ceil(fieldH / cellT));
            var scw = fieldW / sCols, sch = fieldH / sRows, scell = Math.max(scw, sch);
            var sIdx = 0;
            for (var sr = 0; sr < sRows; sr++) {
                for (var sc = 0; sc < sCols; sc++) {
                    var simg = pool[sIdx % pool.length]; sIdx++;
                    if (!simg || !simg.src) continue;
                    var sscale = rand(1.35, 1.85);              // heavy overlap → no gaps
                    var sw = scell * sscale;
                    var sdepth = (sscale - 1.35) / 0.50;
                    var snode = makeNode(host, simg, sw, sdepth, Math.round(sdepth * 40), 0);
                    snode.style.transform =
                        'translate3d(' + (ox + (sc + 0.5) * scw - sw / 2 + rand(-scw * 0.08, scw * 0.08)).toFixed(1) + 'px,' +
                        (oy + (sr + 0.5) * sch - sw / 2 + rand(-sch * 0.08, sch * 0.08)).toFixed(1) + 'px,0)';
                }
            }

            // ── Layer 2: DRIFTING movers — scattered, opposing directions ────
            var movers = [];
            var mIdx = Math.floor(pool.length / 2);             // offset so movers differ from the floor beneath
            for (var m = 0; m < count; m++) {
                var img = pool[mIdx % pool.length]; mIdx++;
                if (!img || !img.src) continue;
                var scale = rand(1.15, 1.6);
                var w = scell * scale;
                var depth = (scale - 1.15) / 0.45;
                var dir = Math.random() < 0.5 ? 1 : -1;
                var v = basePx * (0.6 + depth * 0.6) * rand(0.75, 1.25) * dir;
                var rot = dir === 1 ? 0 : 180;                  // reverse lane upside-down
                var node = makeNode(host, img, w, depth, 60 + Math.round(depth * 90), rot);
                movers.push({
                    node: node,
                    x: rand(ox, ox + fieldW) - w / 2,
                    y: rand(oy, oy + fieldH) - w / 2,
                    vx: ux * v, vy: uy * v, w: w, rot: rot
                });
            }

            function place(p) {
                p.node.style.transform =
                    'translate3d(' + p.x.toFixed(1) + 'px,' + p.y.toFixed(1) + 'px,0) rotate(' + p.rot + 'deg)';
            }
            movers.forEach(place);
            if (reduced) return;

            var last = 0;
            function frame(ts) {
                if (!last) last = ts;
                var dt = Math.min(0.05, (ts - last) / 1000);
                last = ts;
                if (!document.hidden) {
                    var hiX = ox + fieldW, hiY = oy + fieldH;
                    for (var i = 0; i < movers.length; i++) {
                        var p = movers[i];
                        p.x += p.vx * dt; p.y += p.vy * dt;
                        if (p.x > hiX) p.x -= (fieldW + p.w); else if (p.x + p.w < ox) p.x += (fieldW + p.w);
                        if (p.y > hiY) p.y -= (fieldH + p.w); else if (p.y + p.w < oy) p.y += (fieldH + p.w);
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
