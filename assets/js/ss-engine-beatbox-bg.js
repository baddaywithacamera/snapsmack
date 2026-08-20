/**
 * SNAPSMACK — BEATBOX background collage (Layer 3): ORGANIZED MAYHEM BEATBOX
 * ss-engine-beatbox-bg.js
 *
 * A beat-reactive fork of ORGANIZED MAYHEM's image model. It reuses the same JSON
 * endpoint contract (data-api-url → { images:[{id,title,src,url}] }, sampled
 * cheaply server-side, GramOfSmack trigram/panorama/carousel splits excluded) but
 * renders a FIXED full-viewport backdrop of the site's own photos rather than the
 * pannable tabletop. The photos stay dim and desaturated at rest; on a transient
 * (percussion hit) tiles pulse — scaling up and flaring to full brightness and
 * saturation, then decaying back over ~300ms.
 *
 * NOTE (honest fork boundary): this shares ORGANIZED MAYHEM's *endpoint*, not its
 * pan/zoom/region engine — BEATBOX wants a still backdrop, not a tabletop. If the
 * two should share more (e.g. the region-windowed pool), that reconciliation is a
 * follow-up flagged in the build handoff, not something done blind here.
 *
 * Consumes window.SnapBeatbox. Transient detection reads band amplitude spikes
 * frame-over-frame from the shared bus (no own AnalyserNode). Active when the
 * master background mode is "collage" or "both".
 *
 * DATA CONTRACT — mount on [data-beatbox-bg] plus:
 *   data-api-url             image endpoint (ORGANIZED MAYHEM contract)
 *   data-bb-collage-opacity  rest opacity %   5..40                       [15]
 *   data-bb-collage-sat      rest saturation % 0..50                      [20]
 *   data-bb-scale-hit        scale on hit % 100..115                     [105]
 *   data-bb-cols             collage columns (0 = auto from viewport)      [0]
 *   data-bb-ripple-speed     slow | medium | fast                     [medium]
 * Reaction mode (simultaneous | ripple) is read live from the shared bus.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
/* Requires: ss-engine-beatbox.js */

(function () {
    'use strict';
    if (window.__ssBeatboxBg) return;
    window.__ssBeatboxBg = true;

    function fault(where, err) { try { if (window.console && console.error) console.error('[beatbox-bg] ' + where, err); } catch (e) {} }
    function clamp(v, lo, hi) { return v < lo ? lo : (v > hi ? hi : v); }

    var RIPPLE_MS = { slow: 42, medium: 26, fast: 14 };
    var COOLDOWN = 100;   // per-tile ms between reactions (anti-strobe, spec)
    var DECAY = 300;      // ms decay back to rest

    var Bus = null, host = null, images = [], tiles = [], cols = 8, rows = 6;
    var restOp = 0.15, restSat = 20, hitScale = 1.05, rippleSpeed = 26;
    var enabled = false, built = false;

    function bus() { return window.SnapBeatbox || null; }
    function A(n, d) { var v = host && host.getAttribute ? host.getAttribute(n) : null; return v == null ? d : v; }

    function readSettings() {
        Bus = bus(); if (!Bus || !host) return;
        restOp = clamp(parseFloat(A('data-bb-collage-opacity', 15)) || 15, 5, 40) / 100;
        restSat = clamp(parseFloat(A('data-bb-collage-sat', 20)) || 20, 0, 50);
        hitScale = 1 + clamp((parseFloat(A('data-bb-scale-hit', 105)) || 105) - 100, 0, 15) / 100;
        rippleSpeed = RIPPLE_MS[A('data-bb-ripple-speed', 'medium')] || 26;
        var s = Bus.settings || {};
        enabled = (s.bgMode === 'collage' || s.bgMode === 'both');
        host.classList.toggle('bb-collage-on', enabled);
        if (enabled && !built) build();
        applyRest();
    }

    function applyRest() {
        for (var i = 0; i < tiles.length; i++) {
            tiles[i].style.setProperty('--bb-rest-op', restOp.toFixed(3));
            tiles[i].style.setProperty('--bb-rest-sat', restSat + '%');
            tiles[i].style.setProperty('--bb-hit-scale', hitScale.toFixed(3));
        }
    }

    // ── build the tile field ─────────────────────────────────────────────────
    function computeGrid() {
        var declared = parseInt(A('data-bb-cols', 0), 10) || 0;
        cols = declared > 0 ? declared : Math.max(4, Math.round(window.innerWidth / 220));
        rows = Math.max(3, Math.ceil(window.innerHeight / (window.innerWidth / cols)) + 1);
    }

    function build() {
        if (!host) return;
        computeGrid();
        host.style.setProperty('--bb-cols', cols);
        host.innerHTML = '';
        tiles = [];
        var total = cols * rows;
        for (var i = 0; i < total; i++) {
            var d = document.createElement('div');
            d.className = 'bb-ctile';
            d._x = i % cols; d._y = Math.floor(i / cols); d._cool = 0;
            host.appendChild(d);
            tiles.push(d);
        }
        applyRest();
        built = true;
        if (images.length) paintImages(); else fetchPool();
    }

    function fetchPool() {
        var url = A('data-api-url', '');
        if (!url) return;
        var sep = url.indexOf('?') < 0 ? '?' : '&';
        fetch(url + sep + 'count=' + (cols * rows) + '&_=' + Date.now(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                images = (data && data.images) ? data.images : [];
                paintImages();
            })
            .catch(function (e) { fault('fetch', e); });
    }

    function paintImages() {
        if (!images.length) return;
        for (var i = 0; i < tiles.length; i++) {
            var img = images[i % images.length];
            if (img && img.src) tiles[i].style.backgroundImage = 'url("' + img.src + '")';
        }
    }

    // ── transient detection (frame-over-frame spikes, spec) ──────────────────
    function detect() {
        var b = Bus.bands, p = Bus.prevBands;
        var bass = (b[4] - p[4] > 0.14) && b[4] > 0.35;   // bass drum → heaviest
        var snare = (b[2] - p[2] > 0.16) && b[2] > 0.30;  // snare
        var hat = (b[0] - p[0] > 0.18) && b[0] > 0.25;    // hi-hat
        if (!(bass || snare || hat)) return 0;
        return bass ? 1 : (snare ? 0.7 : 0.5);
    }

    function hit(tile, strength) {
        var t = performance.now();
        if (t - tile._cool < COOLDOWN) return;            // per-tile cooldown
        tile._cool = t;
        tile.style.setProperty('--bb-hit-scale', (1 + (hitScale - 1) * strength).toFixed(3));
        tile.classList.add('bb-hit');
        setTimeout(function () { tile.classList.remove('bb-hit'); }, DECAY);
    }

    function fireAll(s) { for (var i = 0; i < tiles.length; i++) hit(tiles[i], s); }
    function ripple(s) {
        var origin = tiles[(Math.random() * tiles.length) | 0]; if (!origin) return;
        var ox = origin._x, oy = origin._y;
        for (var i = 0; i < tiles.length; i++) {
            var t = tiles[i], d = Math.sqrt((t._x - ox) * (t._x - ox) + (t._y - oy) * (t._y - oy));
            (function (tile, delay, str) { setTimeout(function () { hit(tile, str); }, delay); })(t, d * rippleSpeed, s * Math.max(0.4, 1 - d / 12));
        }
    }

    function onFrame() {
        Bus = bus(); if (!Bus || !enabled) return;
        var s = detect(); if (!s) return;
        var react = (Bus.settings && Bus.settings.react) || 'simultaneous';
        if (react === 'ripple') ripple(s); else fireAll(s);
    }

    var resizeTimer = 0;
    function onResize() { clearTimeout(resizeTimer); resizeTimer = setTimeout(function () { if (enabled) { built = false; build(); } }, 250); }

    function start() {
        host = document.querySelector('[data-beatbox-bg]');
        if (!host) return;
        readSettings();
        document.addEventListener('beatbox:frame', onFrame);
        document.addEventListener('beatbox:settings', readSettings);
        window.addEventListener('resize', onResize, { passive: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();

})();
// ===== SNAPSMACK EOF =====
