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
            var rs = (P.ray/100)*0.7, fs = (P.flo/100)*0.5;
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
            var spd = 0.15 + (P.spd/100)*0.9;
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

        var DRAW = { scope: drawScope, bloom: drawBloom, flow: drawFlow, daisy: drawDaisy, reels: drawReels };
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
            surprisePick = {
                mode: pickRandom(pool),
                cw: (randomColour && cwNames.length) ? pickRandom(cwNames) : activeName
            };
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

    if (document.readyState ===