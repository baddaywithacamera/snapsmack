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
 *   SCROLLS — parallel 70s striped ribbons (brown/red/orange/cream) with spiral
 *             scroll-heads that all curl the same way and travel together along
 *             the ribbons; vertical or horizontal (data-jt-scrolls-axis).
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
 *   data-jt-mode        scope|bloom|flow|daisy|reels|scrolls|cycle|surprise
 *   data-jt-scrolls-axis  v|h — SCROLLS ribbon direction (vertical/horizontal)
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
    var ALL_MODES = ['scope', 'bloom', 'flow', 'daisy', 'reels', 'scrolls'];
    var MODE_DEFAULTS = {
        scope: { speed: 40 },
        bloom: { speed: 40 },
        flow:  { speed: 40 },
        daisy: { ray: 34, flo: 28, rays: 24, sw: 45, sz: 17, face: true },
        reels: { spd: 40, cell: 110, spin: 55, busy: 26, dir: 'dtlbr' },
        scrolls: { speed: 40, axis: 'v' }
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
        // SOLID / OFF: no animated canvas. Remove any existing canvas and let the
        // page's own background colour (--bg-primary, the "Page Background" control)
        // show through the transparent carrier. Set Page Background to #ffffff for white.
        if (mode0 === 'solid' || mode0 === 'off' || mode0 === 'none') {
            var _cvOld = host.querySelector('canvas.jt-canvas');
            if (_cvOld) _cvOld.parentNode.removeChild(_cvOld);
            host.classList.add('jt-bg-solid');
            return;
        }
        var gSpeed = Math.max(1, Math.min(100, parseFloat(host.getAttribute('data-jt-speed')) || 40));
        // Global Background Speed (jt_speed) now scales ALL modes. Neutral (=1) at the
        // default 45 so untouched looks are unchanged; DAISY & REELS previously ignored it.
        var gFactor = spOf(gSpeed) / spOf(45);
        var cycleSecs = Math.max(6, parseFloat(host.getAttribute('data-jt-cycle')) || 18);
        var randomColour = host.getAttribute('data-jt-random-colour') === '1';
        var scrollsAxis = (host.getAttribute('data-jt-scrolls-axis') || 'down').toLowerCase();
        var scrollsAngle = 0;
        (function () {
            // 8 travel directions = the "scrolls down" tiling rotated in 45° steps.
            // ctx.rotate is clockwise (y is down), so local +y ("down") maps to screen
            // (-sin a, cos a): 0=down, PI=up, PI/2=left, -PI/2=right, and the diagonals
            // as below. v/h/diag/diag2 kept as back-compat aliases for saved settings.
            var ANG = {
                down: 0,           up: Math.PI,
                left: Math.PI/2,   right: -Math.PI/2,
                dr: -Math.PI/4,    dl: Math.PI/4,          // down-right / down-left
                ur: -3*Math.PI/4,  ul: 3*Math.PI/4,        // up-right   / up-left
                v: 0, h: Math.PI/2, diag: Math.PI/4, diag2: -Math.PI/4
            };
            var EIGHT = ['down','up','left','right','dr','dl','ur','ul'];
            var pick = scrollsAxis;
            if (pick === 'random') { pick = EIGHT[Math.floor(Math.random()*EIGHT.length)]; }
            scrollsAngle = (ANG[pick] != null) ? ANG[pick] : 0;
        })();
        var scrollsFade = (host.getAttribute('data-jt-scrolls-fade') || 'fade').toLowerCase();
        if (['fade','blink','off'].indexOf(scrollsFade) < 0) scrollsFade = 'fade';

        var savedModes = {};
        try { savedModes = JSON.parse(host.getAttribute('data-jt-modes') || '{}') || {}; } catch (e) {}
        function paramsFor(m) {
            var d = MODE_DEFAULTS[m] || {}, out = {};
            for (var k in d) out[k] = d[k];
            var s = savedModes[m] || {};
            for (var k2 in s) out[k2] = s[k2];
            if ((m === 'scope' || m === 'bloom' || m === 'flow') && out.speed == null) out.speed = gSpeed;
            if (m === 'scrolls') { out.axis = scrollsAxis; out.angle = scrollsAngle; out.fade = scrollsFade; }
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
            var N = 20, seg = Math.PI * 2 / N;
            function scopeFlower(fx, fy, fr, dark){ ctx.save(); ctx.translate(fx, fy); ctx.rotate(T*0.25); var FP = 8; ctx.strokeStyle = INK; ctx.lineWidth = Math.max(1.2, fr*0.06); ctx.lineJoin = 'round'; for (var fi = 0; fi < FP; fi++){ ctx.save(); ctx.rotate((fi/FP)*Math.PI*2); ctx.beginPath(); ctx.ellipse(0, -fr*0.60, fr*0.30, fr*0.46, 0, 0, Math.PI*2); var fcol = (fi % 2 === 0) ? PAL[0] : PAL[1]; ctx.fillStyle = dark ? shade(fcol, 0.62) : fcol; ctx.fill(); ctx.stroke(); ctx.restore(); } ctx.beginPath(); ctx.arc(0,0,fr*0.40,0,Math.PI*2); ctx.fillStyle = dark ? shade(cw.centre, 0.62) : cw.centre; ctx.fill(); ctx.stroke(); ctx.restore(); }
            var shapes = [
                { r: 0.34, a: 0.30, sz: 0.20, ci: 0, sp: 0.5 },
                { r: 0.62, a: 0.62, sz: 0.15, ci: 1, sp: -0.35 },
                { r: 0.82, a: 0.40, sz: 0.11, ci: 2, sp: 0.7 }
            ];
            for (var i = 0; i < N; i++) {
                ctx.save();
                ctx.translate(cx, cy);
                if (i % 2 === 0) ctx.rotate(i * seg);
                else { ctx.rotate((i + 1) * seg); ctx.scale(1, -1); }
                ctx.beginPath(); ctx.moveTo(0, 0);
                ctx.arc(0, 0, R, 0, seg); ctx.closePath(); ctx.clip();
                var dark = (i % 2 === 1);
                ctx.fillStyle = dark ? shade(FIELD, 0.82) : FIELD;
                ctx.beginPath(); ctx.moveTo(0, 0); ctx.arc(0, 0, R, 0, seg); ctx.closePath(); ctx.fill();
                for (var s = 0; s < shapes.length; s++) {
                    var sh = shapes[s];
                    var ang = sh.a * seg + Math.sin(T * sh.sp * sp + s) * seg * 0.12;
                    var rad = sh.r * R + Math.sin(T * sh.sp * 0.7 * sp + s * 2) * R * 0.05;
                    var x = Math.cos(ang) * rad, y = Math.sin(ang) * rad;
                    var col = PAL[sh.ci % PAL.length];
                    ctx.fillStyle = dark ? shade(col, 0.6) : col;
                    ctx.beginPath(); ctx.arc(x, y, sh.sz * R, 0, Math.PI * 2); ctx.fill();
                }
                scopeFlower(Math.cos(seg*0.5)*R*0.52, Math.sin(seg*0.5)*R*0.52, R*0.14, dark);
                ctx.restore();
            }
        }

        // ── BLOOM — quatrefoil flower field, floats + slow rotate ───────────
        function flower(x, y, r, col) {
            ctx.fillStyle = col;
            var p = r * 0.60;
            ctx.beginPath(); ctx.arc(x, y - p, r*0.62, 0, 7); ctx.fill();
            ctx.beginPath(); ctx.arc(x + p, y, r*0.62, 0, 7); ctx.fill();
            ctx.beginPath(); ctx.arc(x, y + p, r*0.62, 0, 7); ctx.fill();
            ctx.beginPath(); ctx.arc(x - p, y, r*0.62, 0, 7); ctx.fill();
            ctx.fillStyle = shade(col, 0.72);
            ctx.beginPath(); ctx.arc(x, y, r*0.42, 0, 7); ctx.fill();
        }
        function drawBloom(T, cw, P) {
            var sp = spOf(P.speed), PAL = cw.colors, FIELD = cw.cream;
            var w = cv.width, h = cv.height;
            ctx.fillStyle = FIELD; ctx.fillRect(0, 0, w, h);
            var cell = 150, r = cell * 0.5;
            ctx.save();
            ctx.translate(w/2, h/2);
            ctx.rotate(Math.sin(T * 0.05 * sp) * 0.10);
            ctx.translate(-w/2, -h/2);
            var ox = Math.sin(T * 0.10 * sp) * 22, oy = Math.cos(T * 0.08 * sp) * 22;
            var cols = Math.ceil(w / cell) + 3, rows = Math.ceil(h / cell) + 3;
            for (var gy = -2; gy < rows; gy++) {
                for (var gx = -2; gx < cols; gx++) {
                    var seed = gx * 73.7 + gy * 91.3;
                    var stagger = (gy % 2) ? cell * 0.5 : 0;
                    var x = gx * cell + stagger + ox, y = gy * cell + oy;
                    var ci = (Math.floor(T * 0.25 * sp + rnd(seed) * 9) + (gx + gy)) % PAL.length;
                    flower(x, y, r, PAL[(ci + PAL.length) % PAL.length]);
                }
            }
            ctx.restore();
        }

        // ── FLOW — flowing racing-stripe ribbons (boundary-band fill) ───────
        function drawFlow(T, cw, P) {
            var sp = spOf(P.speed), PAL = cw.colors, FIELD = cw.cream;
            var w = cv.width, h = cv.height;
            ctx.fillStyle = FIELD; ctx.fillRect(0, 0, w, h);
            var N = 13, amp = 130, swirl = 0.5;
            var D = Math.hypot(w, h) * 1.25, step = D / N, k = (Math.PI*2) / (D*0.55), uSteps = 48;
            var cols = [], ci = 0;
            for (var b = 0; b < N; b++) { if (b % 3 === 2) cols.push(FIELD); else cols.push(PAL[(ci++) % PAL.length]); }
            ctx.save();
            ctx.translate(w/2, h/2);
            ctx.rotate((18 * Math.PI/180) + Math.sin(T * 0.12 * sp) * swirl * 0.5);
            function wAt(idx, u) {
                return -D/2 + idx*step
                     + amp*Math.sin(k*u + T*sp + idx*0.55)
                     + amp*0.35*swirl*Math.sin(k*1.9*u - T*sp*0.7 + idx*0.9);
            }
            for (var i = 0; i < N; i++) {
                ctx.fillStyle = cols[i]; ctx.beginPath();
                for (var s = 0; s <= uSteps; s++) { var u = -D/2 + (s/uSteps)*D; var y = wAt(i, u); if (s===0) ctx.moveTo(u,y); else ctx.lineTo(u,y); }
                for (var s2 = uSteps; s2 >= 0; s2--) { var u2 = -D/2 + (s2/uSteps)*D; ctx.lineTo(u2, wAt(i+1, u2)); }
                ctx.closePath(); ctx.fill();
            }
            ctx.restore();
        }

        // ── DAISY — sunburst rays around a floating smiley daisy ────────────
        function rayCols(cw, N) {
            var out = [], ci = 0;
            for (var i = 0; i < N; i++) { if (i % 2 === 1) out.push(cw.cream); else { out.push(cw.colors[ci % cw.colors.length]); ci++; } }
            return out;
        }
        function drawDaisyFlower(x, y, R, cw, petalPhase, face) {
            ctx.save(); ctx.translate(x, y);
            var P = 11;
            var petPal = cw.colors.concat([cw.cream]);
            var shift = Math.floor(petalPhase);
            ctx.strokeStyle = INK; ctx.lineWidth = Math.max(2, R*0.05); ctx.lineJoin = 'round';
            for (var i = 0; i < P; i++) {
                var a = (i/P)*Math.PI*2;
                ctx.save(); ctx.rotate(a);
                ctx.beginPath(); ctx.ellipse(0, -R*0.66, R*0.26, R*0.42, 0, 0, Math.PI*2);
                ctx.fillStyle = petPal[((i + shift) % petPal.length + petPal.length) % petPal.length]; ctx.fill(); ctx.stroke();
                ctx.restore();
            }
            ctx.beginPath(); ctx.arc(0, 0, R*0.46, 0, Math.PI*2);
            ctx.fillStyle = cw.centre; ctx.fill(); ctx.stroke();
            if (face) {
                ctx.fillStyle = INK;
                ctx.beginPath(); ctx.ellipse(-R*0.17, -R*0.08, R*0.06, R*0.09, 0, 0, 7); ctx.fill();
                ctx.beginPath(); ctx.ellipse( R*0.17, -R*0.08, R*0.06, R*0.09, 0, 0, 7); ctx.fill();
                ctx.beginPath(); ctx.lineWidth = Math.max(2, R*0.045); ctx.strokeStyle = INK;
                ctx.arc(0, R*0.02, R*0.22, 0.15*Math.PI, 0.85*Math.PI); ctx.stroke();
            }
            ctx.restore();
        }
        function drawDaisy(T, cw, P) {
            var w = cv.width, h = cv.height;
            ctx.fillStyle = cw.cream; ctx.fillRect(0, 0, w, h);
            var rs = (P.ray/100)*0.7 * gFactor, fs = (P.flo/100)*0.5 * gFactor;
            var N = P.rays|0; if (N < 6) N = 6;
            var seg = Math.PI*2/N, curl = (P.sw/100) * 2.4;
            // Start the drift already at the lower-right spread (Sean, per screenshot) rather
            // than dead-centre — a speed-independent phase offset. +1.0 on fx and +1.3 on fy
            // (=1.3x, matching fy's 1.3x frequency) is a clean time-shift of the identical
            // Lissajous: same path, same direction, just begun further along. Nothing else changed.
            var fx = w*0.5 + Math.sin(T*fs + 1.0)*w*0.40;      // wide enough that the daisy swings out past the centre readability panel into the side margins, fully visible
            var fy = h*0.5 + Math.sin(T*fs*1.3 + 1.0 + 1.3)*h*0.30;
            var R = Math.hypot(w, h)*1.05;
            var cols = rayCols(cw, N), rot = T*rs, steps = 26;
            ctx.save(); ctx.translate(fx, fy);
            for (var i = 0; i < N; i++) {
                ctx.fillStyle = cols[i];
                ctx.beginPath(); ctx.moveTo(0, 0);
                for (var s = 0; s <= steps; s++) { var r = (s/steps)*R; var a = rot + i*seg + curl*(r/R); ctx.lineTo(Math.cos(a)*r, Math.sin(a)*r); }
                for (var s2 = steps; s2 >= 0; s2--) { var r2 = (s2/steps)*R; var a2 = rot + (i+1)*seg + curl*(r2/R); ctx.lineTo(Math.cos(a2)*r2, Math.sin(a2)*r2); }
                ctx.closePath(); ctx.fill();
            }
            ctx.restore();
            drawDaisyFlower(fx, fy, Math.min(w, h)*(P.sz/100), cw, T*fs, P.face !== false);
        }

        // ── REELS — Bauhaus shuffle grid on the dark field ──────────────────
        function shapeFor(idx, cyc, pal) {
            var s = idx*13.1 + cyc*57.7;
            var type = ['solid','split','stripe','semi','split'][Math.floor(rnd(s)*5)];
            var a = Math.floor(rnd(s+1)*pal.length);
            var b = (a + 1 + Math.floor(rnd(s+2)*(pal.length-1))) % pal.length;
            var rot = Math.floor(rnd(s+3)*4);
            var spin = rnd(s+4) < 0.5 ? 1 : -1;
            return { type: type, colA: pal[a], colB: pal[b], rot: rot, spin: spin };
        }
        function drawReelCell(cx, cy, D, sh, sc, extraRot, dark) {
            var R = D*0.5;
            ctx.save();
            ctx.translate(cx, cy);
            ctx.scale(sc, sc);
            ctx.rotate(sh.rot*Math.PI/2 + extraRot);
            ctx.beginPath(); ctx.arc(0, 0, R, 0, Math.PI*2); ctx.clip();
            if (sh.type === 'solid') {
                ctx.fillStyle = sh.colA; ctx.beginPath(); ctx.arc(0, 0, R, 0, 7); ctx.fill();
            } else if (sh.type === 'split') {
                ctx.fillStyle = sh.colB; ctx.fillRect(-R, -R, 2*R, 2*R);
                ctx.fillStyle = sh.colA; ctx.fillRect(-R, -R, 2*R, R);
            } else if (sh.type === 'semi') {
                ctx.fillStyle = dark; ctx.fillRect(-R, -R, 2*R, 2*R);
                ctx.fillStyle = sh.colA; ctx.fillRect(-R, -R, 2*R, R);
            } else {
                ctx.fillStyle = sh.colA; ctx.fillRect(-R, -R, 2*R, 2*R);
                ctx.save(); ctx.beginPath(); ctx.rect(-R, 0, 2*R, R); ctx.clip();
                ctx.fillStyle = sh.colB; var n = 3, band = R/(n*2);
                for (var i = 0; i < n; i++) { ctx.fillRect(-R, (i*2+0.5)*band, 2*R, band); }
                ctx.restore();
            }
            ctx.restore();
        }
        function drawReels(T, cw, P) {
            var pal = cw.colors.concat([cw.cream]);
            var w = cv.width, h = cv.height;
            ctx.fillStyle = cw.dark; ctx.fillRect(0, 0, w, h);
            var D = P.cell|0; if (D < 40) D = 40;
            var cols = Math.ceil(w/D), rows = Math.ceil(h/D);
            var ox = (w - cols*D)/2, oy = (h - rows*D)/2;
            var spd = (0.15 + (P.spd/100)*0.9) * gFactor;
            var spinAmt = (P.spin/100) * Math.PI;
            var busyF = Math.max(0.2, P.busy/30);
            for (var gy = 0; gy < rows; gy++) {
                for (var gx = 0; gx < cols; gx++) {
                    var idx = gy*cols + gx;
                    var period = (4.0 + rnd(idx*2.7)*4.0) / busyF;
                    var offset = orderVal(P.dir, gy, gx, rows, cols)*0.16 + rnd(idx*5.3)*0.22;
                    var local = T*spd/period + offset;
                    var cyc = Math.floor(local), f = local - cyc;
                    var sc, shownCyc;
                    if (f < 0.86) { sc = 1; shownCyc = cyc; }
                    else if (f < 0.93) { sc = 1 - (f-0.86)/0.07; shownCyc = cyc; }
                    else { sc = (f-0.93)/0.07; shownCyc = cyc+1; }
                    if (sc <= 0.001) continue;
                    var sh = shapeFor(idx, shownCyc, pal);
                    var extraRot = (1-sc) * spinAmt * sh.spin;
                    drawReelCell(ox + gx*D + D/2, oy + gy*D + D/2, D, sh, Math.max(0, sc), extraRot, cw.dark);
                }
            }
        }

        // ── SCROLLS — the 70s ribbon-scroll tile, recoloured onto the palette ──
        // Recolours the ORIGINAL 5-tone tile (field, pale centre-line, orange, red,
        // brown all DISTINCT — the vector trace had merged field+centre-line and it
        // went flat). Two lightest tones map to SHADED cream (field) and FULL cream
        // (centre-line) so the loop-line lifts off the field. Per colourway + colour-
        // rotation STATE an offscreen tile is cached, then tiled + scrolled seamlessly.
        // Direction v|h|diag|diag2|random. Colour drift fade|blink|off: the 3 palette
        // colours rotate slowly through the 3 chromatic bands (field + centre-line
        // hold), fade = graceful crossfade, blink = hard switch.
        var JT_SC_SRC = [[77,37,5],[254,51,1],[247,151,19],[224,194,167],[247,236,180]];
        var JT_SC_URI = "data:image/png;base64,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";
        var _sc_srcData = null, _sc_ready = false, _sc_tileCache = {};
        (function () {
            var im = new Image();
            im.onload = function () {
                var oc = document.createElement('canvas'); oc.width = 740; oc.height = 740;
                var octx = oc.getContext('2d'); octx.drawImage(im, 0, 0, 740, 740);
                try { _sc_srcData = octx.getImageData(0, 0, 740, 740); _sc_ready = true; _sc_tileCache = {}; } catch (e) {}
            };
            im.src = JT_SC_URI;
        })();
        function scLum(c) { return 0.299*c[0] + 0.587*c[1] + 0.114*c[2]; }
        function scShadeArr(hex, f) { var c = hex2rgb(hex); return [Math.round(c[0]*f), Math.round(c[1]*f), Math.round(c[2]*f)]; }
        function scKey(cw) { return cw.cream + '|' + cw.colors.join(','); }
        function scTile(cw, state) {
            var k = scKey(cw) + '|' + state; if (_sc_tileCache[k]) return _sc_tileCache[k];
            if (!_sc_ready) return null;
            var cr = hex2rgb(cw.cream), a = hex2rgb(cw.colors[0]), b = hex2rgb(cw.colors[1]), c = hex2rgb(cw.colors[2]);
            var field = scShadeArr(cw.cream, 0.82);
            var sByLum = [0,1,2,3,4].sort(function (i, j) { return scLum(JT_SC_SRC[i]) - scLum(JT_SC_SRC[j]); }); // dark->light: brown,red,orange,field,centre
            var base = [c, b, a];                                              // dark->light chromatic palette
            var map = {}, i;
            for (i = 0; i < 3; i++) { var s = JT_SC_SRC[sByLum[i]]; var t = base[(i + state) % 3]; map[s[0]+','+s[1]+','+s[2]] = t; }
            var sf = JT_SC_SRC[sByLum[3]]; map[sf[0]+','+sf[1]+','+sf[2]] = field;    // field holds
            var s5 = JT_SC_SRC[sByLum[4]]; map[s5[0]+','+s5[1]+','+s5[2]] = cr;       // centre-line holds
            var oc = document.createElement('canvas'); oc.width = 740; oc.height = 740;
            var octx = oc.getContext('2d');
            var id = octx.createImageData(740, 740), sd = _sc_srcData.data, dd = id.data, p, q;
            for (p = 0; p < sd.length; p += 4) {
                var key = sd[p] + ',' + sd[p+1] + ',' + sd[p+2], t2 = map[key];
                if (!t2) {
                    var best = null, bd = 1e12;
                    for (q = 0; q < 5; q++) { var s2 = JT_SC_SRC[q]; var dq = (sd[p]-s2[0])*(sd[p]-s2[0]) + (sd[p+1]-s2[1])*(sd[p+1]-s2[1]) + (sd[p+2]-s2[2])*(sd[p+2]-s2[2]); if (dq < bd) { bd = dq; best = s2; } }
                    t2 = map[best[0]+','+best[1]+','+best[2]];
                }
                dd[p] = t2[0]; dd[p+1] = t2[1]; dd[p+2] = t2[2]; dd[p+3] = 255;
            }
            octx.putImageData(id, 0, 0);
            _sc_tileCache[k] = oc; return oc;
        }
        function drawScrolls(T, cw, P) {
            var w = cv.width, h = cv.height;
            var field = scShadeArr(cw.cream, 0.82);
            ctx.fillStyle = 'rgb(' + field[0] + ',' + field[1] + ',' + field[2] + ')'; ctx.fillRect(0, 0, w, h);
            if (!_sc_ready) return;
            var ang = (P.angle != null) ? P.angle : 0;
            var sp = spOf(P.speed) * gFactor, SC = 0.62, STEP = 740 * SC;
            var travel = ((T * sp * 30) % STEP + STEP) % STEP;                 // wraps seamlessly
            var D = Math.hypot(w, h), half = Math.ceil((D / 2) / STEP) + 1, gx, gy;
            var fade = P.fade || 'fade', PERIOD = 8, ph = T / PERIOD, st = Math.floor(ph) % 3, fr = ph - Math.floor(ph);
            var tileA, tileB = null, alpha = 0;
            if (fade === 'off') { tileA = scTile(cw, 0); }
            else if (fade === 'blink') { tileA = scTile(cw, st); }
            else { tileA = scTile(cw, st); if (fr >= 0.6) { tileB = scTile(cw, (st + 1) % 3); alpha = (fr - 0.6) / 0.4; } }
            if (!tileA) return;
            ctx.save(); ctx.translate(w/2, h/2); ctx.rotate(ang);
            function paint(tile) { for (gy = -half; gy <= half; gy++) for (gx = -half; gx <= half; gx++) { ctx.drawImage(tile, gx*STEP, gy*STEP + travel, STEP, STEP); } }
            paint(tileA);
            if (tileB) { ctx.globalAlpha = alpha; paint(tileB); ctx.globalAlpha = 1; }
            ctx.restore();
        }

        var DRAW = { scope: drawScope, bloom: drawBloom, flow: drawFlow, daisy: drawDaisy, reels: drawReels, scrolls: drawScrolls };
        function drawMode(m, T, cwName) {
            if (!DRAW[m]) m = 'flow';
            var cw = COLOURWAYS[cwName] || COLOURWAYS[activeName];
            DRAW[m](T, cw, paramsFor(m));
        }

        // ── mode / colourway resolution ─────────────────────────────────────
        function pickRandom(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

        // SURPRISE decides ONCE per load and holds for the whole visit. Instead
        // of rolling dice and hoping (which, with random-colour OFF and only a
        // handful of modes, quickly memorised the whole menu and collapsed back
        // to pure random — the source of the repeats), it now deals a SHUFFLE
        // BAG: every enabled combo is dealt once before ANY repeat, the deck is
        // reshuffled only when empty, no two consecutive loads share the same
        // MODE, and a fresh deck never opens on the mode you just saw. Session-
        // scoped (sessionStorage), no cookies, cleared when the tab closes.
        var surprisePick = (mode0 === 'surprise') ? jtSurprisePick() : null;

        function jtFY(a) {
            for (var i = a.length - 1; i > 0; i--) {
                var j = Math.floor(Math.random() * (i + 1));
                var t = a[i]; a[i] = a[j]; a[j] = t;
            }
            return a;
        }
        function jtModeOf(key) { var s = key.indexOf('|'); return s >= 0 ? key.slice(0, s) : key; }
        function jtKeyToPick(key) {
            var s = key.indexOf('|');
            var m = s >= 0 ? key.slice(0, s) : key;
            var c = s >= 0 ? key.slice(s + 1) : activeName;
            if (pool.indexOf(m) < 0) m = pool[0] || 'flow';
            if (!COLOURWAYS[c]) c = activeName;
            return { mode: m, cw: c };
        }
        // Deal a fresh deck of every enabled combo, mode-spread so no two
        // adjacent cards share a mode (greedy: always deal from the largest
        // remaining mode-bucket that isn't the previous mode). Seeded with the
        // last-shown mode so the deck's first card can't repeat it either.
        function jtBuildDeck(lastMode) {
            var buckets = {}, modes = [], total = 0, i, j;
            for (i = 0; i < pool.length; i++) {
                var m = pool[i], entries = [];
                if (randomColour && cwNames.length) {
                    for (j = 0; j < cwNames.length; j++) entries.push(m + '|' + cwNames[j]);
                } else {
                    entries.push(m + '|' + activeName);
                }
                jtFY(entries);
                buckets[m] = entries; modes.push(m); total += entries.length;
            }
            var deck = [], prev = lastMode;
            for (var n = 0; n < total; n++) {
                var cand = [];
                for (i = 0; i < modes.length; i++) if (buckets[modes[i]].length) cand.push(modes[i]);
                var avoid = cand.filter(function (m) { return m !== prev; });
                var list = avoid.length ? avoid : cand;
                list.sort(function (x, y) { return buckets[y].length - buckets[x].length; });
                var top = buckets[list[0]].length;
                var tied = list.filter(function (m) { return buckets[m].length === top; });
                var chosen = tied[Math.floor(Math.random() * tied.length)];
                deck.push(buckets[chosen].pop());
                prev = chosen;
            }
            return deck;
        }
        function jtSurprisePick() {
            // Combo space for the CURRENT settings (enabled pool, colourways,
            // whether both barrels is on).
            var space = {}, count = 0, i, j;
            if (randomColour && cwNames.length) {
                for (i = 0; i < pool.length; i++)
                    for (j = 0; j < cwNames.length; j++) { space[pool[i] + '|' + cwNames[j]] = 1; count++; }
            } else {
                for (i = 0; i < pool.length; i++) { space[pool[i] + '|' + activeName] = 1; count++; }
            }
            if (count <= 1) {
                for (var only in space) if (space.hasOwnProperty(only)) return jtKeyToPick(only);
                return jtKeyToPick((pool[0] || 'flow') + '|' + activeName);
            }
            var bag = [], last = '';
            try { bag = JSON.parse(sessionStorage.getItem('jtSurpriseBag') || '[]') || []; } catch (e) { bag = []; }
            try { last = sessionStorage.getItem('jtSurpriseLast') || ''; } catch (e) { last = ''; }
            if (!Array.isArray(bag)) bag = [];
            bag = bag.filter(function (k) { return space[k]; });   // drop stale cards if settings changed
            if (!bag.length) bag = jtBuildDeck(last ? jtModeOf(last) : null);
            var key = bag.shift();
            try { sessionStorage.setItem('jtSurpriseBag', JSON.stringify(bag)); } catch (e) {}
            try { sessionStorage.setItem('jtSurpriseLast', key); } catch (e) {}
            return jtKeyToPick(key);
        }

        // CYCLE state
        var cyMode = null, cyCw = activeName, cyStep = -1;
        function resolve(T) {
            if (surprisePick) return surprisePick;
            if (mode0 === 'cycle') {
                var step = Math.floor(T / cycleSecs);
                if (step !== cyStep) {
                    cyStep = step;
                    cyMode = pool[step % pool.length];
                    if (randomColour && cwNames.length) cyCw = cwNames[step % cwNames.length];
                }
                return { mode: cyMode || pool[0], cw: cyCw };
            }
            return { mode: (DRAW[mode0] ? mode0 : 'flow'), cw: activeName };
        }

        // ── run loop ────────────────────────────────────────────────────────
        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) { var p0 = resolve(0); announce(p0.cw); drawMode(p0.mode, 0, p0.cw); return; }

        var rafId = null, lastDraw = 0;
        function frame(now) {
            if (now - lastDraw >= 32) {
                lastDraw = now;
                var t = now/1000, p = resolve(t);
                announce(p.cw);
                drawMode(p.mode, t, p.cw);
            }
            rafId = window.requestAnimationFrame(frame);
        }
        function start() { if (rafId === null) rafId = window.requestAnimationFrame(frame); }
        function stop() { if (rafId !== null) { window.cancelAnimationFrame(rafId); rafId = null; } }
        document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
        if (!document.hidden) start();

        // Test hook (harness only; harmless in production).
        window.__jtSet = function (m, cwName, opts) {
            opts = opts || {};
            if (m) { mode0 = m; surprisePick = (m === 'surprise')
                ? { mode: pickRandom(pool), cw: (randomColour && cwNames.length) ? pickRandom(cwNames) : (cwName || activeName) }
                : null; cyStep = -1; }
            if (cwName && COLOURWAYS[cwName.toUpperCase()]) activeName = cwName.toUpperCase();
            if (opts.randomColour != null) randomColour = !!opts.randomColour;
            lastAnnounced = null;
        };
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
