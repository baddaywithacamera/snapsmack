/**
 * SNAPSMACK — Organized Mayhem
 * ss-engine-organized-mayhem.js
 *
 * Tabletop Background FX Engine (spec: _spec/organized-mayhem-spec-v0.1.docx).
 *
 * Renders an infinite, pannable, zoomable "tabletop" of scattered photo
 * thumbnails — the physical-prints-thrown-on-a-table metaphor. Core CMS
 * service; any skin opts in via the manifest (require_scripts =>
 * ['smack-organized-mayhem']). No inline JS ever ships in a skin.
 *
 * RESOURCE MODEL (read before editing)
 *   The 64MB "shared-host defensible" ceiling (spec) is a SERVER budget — the
 *   host only runs ONE cheap minimal query (see the endpoint contract in the
 *   build doc; it must NOT use ORDER BY RAND()). The browser carries all the
 *   render + memory work, and is bounded three ways:
 *     1. MODEL is region-windowed — regions beyond KEEP_REGIONS of the view
 *        are evicted (cards dropped from memory, can regenerate on return).
 *     2. DOM is virtualised — only tiles inside the viewport overscan are
 *        mounted, hard-capped by budget.maxMounted (watchdog-tunable).
 *     3. The update pass is CHANGE-DRIVEN — full scans run only when the view
 *        actually moved or new regions are pending, never idly at 60fps.
 *   Idle "alive" wobble is a CSS animation (GPU/compositor), not per-frame JS.
 *
 * DATA CONTRACT
 *   Mount on a container carrying [data-mayhem] plus:
 *     data-mayhem            — marker (presence triggers init)
 *     data-api-url           — JSON endpoint; GET ?count=N&_=ts returns
 *                              { images: [ {id,title,src,url}, ... ] }.
 *                              MUST exclude GramOfSmack trigram splits /
 *                              panorama rows / carousel covers server-side
 *                              (spec §3.2) and MUST sample cheaply (no RAND).
 *     data-initial-count     — initial photo pool size (spec §3.1)        [120]
 *     data-max-width         — tile max display width in px (400px native)  [300]
 *     data-overlap-max       — max overlap fraction, 0..1 (spec §4.2)     [0.85]
 *     data-cluster-size      — avg cards dropped per organic anchor         [9]
 *     data-drift             — "1"/"0" idle cinematic drift (spec §5)       [1]
 *     data-drift-delay       — ms of idle before drift starts            [4000]
 *     data-warp              — "1"/"0" paper-warp 3D skew (spec §4.4)       [1]
 *     data-max-mounted       — watchdog start cap on live DOM tiles       [180]
 *     data-loading-label     — loading verb (spec §6)             ["Placing"]
 *
 * Faults fail silently on the front end — no raw error leakage to the public
 * UI (spec §1). Failures are routed to the console error channel only.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    var container = document.querySelector('[data-mayhem]');
    if (!container) return;

    // ── Silent error pipeline (spec §1) ──────────────────────────────────
    function fault(where, err) {
        try { if (window.console && console.error) console.error('[organized-mayhem] ' + where, err); } catch (e) {}
    }

    try { boot(); } catch (e) { fault('boot', e); }

    function boot() {

        // ── Config from data attributes ──────────────────────────────────
        var d = container.dataset;
        var apiUrl       = d.apiUrl || '';
        var initialCount = clampInt(d.initialCount, 1, 600, 120);
        var maxWidth     = clampInt(d.maxWidth, 80, 800, 300);
        var overlapMax   = clampFloat(d.overlapMax, 0, 0.98, 0.85);
        var clusterSize  = clampInt(d.clusterSize, 2, 40, 9);
        var driftOn      = d.drift !== '0';
        var driftDelay   = clampInt(d.driftDelay, 0, 60000, 4000);
        var warpOn       = d.warp !== '0';
        var loadingLabel = d.loadingLabel || 'Placing';
        // Mode knobs (spec: one engine, two behaviours):
        //   data-pan="0"     → not grabbable/zoomable (INSTANT CAMERA backdrop)
        //   data-ambient="1" → always drift, not just when idle (backdrop)
        var panEnabled   = d.pan !== '0';
        var ambient      = d.ambient === '1';
        var prefersReduced = window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // ── Watchdog-tunable budgets (spec §2) ───────────────────────────
        var budget = {
            maxMounted: clampInt(d.maxMounted, 30, 600, 180),
            warp: warpOn && !prefersReduced,
            perspective: !prefersReduced,
            drift: driftOn && !prefersReduced,
            wobble: !prefersReduced
        };

        // ── World / view state ───────────────────────────────────────────
        var REGION = 1600;            // world px per region cell
        var WORLD_MARGIN = 500;       // viewport overscan (world px) for mounting
        var KEEP_REGIONS = 3;         // evict model regions beyond this Chebyshev radius
        var view = { x: 0, y: 0, z: 1 };
        var minZoom = 0.35, maxZoom = 2.4;

        var pool = [];                // fetched image records (cycled)
        var poolIdx = 0;
        var regions = {};             // key -> { rx, ry, cards: [] }
        var cardCount = 0;            // live model card count (across kept regions)
        var coverageMode = false;     // ambient backdrop: one-shot full-coverage,
                                      // each image used once, gentle bounded drift

        // ── DOM scaffold ─────────────────────────────────────────────────
        if (getComputedStyle(container).position === 'static') container.style.position = 'relative';
        container.style.overflow = 'hidden';
        if (panEnabled) {
            container.style.touchAction = 'none';   // we own drag/zoom gestures
            container.style.cursor = 'grab';
        }                                            // backdrop mode leaves touch/scroll to the page

        var world = document.createElement('div');
        world.className = 'om-world';
        world.style.cssText = 'position:absolute;left:0;top:0;width:0;height:0;' +
            'transform-origin:0 0;will-change:transform;';
        container.appendChild(world);

        var hourglass = document.createElement('div');
        hourglass.className = 'om-hourglass';
        hourglass.setAttribute('aria-hidden', 'true');
        hourglass.style.cssText = 'position:absolute;right:14px;bottom:14px;width:34px;height:34px;' +
            'display:none;z-index:5;pointer-events:none;font-size:26px;line-height:34px;text-align:center;' +
            'animation:om-flip 1s linear infinite;';
        hourglass.textContent = '⧖'; // flippy hourglass
        container.appendChild(hourglass);

        injectCssOnce();

        // ── Loading overlay (spec §6) ────────────────────────────────────
        var overlay = document.createElement('div');
        overlay.className = 'om-loading';
        overlay.style.cssText = 'position:absolute;inset:0;z-index:9;display:flex;flex-direction:column;' +
            'align-items:center;justify-content:center;gap:14px;background:inherit;';
        var bar = document.createElement('div');
        bar.style.cssText = 'width:min(60%,360px);height:8px;border-radius:6px;overflow:hidden;background:rgba(127,127,127,.25);';
        var fill = document.createElement('div');
        fill.style.cssText = 'height:100%;width:0%;border-radius:6px;background:currentColor;transition:width .15s ease;opacity:.85;';
        bar.appendChild(fill);
        var counter = document.createElement('div');
        counter.className = 'om-loading-count';
        counter.style.cssText = 'font:14px/1.4 system-ui,sans-serif;opacity:.8;letter-spacing:.02em;';
        counter.textContent = loadingLabel + ' …';
        overlay.appendChild(bar); overlay.appendChild(counter);
        container.appendChild(overlay);

        function setProgress(done, total) {
            var pct = total ? Math.round((done / total) * 100) : 0;
            fill.style.width = pct + '%';
            counter.textContent = loadingLabel + ' ' + done + ' of ' + total + ' photos';
        }
        function hideOverlay() {
            overlay.style.transition = 'opacity .4s ease';
            overlay.style.opacity = '0';
            setTimeout(function () { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); }, 450);
        }

        // ── Helpers ──────────────────────────────────────────────────────
        function rand(min, max) { return Math.random() * (max - min) + min; }
        function rkey(rx, ry) { return rx + ':' + ry; }
        function nextImage() {
            if (!pool.length) return null;
            var img = pool[poolIdx % pool.length]; poolIdx++; return img;
        }

        // ── Fetch the image pool (+ server vitals) ───────────────────────
        // Resolves the whole payload { images:[...], vitals:{...} }. A bare
        // array (legacy) is accepted and wrapped.
        function fetchPool(count) {
            return new Promise(function (resolve) {
                if (!apiUrl) { resolve({ images: [] }); return; }
                var url = apiUrl + (apiUrl.indexOf('?') > -1 ? '&' : '?') + 'count=' + count + '&_=' + Date.now();
                fetch(url)
                    .then(function (r) { return r.json(); })
                    .then(function (data) { resolve(Array.isArray(data) ? { images: data } : (data || { images: [] })); })
                    .catch(function (e) { fault('fetchPool', e); resolve({ images: [] }); });
            });
        }

        // ── Region generation: organic clusters + overlap rule (spec §4) ─
        function generateRegion(rx, ry) {
            var key = rkey(rx, ry);
            if (regions[key] || !pool.length) return false;
            var reg = { rx: rx, ry: ry, cards: [] };
            regions[key] = reg;

            var baseX = rx * REGION, baseY = ry * REGION;
            var anchors = Math.max(2, Math.round(REGION * REGION / (520 * 520)));
            for (var a = 0; a < anchors; a++) {
                var ax = baseX + rand(0.12, 0.88) * REGION;
                var ay = baseY + rand(0.12, 0.88) * REGION;
                var n = Math.max(2, Math.round(clusterSize * rand(0.6, 1.4)));
                for (var c = 0; c < n; c++) {
                    var img = nextImage(); if (!img) break;
                    placeCard(reg, img, ax, ay, rx, ry);
                }
            }
            return true;
        }

        function placeCard(reg, img, anchorX, anchorY, rx, ry) {
            var w = maxWidth * rand(0.66, 1.0);
            var h = w * rand(0.66, 1.45);
            var x = anchorX + rand(-REGION * 0.18, REGION * 0.18);
            var y = anchorY + rand(-REGION * 0.18, REGION * 0.18);

            var neighbours = collectNeighbours(rx, ry);
            var attempts = 0, z = 1;
            while (attempts < 20) {
                var worst = worstOverlap(x, y, w, h, neighbours);
                if (!worst || worst.frac <= overlapMax) { if (worst) z = worst.z + 1; break; }
                var dx = (x + w / 2) - worst.cx, dy = (y + h / 2) - worst.cy;
                var len = Math.hypot(dx, dy) || 1, push = Math.max(w, h) * 0.22;
                x += (dx / len) * push; y += (dy / len) * push;
                attempts++;
            }

            var card = {
                title: img.title || '', src: img.src || '', url: img.url || '#',
                x: x, y: y, w: w, h: h, z: z,
                rot: rand(0, 360), warpX: rand(-7, 7), warpY: rand(-7, 7),
                wob: rand(6, 12), del: rand(0, 6), node: null
            };
            reg.cards.push(card);
            cardCount++;
        }

        function collectNeighbours(rx, ry) {
            var out = [];
            for (var i = -1; i <= 1; i++) for (var j = -1; j <= 1; j++) {
                var reg = regions[rkey(rx + i, ry + j)];
                if (reg) out = out.concat(reg.cards);
            }
            return out;
        }
        function worstOverlap(x, y, w, h, neighbours) {
            var worst = null;
            for (var i = 0; i < neighbours.length; i++) {
                var c = neighbours[i];
                var ox = Math.max(0, Math.min(x + w, c.x + c.w) - Math.max(x, c.x));
                var oy = Math.max(0, Math.min(y + h, c.y + c.h) - Math.max(y, c.y));
                if (ox <= 0 || oy <= 0) continue;
                var frac = (ox * oy) / Math.min(w * h, c.w * c.h);
                if (!worst || frac > worst.frac) worst = { frac: frac, z: c.z, cx: c.x + c.w / 2, cy: c.y + c.h / 2 };
            }
            return worst;
        }

        // ── Tile DOM ─────────────────────────────────────────────────────
        function mount(card) {
            if (card.node) return;
            var a = document.createElement('a');
            a.className = 'om-card';
            a.href = card.url;
            // HARD RULE: prints stay WHOLE. Only a 2D translate + rotate — no
            // rotateX/rotateY 3D warp. The warp foreshortened each rectangle into
            // a wedge, and overlapping wedges read as sliced/merged images. Flat
            // rotated rectangles overlap cleanly, layered by z-index, never merge.
            a.style.cssText = 'position:absolute;left:0;top:0;display:block;width:' +
                card.w.toFixed(0) + 'px;z-index:' + card.z + ';will-change:transform;text-decoration:none;' +
                'transform:translate3d(' + card.x.toFixed(1) + 'px,' + card.y.toFixed(1) + 'px,0) rotate(' +
                card.rot.toFixed(2) + 'deg);';

            var img = document.createElement('img');
            img.src = card.src; img.alt = card.title;
            img.loading = 'lazy'; img.draggable = false;
            img.className = 'om-img';
            img.style.cssText = 'display:block;width:100%;height:auto;box-shadow:' + shadowFor(card.z) +
                ';background:rgba(127,127,127,.12);';
            // Per-tile idle wobble parameters (consumed by the CSS animation).
            img.style.setProperty('--om-w', card.wob.toFixed(1) + 's');
            img.style.setProperty('--om-d', card.del.toFixed(1) + 's');
            img.addEventListener('error', function () { fault('img', card.src); a.style.display = 'none'; });
            a.appendChild(img);
            a.addEventListener('click', function (e) { if (dragMoved) e.preventDefault(); });

            world.appendChild(a);
            card.node = a;
        }
        function unmount(card) {
            if (card.node && card.node.parentNode) card.node.parentNode.removeChild(card.node);
            card.node = null;
        }
        function shadowFor(z) {
            if (!budget.perspective) return '0 1px 3px rgba(0,0,0,.35)';
            var depth = Math.min(z, 12), blur = 4 + depth * 1.6, drop = 2 + depth * 1.1;
            return '0 ' + drop.toFixed(0) + 'px ' + blur.toFixed(0) + 'px rgba(0,0,0,' + (0.28 + depth * 0.02).toFixed(2) + ')';
        }

        // ── Viewport math ────────────────────────────────────────────────
        function visibleWorldRect() {
            var cw = container.clientWidth, ch = container.clientHeight;
            return { x: view.x - WORLD_MARGIN, y: view.y - WORLD_MARGIN,
                     w: cw / view.z + WORLD_MARGIN * 2, h: ch / view.z + WORLD_MARGIN * 2 };
        }
        function viewCenterRegion() {
            var cx = view.x + container.clientWidth / (2 * view.z);
            var cy = view.y + container.clientHeight / (2 * view.z);
            return { rx: Math.floor(cx / REGION), ry: Math.floor(cy / REGION) };
        }

        // Generate every region overlapping rect; returns # newly built.
        function ensureRegionsFor(rect) {
            var built = 0;
            var rx0 = Math.floor(rect.x / REGION) - 1, ry0 = Math.floor(rect.y / REGION) - 1;
            var rx1 = Math.floor((rect.x + rect.w) / REGION) + 1, ry1 = Math.floor((rect.y + rect.h) / REGION) + 1;
            for (var rx = rx0; rx <= rx1; rx++) for (var ry = ry0; ry <= ry1; ry++)
                if (generateRegion(rx, ry)) built++;
            return built;
        }

        // Evict model regions far from the view (bounds RAM — the key fix).
        function evictFarRegions() {
            var ctr = viewCenterRegion();
            for (var key in regions) {
                if (!regions.hasOwnProperty(key)) continue;
                var reg = regions[key];
                if (Math.max(Math.abs(reg.rx - ctr.rx), Math.abs(reg.ry - ctr.ry)) > KEEP_REGIONS) {
                    for (var i = 0; i < reg.cards.length; i++) unmount(reg.cards[i]);
                    cardCount -= reg.cards.length;
                    delete regions[key];
                }
            }
        }

        // Mount/unmount DOM for the visible rect across kept regions only.
        function syncMounted() {
            var rect = visibleWorldRect(), mounted = 0;
            for (var key in regions) {
                if (!regions.hasOwnProperty(key)) continue;
                var cs = regions[key].cards;
                for (var i = 0; i < cs.length; i++) {
                    var c = cs[i];
                    var on = (c.x + c.w > rect.x) && (c.x < rect.x + rect.w) &&
                             (c.y + c.h > rect.y) && (c.y < rect.y + rect.h);
                    if (on && mounted < budget.maxMounted) { mount(c); mounted++; }
                    else if (!on) unmount(c);
                }
            }
        }

        function renderView() {
            world.style.transform = 'scale(' + view.z + ') translate3d(' +
                (-view.x).toFixed(1) + 'px,' + (-view.y).toFixed(1) + 'px,0)';
        }

        // Change-driven update: build frontier, evict, (re)mount. Cheap when still.
        function update() {
            try {
                var built = ensureRegionsFor(visibleWorldRect());
                if (built > 2) flashHourglass();   // gentle catch-up signal (spec §2)
                evictFarRegions();
                syncMounted();
            } catch (e) { fault('update', e); }
        }

        var hgTimer = null;
        function flashHourglass() {
            hourglass.style.display = 'block';
            if (hgTimer) clearTimeout(hgTimer);
            hgTimer = setTimeout(function () { hourglass.style.display = 'none'; }, 320);
        }

        // ── Idle "alive" wobble = CSS class toggle (no per-frame JS) ──────
        function setAlive(on) {
            container.classList.toggle('om-alive', !!(on && budget.drift && budget.wobble));
        }

        // ── Pan / drag (mouse + touch) ───────────────────────────────────
        var dragging = false, dragMoved = false, lastX = 0, lastY = 0, lastInteract = now();
        function pointerDown(px, py) { if (!panEnabled) return; dragging = true; dragMoved = false; lastX = px; lastY = py; container.style.cursor = 'grabbing'; lastInteract = now(); setAlive(false); }
        function pointerMove(px, py) {
            if (!dragging) return;
            var dx = (px - lastX) / view.z, dy = (py - lastY) / view.z;
            if (Math.abs(px - lastX) + Math.abs(py - lastY) > 3) dragMoved = true;
            lastX = px; lastY = py;
            view.x -= dx; view.y -= dy;
            renderView(); update();
            lastInteract = now();
        }
        function pointerUp() { dragging = false; container.style.cursor = 'grab'; lastInteract = now(); }

        function zoomAt(clientX, clientY, factor) {
            if (!panEnabled) return;
            var r = container.getBoundingClientRect();
            var px = clientX - r.left, py = clientY - r.top;
            var wx = view.x + px / view.z, wy = view.y + py / view.z;
            view.z = Math.max(minZoom, Math.min(maxZoom, view.z * factor));
            view.x = wx - px / view.z; view.y = wy - py / view.z;
            renderView(); update();
            lastInteract = now(); setAlive(false);
        }

        container.addEventListener('wheel', function (e) { e.preventDefault(); zoomAt(e.clientX, e.clientY, e.deltaY < 0 ? 1.12 : 0.89); }, { passive: false });
        container.addEventListener('mousedown', function (e) { e.preventDefault(); pointerDown(e.clientX, e.clientY); });
        window.addEventListener('mousemove', function (e) { pointerMove(e.clientX, e.clientY); });
        window.addEventListener('mouseup', pointerUp);

        var pinchDist = 0;
        container.addEventListener('touchstart', function (e) {
            if (e.touches.length === 1) pointerDown(e.touches[0].clientX, e.touches[0].clientY);
            else if (e.touches.length === 2) pinchDist = touchDist(e);
            lastInteract = now();
        }, { passive: true });
        container.addEventListener('touchmove', function (e) {
            if (e.touches.length === 1) pointerMove(e.touches[0].clientX, e.touches[0].clientY);
            else if (e.touches.length === 2) { e.preventDefault(); var nd = touchDist(e); if (pinchDist) { var m = touchMid(e); zoomAt(m.x, m.y, nd / pinchDist); } pinchDist = nd; }
        }, { passive: false });
        container.addEventListener('touchend', function (e) { if (!e.touches.length) pointerUp(); pinchDist = 0; });
        function touchDist(e) { var a = e.touches[0], b = e.touches[1]; return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY); }
        function touchMid(e) { var a = e.touches[0], b = e.touches[1]; return { x: (a.clientX + b.clientX) / 2, y: (a.clientY + b.clientY) / 2 }; }

        // ── Hardware-aware watchdog (spec §2) ────────────────────────────
        // Two inputs: server vitals (applyVitals, below) set the STARTING tier;
        // client frame-time is the live guard that ratchets further down.
        var slowStreak = 0, tier = 0;
        function watchdog(dt) {
            if (dt > 40) slowStreak++; else slowStreak = Math.max(0, slowStreak - 1);
            if (slowStreak > 30 && tier < 3) { scaleDown('frame-time'); slowStreak = 0; }
        }
        function scaleDown(reason) {
            tier++;
            if (tier >= 1) { budget.warp = false; budget.wobble = false; setAlive(false); }
            if (tier >= 2) { budget.maxMounted = Math.max(40, Math.round(budget.maxMounted * 0.6)); syncMounted(); }
            if (tier >= 3) { budget.drift = false; }
            fault('watchdog: scaled to tier ' + tier + ' (' + (reason || '') + ')', null);
        }

        // Server vitals (spec §2) — the dashboard's sys_getloadavg()/memory,
        // emitted in the pool payload's `vitals`. Sets the STARTING tier so a
        // loaded shared host gets a lean engine from frame one; client
        // frame-time then adjusts further down if the device also struggles.
        // (Tiers ratchet down only — same one-way policy as the FPS guard.)
        function applyVitals(v) {
            if (!v) return;
            var load = parseFloat(v.load); if (isNaN(load)) load = 0;
            var ncpu = parseInt(v.ncpu, 10); if (isNaN(ncpu) || ncpu < 1) ncpu = 1;
            var per = load / ncpu;
            var mem = parseFloat(v.mem_used_pct); if (isNaN(mem)) mem = 0;
            var target = 0;
            if (per > 2.0 || mem > 90) target = 2;
            else if (per > 1.0 || mem > 75) target = 1;
            while (tier < target) scaleDown('server-vitals');
        }

        // ── Idle cinematic drift (spec §5) — world transform only ────────
        var driftVX = rand(-0.05, 0.05), driftVY = rand(-0.05, 0.05), driftZ0 = view.z;
        var lastFrame = now(), lastUpd = 0, raf = null;

        function loop(ts) {
            var t = ts || now();
            var dt = t - lastFrame; lastFrame = t;
            try {
                if (document.hidden) { raf = requestAnimationFrame(loop); return; }
                watchdog(dt);
                var idle = ambient || ((now() - lastInteract) > driftDelay && !dragging);
                setAlive(idle);

                if (coverageMode) {
                    // Full-coverage backdrop: gentle bounded sway around origin
                    // (always within the field margin) + a slow zoom-IN breath
                    // (never zooms out past the edge). The field is built once and
                    // fully covers the viewport, so NO region (re)generation runs.
                    if (budget.drift) {
                        var amp = Math.min(container.clientWidth, container.clientHeight) * 0.05;
                        view.x = Math.sin(t / 11000) * amp;
                        view.y = Math.cos(t / 13000) * amp;
                        view.z = driftZ0 + (0.5 + 0.5 * Math.sin(t / 9000)) * 0.04;  // 1.00 → 1.04
                        renderView();
                    }
                } else if (budget.drift && idle) {
                    view.x += driftVX * (dt / 16.7);
                    view.y += driftVY * (dt / 16.7);
                    view.z = driftZ0 + Math.sin(t / 9000) * 0.06;
                    if (Math.random() < 0.004) { driftVX = rand(-0.05, 0.05); driftVY = rand(-0.05, 0.05); }
                    renderView();
                    // Drift moves slowly — refresh the mount window ~4x/sec, not every frame.
                    if (t - lastUpd > 250) { update(); lastUpd = t; }
                } else if (!idle) {
                    driftZ0 = view.z;
                }
            } catch (e) { fault('loop', e); }
            raf = requestAnimationFrame(loop);
        }
        function startLoop() { if (!raf) raf = requestAnimationFrame(loop); }

        // ── Chunked, non-blocking initial build (spec §3.1, §6) ──────────
        function build() {
            // ESC-return restores the exact prior view (spec §4.3, set by the
            // 52 PICKUP layer); a plain refresh gets a fresh centred sample.
            var restored = false;
            try {
                if (window.sessionStorage && sessionStorage.getItem('om-restore') === '1') {
                    sessionStorage.removeItem('om-restore');
                    var sv = JSON.parse(sessionStorage.getItem('om-view') || 'null');
                    if (sv && typeof sv.x === 'number') {
                        view.x = sv.x; view.y = sv.y;
                        view.z = Math.max(minZoom, Math.min(maxZoom, sv.z || 1));
                        restored = true;
                    }
                }
            } catch (e) { fault('restore view', e); }
            if (!restored) {
                view.x = -(container.clientWidth / 2);
                view.y = -(container.clientHeight / 2);
            }
            renderView();

            var total = initialCount;
            var ring = Math.max(1, Math.ceil(Math.sqrt(total / clusterSize)));
            var queue = [];
            for (var rx = -ring; rx <= ring; rx++) for (var ry = -ring; ry <= ring; ry++)
                queue.push([rx, ry, Math.max(Math.abs(rx), Math.abs(ry))]);
            queue.sort(function (a, b) { return a[2] - b[2]; });   // spiral out from origin

            function step() {
                var t0 = now();
                while (queue.length && cardCount < total && (now() - t0) < 12) {
                    var rc = queue.shift();
                    generateRegion(rc[0], rc[1]);
                    setProgress(Math.min(total, cardCount), total);
                }
                if (queue.length && cardCount < total) { requestAnimationFrame(step); return; }
                setProgress(total, total);
                syncMounted();
                hideOverlay();
                startLoop();
            }
            requestAnimationFrame(step);
        }

        // ── Ambient backdrop: one-shot FULL COVERAGE (spec: instant-camera) ──
        // Fixed (non-pannable) tabletop that must completely cover the viewport
        // with NO gaps and NO repeats. Each pool image is placed exactly once on
        // a jittered grid sized to the viewport (+ margin for the drift), with
        // each print scaled larger than its cell so neighbours overlap into a
        // solid pile. Few images => big cells => the prints zoom in to still
        // cover. Many images => smaller prints, finer scatter. The loop applies
        // only a gentle bounded sway, so the field never drifts off its edge.
        function buildAmbientCoverage() {
            // reset model
            for (var k in regions) { if (!regions.hasOwnProperty(k)) continue;
                var cz = regions[k].cards; for (var z = 0; z < cz.length; z++) unmount(cz[z]); }
            regions = {}; cardCount = 0; poolIdx = 0;
            view.x = 0; view.y = 0; view.z = 1; driftZ0 = 1;
            renderView();

            var reg = { rx: 0, ry: 0, cards: [] };
            regions['0:0'] = reg;

            var W = container.clientWidth || 1200, H = container.clientHeight || 800;
            var MARGIN = 0.20;                                   // extra field beyond viewport for the sway
            var fieldW = W * (1 + MARGIN * 2), fieldH = H * (1 + MARGIN * 2);
            var ox = -W * MARGIN, oy = -H * MARGIN;              // field origin (view starts at 0,0)

            // Each image once; cap for very large libraries so a backdrop never
            // mounts thousands of nodes (still no repeats — just a subset).
            var M = Math.max(1, Math.min(pool.length, 220));
            var cols = Math.max(1, Math.round(Math.sqrt(M * fieldW / fieldH)));
            var rows = Math.max(1, Math.ceil(M / cols));
            cols = Math.ceil(M / rows);                          // cols*rows >= M, fills the field
            var cellW = fieldW / cols, cellH = fieldH / rows;
            // Print sized well over the cell so rotation + jitter never open a gap.
            var cardW = Math.max(cellW, cellH) * 1.7;

            budget.maxMounted = Math.max(budget.maxMounted, M + 4);

            var idx = 0;
            for (var r = 0; r < rows && idx < M; r++) {
                for (var c = 0; c < cols && idx < M; c++) {
                    var img = pool[idx++];                       // each image exactly once
                    var cx = ox + (c + 0.5) * cellW + rand(-cellW * 0.16, cellW * 0.16);
                    var cy = oy + (r + 0.5) * cellH + rand(-cellH * 0.16, cellH * 0.16);
                    var w = cardW * rand(0.94, 1.12);
                    var h = w * rand(0.92, 1.24);
                    var card = {
                        title: img.title || '', src: img.src || '', url: img.url || '#',
                        x: cx - w / 2, y: cy - h / 2, w: w, h: h,
                        z: 1 + ((r * cols + c) % 9),
                        rot: rand(-16, 16), warpX: rand(-5, 5), warpY: rand(-5, 5),
                        wob: rand(7, 12), del: rand(0, 6), node: null
                    };
                    reg.cards.push(card); cardCount++;
                }
            }
            syncMounted();
            hideOverlay();
            startLoop();
        }

        function reshuffle() {
            for (var key in regions) { if (!regions.hasOwnProperty(key)) continue; var cs = regions[key].cards; for (var i = 0; i < cs.length; i++) unmount(cs[i]); }
            regions = {}; cardCount = 0; poolIdx = 0;
            overlay.style.opacity = '1'; if (!overlay.parentNode) container.appendChild(overlay);
            setProgress(0, initialCount);
            fetchPool(initialCount).then(function (payload) {
                var imgs = payload && payload.images;
                pool = (imgs && imgs.length) ? imgs : pool;
                applyVitals(payload && payload.vitals);
                if (ambient) { coverageMode = true; buildAmbientCoverage(); } else { build(); }
            }).catch(function (e) { fault('reshuffle', e); });
        }
        var reBtn = document.querySelector('[data-mayhem-reshuffle]');
        if (reBtn) reBtn.addEventListener('click', function (e) { e.preventDefault(); reshuffle(); });

        // ── Public API (consumed by the 52 PICKUP layer for ESC return) ──
        try {
            window.OrganizedMayhem = window.OrganizedMayhem || {};
            window.OrganizedMayhem.getView = function () { return { x: view.x, y: view.y, z: view.z }; };
            window.OrganizedMayhem.setView = function (v) {
                if (!v) return;
                if (typeof v.x === 'number') view.x = v.x;
                if (typeof v.y === 'number') view.y = v.y;
                if (typeof v.z === 'number') view.z = Math.max(minZoom, Math.min(maxZoom, v.z));
                renderView(); update();
            };
            window.OrganizedMayhem.reshuffle = function () { reshuffle(); };
        } catch (e) { fault('expose api', e); }

        // ── Go ───────────────────────────────────────────────────────────
        setProgress(0, initialCount);
        fetchPool(initialCount).then(function (payload) {
            pool = (payload && payload.images) || [];
            if (!pool.length) { fault('empty pool', null); hideOverlay(); return; }
            applyVitals(payload && payload.vitals);
            if (ambient) { coverageMode = true; buildAmbientCoverage(); } else { build(); }
        }).catch(function (e) { fault('init', e); hideOverlay(); });
    }

    // ── Pure helpers (module scope) ──────────────────────────────────────
    function now() { return (window.performance && performance.now) ? performance.now() : Date.now(); }
    function clampInt(v, lo, hi, def) { var n = parseInt(v, 10); if (isNaN(n)) return def; return Math.max(lo, Math.min(hi, n)); }
    function clampFloat(v, lo, hi, def) { var n = parseFloat(v); if (isNaN(n)) return def; return Math.max(lo, Math.min(hi, n)); }
    function injectCssOnce() {
        if (document.getElementById('om-keyframes')) return;
        var s = document.createElement('style');
        s.id = 'om-keyframes';
        s.textContent =
            '@keyframes om-flip{0%{transform:rotate(0)}50%{transform:rotate(180deg)}100%{transform:rotate(360deg)}}' +
            '@keyframes om-wobble{from{transform:rotate(-0.6deg)}to{transform:rotate(0.6deg)}}' +
            // No preserve-3d / perspective: the tabletop is a flat 2D stack of
            // whole prints layered by z-index. 3D context is what let the warp
            // slice them.
            '.om-alive .om-img{animation:om-wobble var(--om-w,9s) ease-in-out var(--om-d,0s) infinite alternate}' +
            '@media (prefers-reduced-motion: reduce){.om-alive .om-img{animation:none}}';
        document.head.appendChild(s);
    }

})();
// ===== SNAPSMACK EOF =====
