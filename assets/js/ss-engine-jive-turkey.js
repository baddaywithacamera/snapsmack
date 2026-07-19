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
            var fx = w*0.5 + Math.sin(T*fs)*w*0.40;      // wide enough that the daisy swings out past the centre readability panel into the side margins, fully visible
            var fy = h*0.5 + Math.sin(T*fs*1.3 + 1.0)*h*0.30;
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

        // SURPRISE decides ONCE per load and holds for the whole visit.
        var surprisePick = null;
        if (mode0 === 'surprise') {
            // Avoid repeating recent picks so a reload brings something fresh: remember
            // the last few mode|colourway combos in sessionStorage and steer around them.
            var _recent = [];
            try { _recent = JSON.parse(sessionStorage.getItem('jtSurpriseRecent') || '[]') || []; } catch (e) { _recent = []; }
            var _m, _c, _key, _tries = 0;
            do {
                _m = pickRandom(pool);
                _c = (randomColour && cwNames.length) ? pickRandom(cwNames) : activeName;
                _key = _m + '|' + _c;
                _tries++;
            } while (_recent.indexOf(_key) !== -1 && _tries < 16);
            surprisePick = { mode: _m, cw: _c };
            _recent.push(_key);
            while (_recent.length > 6) _recent.shift();
            try { sessionStorage.setItem('jtSurpriseRecent', JSON.stringify(_recent)); } catch (e) {}
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
