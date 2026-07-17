/**
 * SNAPSMACK — JIVE TURKEY background engine (Layer 1)
 *
 * The 70s background for the GRAMOFSMACK JIVE TURKEY skin. One engine, five
 * selectable modes (+ CYCLE + SURPRISE), all driven ENTIRELY by the active
 * colourway — no hardcoded colour. Deliberately NOT ORGANIZED MAYHEM's photo
 * tabletop; this is flat 70s graphic pattern. Every mode is FULL-COVERAGE — the
 * field colour is painted first so a gap never reads as black.
 *
 *   SCOPE   — a true reflection kaleidoscope. One wedge of a few plain tumbling
 *             circles, mirror-tiled around the disc; alternate wedges a darker
 *             shade of the colourway (light/dark/light/dark, never black).
 *   BLOOM   — a tiling field of plain 70s quatrefoil flowers that floats and
 *             slowly rotates; individual blooms shift colourway colour on a timer.
 *   FLOW    — flowing racing-stripe ribbons: colourway bands with cream woven in,
 *             undulating + drifting in a seamless loop (boundary-band fill), a
 *             slow field rotation for the spiral/swirl.
 *   DAISY   — a sunburst of colourway rays rotating around a smiley daisy that
 *             floats the frame; petal colours cycle; ray-rotation and flower-float
 *             run on two separate speeds.
 *   REELS   — a Bauhaus grid of circles / split / striped / half-circles on the
 *             colourway's dark field; shapes blink out, spin, and a new shape
 *             appears — staggered across a direction like 70s film credits.
 *
 *   CYCLE   — rotates through the enabled modes on a timer, EACH using its OWN
 *             saved settings.
 *   SURPRISE— on every page load, picks a random enabled mode (and, when
 *             "both barrels" is on, a random enabled colourway) and runs it with
 *             that mode's saved settings, so repeat visitors never see the same
 *             thing twice. The chosen colourway is broadcast (jt:colourway event)
 *             so the tile-border engine matches it automatically.
 *
 * Self-contained. Reads config from the `.jt-jive-turkey-bg` element's dataset
 * (set by skin-profile.php):
 *   data-jt-mode        scope|bloom|flow|daisy|reels|cycle|surprise
 *   data-jt-colourway   active colourway NAME (e.g. HARVEST)
 *   data-jt-palette     JSON hex array — active colourway colours (back-compat)
 *   data-jt-field       hex — active colourway cream/base (back-compat)
 *   data-jt-speed       1..100 global speed (SCOPE/BLOOM/FLOW; DAISY/REELS use own)
 *   data-jt-cycle       seconds per mode in CYCLE
 *   data-jt-colourways  JSON map {NAME:{cream,colors[],centre?,dark?}} — all the
 *                       colourways (needed for SURPRISE / random-colour CYCLE)
 *   data-jt-modes       JSON map {mode:{params}} — per-mode saved settings
 *   data-jt-pool        JSON array of mode names eligible for CYCLE/SURPRISE
 *   data-jt-random-colour  "1" to also randomise the colourway ("both barrels")
 * No fetch / storage. Respects prefers-reduced-motion (one static frame) and
 * pauses on document.hidden.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    // Built-in colourways — the skin passes its own via data-jt-colourways, but
    // these keep SURPRISE working even from a bare carrier.
    var COLOURWAYS_DEFAULT = {
        BARF:    { cream:'#efe7cf', colors:['#c9b23a','#6e7f39','#6b4a2a'], centre:'#c9b23a', dark:'#40301c' },
        BLECH:   { cream:'#efe3cd', colors:['#6a3b86','#dd7328','#c39a3f'], centre:'#c39a3f', dark:'#33223e' },
        GROOVY:  { cream:'#f2e7d6', colors:['#7b3f9e','#e368a4','#3f7cc4'], centre:'#e368a4', dark:'#2b2340' },
        HARVEST: { cream:'#f2e2c0', colors:['#d99a2b','#bd4e1f','#6b3f24'], centre:'#d99a2b', dark:'#38220f' }
    };
    var INK = '#3a2a1a';
    var ALL_MODES = ['scope', 'bloom', 'flow', 'daisy', 'reels'];
    var MODE_DEFAULTS = {
        scope: { speed: 40 },
        bloom: { speed: 40 },
        flow:  { speed: 40 },
        daisy: { ray: 34, flo: 28, rays: 24, sw: 45, sz: 17, face: true },
        reels: { spd: 40, cell: 110, spin: 55, busy: 26, dir: 'dtlbr' }
    };

    // ── colour helpers ──────────────────────────────────────────────────────
    function hex2rgb(h) {
        h = String(h).replace('#', '');
        if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
        return [parseInt(h.slice(0,2),16), parseInt(h.slice(2,4),16), parseInt(h.slice(4,6),16)];
    }
    function shade(hex, f) {                       // f<1 darken, f>1 lighten (toward white)
        var c = hex2rgb(hex);
        if (f <= 1) return 'rgb(' + c.map(function (v) { return Math.round(v*f); }).join(',') + ')';
        var t = f - 1;
        return 'rgb(' + c.map(function (v) { return Math.round(v + (255-v)*t); }).join(',') + ')';
    }
    function rnd(seed) { var x = Math.sin(seed*127.1) * 43758.5453; return x - Math.floor(x); }
    function spOf(speed) { return 0.2 + (Math.max(1, Math.min(100, speed || 40)) / 100) * 1.6; }

    // AURORA/PARADE wave directions → per-cell order value (shared by REELS + borders)
    function orderVal(dir, r, c, rows, cols) {
        switch (dir) {
            case 'ltr':   return c;
            case 'rtl':   return (cols - 1 - c);
            case 'ttb':   return r;
            case 'btt':   return (rows - 1 - r);
            case 'dbrtl': return (rows - 1 - r) + (cols - 1 - c);
            default:      return r + c; // dtlbr ↘
        }
    }

    function init() {
        var host = document.querySelector('.jt-jive-turkey-bg');
        if (!host) return;

        // ── resolve config ──────────────────────────────────────────────────
        var COLOURWAYS = COLOURWAYS_DEFAULT;
        try {
            var cwRaw = JSON.parse(host.getAttribute('data-jt-colourways') || 'null');
            if (cwRaw && typeof cwRaw === 'object' && Object.keys(cwRaw).length) COLOURWAYS = cwRaw;
        } catch (e) {}

        // Active colourway: by name, else assembled from palette/field (back-compat).
        var activeName = (host.getAttribute('data-jt-colourway') || '').toUpperCase();
        if (!COLOURWAYS[activeName]) {
            var pal = ['#d99a2b', '#bd4e1f', '#6b3f24'];
            try { var raw = JSON.parse(host.getAttribute('data-jt-palette') || '[]'); if (Array.isArray(raw) && raw.length) pal = raw; } catch (e2) {}
            var field = host.getAttribute('data-jt-field') || '#f2e2c0';
            activeName = activeName || 'ACTIVE';
            COLOURWAYS[activeName] = { cream: field, colors: pal, centre: pal[0], dark: shade(field, 0.28) };
        }
        // Backfill any missing centre/dark so DAISY/REELS never break.
        Object.keys(COLOURWAYS).forEach(function (k) {
            var cw = COLOURWAYS[k];
            if (!cw.centre) cw.centre = (cw.colors && cw.colors[0]) || '#d99a2b';
            if (!cw.dark)   cw.dark   = shade(cw.cream || '#f2e2c0', 0.28);
        });

        var mode0 = (host.getAttribute('data-jt-mode') || 'flow').toLowerCase();
        var gSpeed = Math.max(1, Math.min(100, parseFloat(host.getAttribute('data-jt-speed')) || 40));
        var cycleSecs = Math.max(6, parseFloat(host.getAttribute('data-jt-cycle')) || 18);
        var randomColour = host.getAttribute('data-jt-random-colour') === '1';

        var savedModes = {};
        try { savedModes = JSON.parse(host.getAttribute('data-jt-modes') || '{}') || {}; } catch (e) {}
        function paramsFor(m) {
            var d = MODE_DEFAULTS[m] || {}, out = {};
            for (var k in d) out[k] = d[k];
            var s = savedModes[m] || {};
            for (var k2 in s) out[k2] = s[k2];
            if ((m === 'scope' || m === 'bloom' || m === 'flow') && out.speed == null) out.speed = gSpeed;
            return out;
        }

        var pool = ALL_MODES.slice();
        try {
            var pRaw = JSON.parse(host.getAttribute('data-jt-pool') || 'null');
            if (Array.isArray(pRaw) && pRaw.length) pool = pRaw.filter(function (m) { return ALL_MODES.indexOf(m) >= 0; });
        } catch (e) {}
        if (!pool.length) pool = ALL_MODES.slice();
        var cwNames = Object.keys(COLOURWAYS);

        // ── canvas ──────────────────────────────────────────────────────────
        var cv = host.querySelector('canvas.jt-canvas');
        if (!cv) { cv = document.createElement('canvas'); cv.className = 'jt-canvas'; host.appendChild(cv); }
        var ctx = cv.getContext('2d');
        function sizeCanvas() { cv.width = Math.max(1, window.innerWidth); cv.height = Math.max(1, window.innerHeight); }
        window.addEventListener('resize', sizeCanvas);
        sizeCanvas();

        // Broadcast the live colourway so the border engine (and anyone else) matches.
        var lastAnnounced = null;
        function announce(name) {
            if (name === lastAnnounced) return;
            lastAnnounced = name;
            var cw = COLOURWAYS[name];
            if (!cw) return;
            // Retained handshake so a border engine that inits AFTER us (or in the
            // reduced-motion path) still learns the live colourway without the event.
            try { window.__JT_COLOURWAY = { name: name, cream: cw.cream, colors: cw.colors.slice() }; } catch (e0) {}
            try { window.dispatchEvent(new CustomEvent('jt:colourway', { detail: { name: name, cream: cw.cream, colors: cw.colors.slice() } })); } catch (e) {}
            document.documentElement.setAttribute('data-jt-colourway', name);
        }

        // ── SCOPE — reflection kaleidoscope ─────────────────────────────────
        function drawScope(T, cw, P) {
            var sp = spOf(P.speed), PAL = cw.colors, FIELD = cw.cream;
            var w = cv.width, h = cv.height, cx = w/2, cy = h/2;
            var R = Math.hypot(w, h) / 2 * 1.08;
            ctx.fillStyle = FIELD; ctx.fillRect(0, 0, w, h);
            var N = 14, seg = Math.PI * 2 / N;
            var shapes = [
                { r: 0.34, a: 0.30, sz: 0.20, ci: 0, sp: 0.5 },
                { r: 0.62, a: 0.6