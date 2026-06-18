/**
 * SNAPSMACK - PARADE Fireworks (Layer 1)
 *
 * Canvas-rendered slow-motion fireworks for the high-key PARADE skin, ported from
 * the validated prototype (_continuity/parade-fireworks-prototype.html). Rockets
 * launch on real time; particle motion runs on a slowed clock so a busy sky can
 * drift to a near-freeze and ease back. Each burst samples ACROSS the active flag
 * palette so a single burst paints the whole flag. Colour is HSL-interpolated and
 * softened toward pastel for a warm, airy, high-key feel on white. NO hue-rotate —
 * flag colours stay true.
 *
 * On a white field we do NOT composite additively (that washes out); particles draw
 * source-over with bright airy cores, and trails fade by erasing (destination-out)
 * so the themed background shows through.
 *
 * Self-contained. Reads all config from the .pa-parade-bg element's dataset (set by
 * skin-profile.php): data-pa-palette (JSON hex array), data-pa-motion (0-1),
 * data-pa-rhythm (breath|constant), data-pa-rate (launches/sec), data-pa-burst
 * (count), data-pa-soft (0-1 pastel). No fetch / storage. Respects
 * prefers-reduced-motion (one static frame) and pauses on document.hidden.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    // ── colour helpers: interpolate in HSL, not muddy sRGB ──────────────────
    function hex2hsl(h) {
        h = String(h).replace('#', '');
        if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
        var r = parseInt(h.slice(0,2),16)/255, g = parseInt(h.slice(2,4),16)/255, b = parseInt(h.slice(4,6),16)/255;
        var mx = Math.max(r,g,b), mn = Math.min(r,g,b), hu = 0, s = 0, l = (mx+mn)/2;
        if (mx !== mn) { var d = mx-mn; s = l > .5 ? d/(2-mx-mn) : d/(mx+mn);
            hu = mx===r ? (g-b)/d+(g<b?6:0) : mx===g ? (b-r)/d+2 : (r-g)/d+4; hu /= 6; }
        return [hu*360, s, l];
    }
    function lerp(a,b,t){ return a+(b-a)*t; }
    function clamp(v,a,b){ return Math.max(a, Math.min(b, v)); }
    function lerpHue(a,b,t){ var d = ((b-a)%360+540)%360-180; return (a+d*t+360)%360; }
    function sampleArr(hs,t){
        var n = hs.length, x = (((t%1)+1)%1)*n, i = Math.floor(x), f = x-i;
        var a = hs[i%n], b = hs[(i+1)%n];
        return [lerpHue(a[0],b[0],f), lerp(a[1],b[1],f), lerp(a[2],b[2],f)];
    }
    function hsla(c,a){ return 'hsla('+c[0].toFixed(1)+' '+(c[1]*100).toFixed(1)+'% '+(c[2]*100).toFixed(1)+'% / '+a.toFixed(3)+')'; }
    // high-key softening: lift lightness hard, ease saturation — airy, warm, happy
    function pastel(c, amt){
        var L = clamp(lerp(c[2], 0.80, amt*0.80), 0, 0.9);
        var S = clamp(lerp(c[1], c[1]*0.78, amt), 0, 1);
        return [c[0], S, L];
    }

    function init() {
        var host = document.querySelector('.pa-parade-bg');
        if (!host) return;

        var PAL = [];
        try {
            var raw = JSON.parse(host.getAttribute('data-pa-palette') || '[]');
            if (Array.isArray(raw)) PAL = raw.map(hex2hsl);
        } catch (e) {}
        if (PAL.length < 2) PAL = ['#e40303','#ff8c00','#ffed00','#008026','#004dff','#750787'].map(hex2hsl);

        var motion = parseFloat(host.getAttribute('data-pa-motion')); if (isNaN(motion)) motion = 0.18;
        var rhythm = host.getAttribute('data-pa-rhythm') || 'breath';
        var rate   = parseFloat(host.getAttribute('data-pa-rate'));   if (isNaN(rate))   rate = 3;
        var burst  = parseFloat(host.getAttribute('data-pa-burst'));  if (isNaN(burst))  burst = 74;
        var soft   = parseFloat(host.getAttribute('data-pa-soft'));   if (isNaN(soft))   soft = 0.84;

        var cv = host.querySelector('canvas.pa-canvas');
        if (!cv) { cv = document.createElement('canvas'); cv.className = 'pa-canvas'; host.appendChild(cv); }
        var ctx = cv.getContext('2d');
        var SC = 0.5; // half-res then CSS-upscale + blur = soft
        function sizeCanvas(){ cv.width = Math.max(1, Math.round(window.innerWidth*SC)); cv.height = Math.max(1, Math.round(window.innerHeight*SC)); ctx.imageSmoothingEnabled = true; }
        window.addEventListener('resize', sizeCanvas);
        sizeCanvas();

        var GRAV = 58, DRAG = 0.985, MAX_PARTS = 2200;
        var rockets = [], parts = [], spawnAcc = 0;

        function launch(){
            var w = cv.width, h = cv.height;
            rockets.push({ x: w*(0.12+0.76*Math.random()), y: h,
                vx: (Math.random()-0.5)*18, vy: -(h*(0.42+0.30*Math.random()))/1.6,
                targetY: h*(0.10+0.34*Math.random()), palStart: Math.random() });
        }
        function doBurst(r){
            var n = burst + Math.round((Math.random()-0.5)*burst*0.3);
            var baseSpeed = cv.height*0.16;
            for (var i = 0; i < n && parts.length < MAX_PARTS; i++){
                var ang = Math.random()*Math.PI*2, sp = baseSpeed*(0.25+0.95*Math.random());
                var col = pastel(sampleArr(PAL, r.palStart + i/n), soft);
                parts.push({ x:r.x, y:r.y, vx:Math.cos(ang)*sp, vy:Math.sin(ang)*sp,
                    col:col, life:1, decay:0.10+0.10*Math.random(), size:(1.3+1.6*Math.random()) });
            }
        }
        function fadeFrame(amt){
            ctx.globalCompositeOperation = 'destination-out';
            ctx.fillStyle = 'rgba(0,0,0,'+amt.toFixed(3)+')';
            ctx.fillRect(0, 0, cv.width, cv.height);
            ctx.globalCompositeOperation = 'source-over';
        }
        function drawParticle(p){
            var r = Math.max(0.6, p.size*(0.6+0.7*p.life));
            var g = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, r*4);
            g.addColorStop(0,    hsla([p.col[0], Math.min(1,p.col[1]+0.08), Math.min(0.9,p.col[2]+0.07)], p.life));
            g.addColorStop(0.45, hsla(p.col, p.life*0.5));
            g.addColorStop(1,    hsla(p.col, 0));
            ctx.fillStyle = g;
            ctx.beginPath(); ctx.arc(p.x, p.y, r*4, 0, 7); ctx.fill();
        }

        function staticFrame(){
            ctx.clearRect(0, 0, cv.width, cv.height);
            var W = cv.width, H = cv.height;
            var centres = [[0.24,0.30],[0.6,0.22],[0.78,0.42],[0.4,0.5]];
            for (var ci = 0; ci < centres.length; ci++){
                var ox = W*centres[ci][0], oy = H*centres[ci][1], ps = Math.random();
                for (var k = 0; k < 90; k++){
                    var ang = k/90*Math.PI*2, rad = (0.04+0.10*((k*7)%11)/11)*H;
                    var x = ox+Math.cos(ang)*rad, y = oy+Math.sin(ang)*rad;
                    var col = pastel(sampleArr(PAL, ps + k/90), soft);
                    var g = ctx.createRadialGradient(x, y, 0, x, y, 5);
                    g.addColorStop(0, hsla(col, 0.8)); g.addColorStop(1, hsla(col, 0));
                    ctx.fillStyle = g; ctx.beginPath(); ctx.arc(x, y, 5, 0, 7); ctx.fill();
                }
            }
        }

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) { staticFrame(); return; }

        var rafId = null, lastT = 0, lastFrame = 0;
        function step(now){
            var dt = lastT ? (now-lastT)/1000 : 0; lastT = now; dt = Math.min(dt, 0.05);
            var breath = rhythm === 'breath' ? (0.06 + 0.94*Math.pow(0.5+0.5*Math.sin(now*0.00018), 2)) : 1;
            var sdt = dt*motion*breath;

            spawnAcc += dt*rate;
            while (spawnAcc >= 1){ spawnAcc -= 1; launch(); }

            fadeFrame(0.11);

            for (var i = rockets.length-1; i >= 0; i--){
                var rk = rockets[i];
                rk.vy += GRAV*sdt; rk.x += rk.vx*sdt; rk.y += rk.vy*sdt;
                ctx.fillStyle = 'rgba(255,170,70,0.45)';
                ctx.beginPath(); ctx.arc(rk.x, rk.y, 1.6, 0, 7); ctx.fill();
                if (rk.vy >= 0 || rk.y <= rk.targetY){ doBurst(rk); rockets.splice(i, 1); }
            }
            for (var j = parts.length-1; j >= 0; j--){
                var p = parts[j];
                p.vy += GRAV*0.62*sdt; p.vx *= DRAG; p.vy *= DRAG;
                p.x += p.vx*sdt; p.y += p.vy*sdt;
                p.life -= p.decay*dt;
                if (p.life <= 0){ parts.splice(j, 1); continue; }
                drawParticle(p);
            }
        }
        function frame(now){
            if (now - lastFrame >= 30){ lastFrame = now; step(now); }
            rafId = window.requestAnimationFrame(frame);
        }
        function start(){ if (rafId === null){ lastT = 0; rafId = window.requestAnimationFrame(frame); } }
        function stop(){ if (rafId !== null){ window.cancelAnimationFrame(rafId); rafId = null; } }
        document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
        if (!document.hidden) start();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
