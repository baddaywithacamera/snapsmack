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

        // ── SCROLLS — the 70s ribbon-scroll, drawn as PURE VECTOR geometry ──
        // No raster tile any more: the pattern IS math. Two mirrored striped
        // ribbons (brown/red/orange/cream on a white field) run the full
        // canvas height, sharing a brown stripe at each column seam; each
        // ribbon curls into a loop drawn as four concentric discs (K,R,O,C
        // big->small, ring widths = stripe width) tangent to the stem, then
        // the stem is REDRAWN over the loop's entry half (above centre for
        // the "b", below for the 180-rotated "q") so the band reads as a
        // ribbon sliding into its own coil, exactly like the source art.
        // Drawn at render resolution every frame: curves are anti-aliased
        // by the canvas rasteriser at final scale (smooth at any size) and
        // stripes are continuous full-height fills (no tile joints, nothing
        // to crawl). Layered fills (wide under narrow) keep every internal
        // boundary paint-over-paint - no AA hairline cracks. Colour drift
        // fade|blink|off unchanged: 3 chromatic bands rotate through the
        // palette (dark->light = colors[2],[1],[0]), field + cream hold.
        // Geometry traced from Sean's vector art, in tile units (254x300):
        var SC_TW = 254, SC_TH = 300;         // pattern period
        var SC_SW = 17;                       // stripe / ring width
        var SC_R0 = 26.5;                     // centre-disc radius
        var SC_BX = 63,    SC_BY = 85;        // left loop centre ("b")
        var SC_QX = 178.5, SC_QY = 215;       // right loop centre ("q" = the b rotated 180)
        function drawScrolls(T, cw, P) {
            var w = cv.width, h = cv.height;
            ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, w, h);
            var ang = (P.angle != null) ? P.angle : 0;
            var sp = spOf(P.speed) * gFactor;
            var s = 145 / SC_TW, TW = SC_TW * s, TH = SC_TH * s, SW = SC_SW * s, R0 = SC_R0 * s;
            var travel = ((T * sp * 30) % TH + TH) % TH;
            var D = Math.hypot(w, h), halfX = Math.ceil((D / 2) / TW) + 1, halfY = Math.ceil((D / 2) / TH) + 1;
            var H2 = D / 2 + TH, gx, gy, i, k;
            var fade = P.fade || 'fade', PERIOD = 8, ph = T / PERIOD, st = Math.floor(ph) % 3, fr = ph - Math.floor(ph);
            function palFor(state) {          // fills for the bands [K,R,O,C]
                var base = [cw.colors[2], cw.colors[1], cw.colors[0]];   // dark->light chromatic palette
                return [base[state % 3], base[(1 + state) % 3], base[(2 + state) % 3], cw.cream];
            }
            // geometry batched once, reused by both fade passes
            var stems = [[], [], [], []];     // per band: [xLeft, width] rects, wide under narrow
            var cxs = [], cys = [];           // loop centres
            var bL = (SC_BX - SC_R0 - 3 * SC_SW) * s;   // left stem outer (K) edge
            var qL = (SC_QX + SC_R0 - SC_SW) * s;       // right stem inner (C) edge
            for (gx = -halfX; gx <= halfX; gx++) {
                var cx0 = gx * TW;
                for (i = 0; i < 4; i++) {
                    stems[i].push([cx0 + bL + i * SW, (4 - i) * SW]);   // left stem: K,R,O,C left->right
                    stems[i].push([cx0 + qL, (4 - i) * SW]);           // right stem: C,O,R,K (mirror)
                }
                for (gy = -halfY; gy <= halfY; gy++) {
                    var cy0 = gy * TH + travel;
                    cxs.push(cx0 + SC_BX * s); cys.push(cy0 + SC_BY * s);
                    cxs.push(cx0 + SC_QX * s); cys.push(cy0 + SC_QY * s);
                }
            }
            var RADII = [R0 + 3 * SW, R0 + 2 * SW, R0 + SW, R0];       // disc per band, big->small
            var ROUT = RADII[0];
            function paint(fills) {
                for (i = 0; i < 4; i++) {                              // stems: continuous full-height fills
                    ctx.fillStyle = fills[i];
                    for (k = 0; k < stems[i].length; k++) ctx.fillRect(stems[i][k][0], -H2, stems[i][k][1], 2 * H2);
                }
                ctx.save(); ctx.beginPath();                           // loops: clip out each ribbon's entry half
                ctx.rect(-H2, -H2, 2 * H2, 2 * H2);                    // (above centre for "b", below for "q") so the
                for (k = 0; k < cxs.length; k += 2) ctx.rect(cxs[k] - SC_BX * s + bL, cys[k] - ROUT, 4 * SW, ROUT);
                for (k = 1; k < cxs.length; k += 2) ctx.rect(cxs[k] - SC_QX * s + qL, cys[k], 4 * SW, ROUT);
                ctx.clip('evenodd');                                   // stem stays on top there - every pixel painted once
                for (i = 0; i < 4; i++) {                              // the rings: true annuli (evenodd), eye = solid disc
                    ctx.fillStyle = fills[i]; ctx.beginPath();
                    var r = RADII[i], ri = (i < 3) ? RADII[i + 1] : 0;
                    for (k = 0; k < cxs.length; k++) {
                        ctx.moveTo(cxs[k] + r, cys[k]); ctx.arc(cxs[k], cys[k], r, 0, 6.2831853072);
                        if (ri) { ctx.moveTo(cxs[k] + ri, cys[k]); ctx.arc(cxs[k], cys[k], ri, 0, 6.2831853072); }
                    }
                    ctx.fill('evenodd');
                }
                ctx.restore();
            }
            ctx.save(); ctx.translate(w / 2, h / 2); ctx.rotate(ang);
            if (fade === 'off') { paint(palFor(0)); }
            else if (fade === 'blink') { paint(palFor(st)); }
            else {
                paint(palFor(st));
                if (fr >= 0.6) { ctx.globalAlpha = (fr - 0.6) / 0.4; paint(palFor((st + 1) % 3)); ctx.globalAlpha = 1; }
            }
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
