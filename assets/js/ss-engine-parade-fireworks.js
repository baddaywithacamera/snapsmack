/**
 * SNAPSMACK - PARADE Layer 1: slow-motion flag FIREWORKS (canvas)
 *
 * VERBATIM port of _continuity/parade-fireworks-prototype.html (Sean's canonical
 * prototype — "EXACTLY like the prototype"). Identical launch / burst / particle
 * physics, identical high-key pastel softening, identical prime-on-load so the
 * page opens MID-display, identical destination-out trail fade. The ONLY changes
 * from the prototype: config is read from the .pa-parade-bg dataset (data-pa-*)
 * instead of the control dock, and the palette comes from data-pa-palette.
 *
 * Reads from .pa-parade-bg:
 *   data-pa-palette   (JSON hex array)     data-pa-rate      (launches / sec)
 *   data-pa-launch    (rocket-rise speed)  data-pa-explode   (burst sim speed)
 *   data-pa-intensity (particles / burst)  data-pa-spread    (burst radius)
 *   data-pa-streamer  (streamer width x)   data-pa-soft      (0..1 pastel amount)
 * Canvas softness blur (0.6px) is set in style.css on canvas.pa-canvas.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    // ── colour helpers: interpolate in HSL (verbatim from prototype) ─────────
    function hex2hsl(h) {
        h = String(h).replace('#', '');
        if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
        var r = parseInt(h.slice(0,2),16)/255, g = parseInt(h.slice(2,4),16)/255, b = parseInt(h.slice(4,6),16)/255;
        var mx = Math.max(r,g,b), mn = Math.min(r,g,b), hu = 0, s = 0, l = (mx+mn)/2;
        if (mx !== mn) { var d = mx-mn; s = l > .5 ? d/(2-mx-mn) : d/(mx+mn);
            hu = mx===r ? (g-b)/d+(g<b?6:0) : mx===g ? (b-r)/d+2 : (r-g)/d+4; hu /= 6; }
        return [hu*360, s, l];
    }
    var lerp  = function (a,b,t) { return a+(b-a)*t; };
    var clamp = function (v,a,b) { return Math.max(a, Math.min(b,v)); };
    function lerpHue(a,b,t){ var d = ((b-a)%360+540)%360-180; return (a+d*t+360)%360; }
    function hsla(c,a){ return 'hsla('+c[0].toFixed(1)+' '+(c[1]*100).toFixed(1)+'% '+(c[2]*100).toFixed(1)+'% / '+a.toFixed(3)+')'; }
    function sampleArr(pal,t){
        var hs = pal.map(hex2hsl), n = hs.length;
        var x = (((t%1)+1)%1)*n, i = Math.floor(x), f = x-i;
        var a = hs[i%n], b = hs[(i+1)%n];
        return [lerpHue(a[0],b[0],f), lerp(a[1],b[1],f), lerp(a[2],b[2],f)];
    }
    // HIGH-KEY softening: lift lightness hard, ease saturation — airy, warm, happy
    function pastel(c, amt){
        var L = clamp(lerp(c[2], 0.72, amt*0.68), 0, 0.86);
        var S = clamp(lerp(c[1], c[1]*0.88, amt), 0, 1);
        return [c[0], S, L];
    }

    function init() {
        var host = document.querySelector('.pa-parade-bg');
        if (!host) return;

        var PAL = ['#e40303','#ff8c00','#ffed00','#008026','#004dff','#750787'];
        try { var raw = JSON.parse(host.getAttribute('data-pa-palette') || '[]'); if (Array.isArray(raw) && raw.length >= 2) PAL = raw; } catch (e) {}

        function num(attr, def){ var v = parseFloat(host.getAttribute(attr)); return isNaN(v) ? def : v; }
        var launchRate  = num('data-pa-rate',      3);      // launches / sec
        var launchSpeed = num('data-pa-launch',    0.60);   // rocket-rise speed x
        var explodeSpeed= num('data-pa-explode',   0.18);   // burst sim speed x
        var burstSize   = num('data-pa-intensity', 74);     // particles / burst
        var spreadAmt   = num('data-pa-spread',    0.045);  // burst radius
        var streamerW   = num('data-pa-streamer',  1.0);    // streamer width x
        var softAmt     = num('data-pa-soft',      0.84);   // pastel amount

        var cv = host.querySelector('canvas.pa-canvas');
        if (!cv) { cv = document.createElement('canvas'); cv.className = 'pa-canvas'; host.appendChild(cv); }
        var ctx = cv.getContext('2d');
        var SC = 0.5;
        function sizeCanvas(){ cv.width = Math.max(1, Math.round(window.innerWidth*SC));
            cv.height = Math.max(1, Math.round(window.innerHeight*SC)); ctx.imageSmoothingEnabled = true; }
        window.addEventListener('resize', sizeCanvas); sizeCanvas();

        var GRAV = 78;
        var rockets = [], parts = [], spawnAcc = 0;
        var MAX_PARTS = 2200;

        function launch(){
            var w = cv.width, h = cv.height;
            var speedScale = 0.72+launchSpeed*1.7;
            rockets.push({
                x:w*(0.06+0.88*Math.random()), y:h+8,
                vx:(Math.random()-0.5)*22,
                vy:-h*(0.62+0.24*Math.random())*speedScale,
                targetY:h*(0.10+0.48*Math.random()),
                palStart:Math.random()
            });
        }
        function burst(r){
            var n = burstSize + Math.round((Math.random()-0.5)*burstSize*0.3);
            var vmax = cv.height*(0.20+spreadAmt*3.2);
            var style = Math.floor(Math.random()*4); // peony, ring, chrysanthemum, willow
            var phase = Math.random()*Math.PI*2;
            for (var i = 0; i < n && parts.length < MAX_PARTS; i++){
                var ang = style === 1
                    ? phase + (i/n)*Math.PI*2 + (Math.random()-0.5)*0.025
                    : Math.random()*Math.PI*2;
                var depth = Math.random();
                var sp = style === 1
                    ? vmax*(0.92+0.08*Math.random())
                    : (style === 2
                        ? vmax*(0.55+0.45*Math.pow(depth,0.22))
                        : vmax*(0.28+0.72*Math.sqrt(depth)));
                if (style === 3) sp *= 0.58+0.16*Math.sin(ang)*Math.sin(ang);
                var col = pastel(sampleArr(PAL, r.palStart + i/n), softAmt);
                var life = (style === 3 ? 2.8 : 1.75)+Math.random()*0.85;
                parts.push({
                    x:r.x, y:r.y,
                    vx:Math.cos(ang)*sp, vy:Math.sin(ang)*sp,
                    col:col, size:1.0+1.4*Math.random(), alpha:1,
                    life:life, maxLife:life,
                    drag:style === 3 ? 1.25 : 0.92,
                    gravity:style === 3 ? 1.18 : 1,
                    sparkle:Math.random()<0.18
                });
            }
        }
        function drawParticle(p){
            var a = Math.max(0,p.alpha);
            var speed = Math.sqrt(p.vx*p.vx+p.vy*p.vy);
            var tailSeconds = 0.025+Math.min(0.035,speed/9000);
            var tx = p.x-p.vx*tailSeconds, ty = p.y-p.vy*tailSeconds;
            ctx.strokeStyle = hsla([p.col[0],Math.min(1,p.col[1]+0.08),Math.max(0.38,p.col[2]-0.09)], a*0.98);
            ctx.lineWidth = Math.max(0.65,p.size*streamerW); ctx.lineCap='round'; ctx.lineJoin='round';
            ctx.beginPath(); ctx.moveTo(tx,ty); ctx.lineTo(p.x,p.y); ctx.stroke();
            var r = Math.max(0.65,p.size*(0.7+streamerW*0.32));
            var g = ctx.createRadialGradient(p.x,p.y,0,p.x,p.y,r);
            g.addColorStop(0, 'rgba(255,255,255,'+(a*0.78).toFixed(3)+')');
            g.addColorStop(0.22, hsla([p.col[0],Math.min(1,p.col[1]+0.12),Math.min(0.93,p.col[2]+0.08)], a*0.72));
            g.addColorStop(1, hsla(p.col, 0));
            ctx.fillStyle = g; ctx.beginPath(); ctx.arc(p.x,p.y,r,0,7); ctx.fill();
            if (p.sparkle && p.life < p.maxLife*0.62 && Math.random() < 0.08) {
                ctx.fillStyle = 'rgba(255,255,255,'+(a*0.88).toFixed(3)+')';
                ctx.fillRect(p.x-0.7,p.y-0.7,1.4,1.4);
            }
        }

        var lastT = performance.now();
        function step(now){
            var dt = (now-lastT)/1000; lastT = now; dt = Math.min(dt, 0.05);
            var simDt = dt*(0.36+explodeSpeed*1.9);
            spawnAcc += dt*launchRate;
            while (spawnAcc >= 1){ spawnAcc -= 1; launch(); }
            ctx.clearRect(0,0,cv.width,cv.height);
            for (var i = rockets.length-1; i >= 0; i--){
                var r = rockets[i];
                r.vy += GRAV*dt; r.x += r.vx*dt; r.y += r.vy*dt;
                var tail = Math.max(5, Math.min(20, -r.vy*0.028));
                var rg = ctx.createLinearGradient(r.x,r.y,r.x-r.vx*0.06,r.y+tail);
                rg.addColorStop(0,'rgba(255,255,255,0.92)');
                rg.addColorStop(0.25,'rgba(255,184,76,0.72)');
                rg.addColorStop(1,'rgba(255,184,76,0)');
                ctx.strokeStyle=rg; ctx.lineWidth=1.5; ctx.beginPath();
                ctx.moveTo(r.x,r.y); ctx.lineTo(r.x-r.vx*0.06,r.y+tail); ctx.stroke();
                ctx.fillStyle = 'rgba(255,255,255,0.9)';
                ctx.beginPath(); ctx.arc(r.x, r.y, 1.35, 0, 7); ctx.fill();
                if (r.vy >= 0 || r.y <= r.targetY){ burst(r); rockets.splice(i, 1); }
            }
            for (var j = parts.length-1; j >= 0; j--){
                var p = parts[j];
                var damp = Math.exp(-p.drag*simDt);
                p.vx *= damp;
                p.vy = p.vy*damp+GRAV*p.gravity*simDt;
                p.x += p.vx*simDt;
                p.y += p.vy*simDt;
                p.life -= simDt;
                p.alpha = Math.min(1,p.life/Math.min(0.55,p.maxLife*0.28));
                if (p.life <= 0){ parts.splice(j,1); continue; }
                drawParticle(p);
            }
        }

        // prime the scene so the page opens MID-display — bursts already in the air
        // at mixed ages, so you don't wait for the first rocket to rise and pop.
        function primeScene(){
            var w = cv.width, h = cv.height;
            for (var b = 0; b < 4; b++) burst({ x:w*(0.08+0.84*Math.random()), y:h*(0.10+0.46*Math.random()), palStart:Math.random() });
            for (var i = 0; i < parts.length; i++){
                var p = parts[i], age = Math.random()*Math.random()*1.15;
                var damp = Math.exp(-p.drag*age);
                p.x += p.vx*(1-damp)/p.drag;
                p.y += p.vy*(1-damp)/p.drag+0.5*GRAV*p.gravity*age*age;
                p.vx *= damp;
                p.vy = p.vy*damp+GRAV*p.gravity*age;
                p.life -= age;
                p.alpha = Math.min(1,p.life/Math.min(0.55,p.maxLife*0.28));
            }
            launch();
        }

        // reduced-motion: a few static, coherent bursts; no animation
        function staticFrame(){
            ctx.clearRect(0,0,cv.width,cv.height);
            var W = cv.width, H = cv.height;
            var centres = [[0.24,0.30],[0.6,0.22],[0.78,0.42],[0.4,0.5]];
            for (var c = 0; c < centres.length; c++){
                var ox = W*centres[c][0], oy = H*centres[c][1], ps = Math.random();
                for (var kk = 0; kk < 90; kk++){
                    var ang = kk/90*Math.PI*2, rad = (0.04+0.10*((kk*7)%11)/11)*H;
                    var x = ox+Math.cos(ang)*rad, y = oy+Math.sin(ang)*rad;
                    var col = pastel(sampleArr(PAL, ps + kk/90), softAmt);
                    var g = ctx.createRadialGradient(x,y,0,x,y,5);
                    g.addColorStop(0, hsla(col,0.8)); g.addColorStop(1, hsla(col,0));
                    ctx.fillStyle = g; ctx.beginPath(); ctx.arc(x,y,5,0,7); ctx.fill();
                }
            }
        }

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) { staticFrame(); return; }

        var rafId = null, lastFrame = 0;
        function frame(now){
            if (now - lastFrame >= 30){ lastFrame = now; step(now); }
            rafId = window.requestAnimationFrame(frame);
        }
        function start(){ if (rafId === null){ lastT = performance.now(); rafId = window.requestAnimationFrame(frame); } }
        function stop(){ if (rafId !== null){ window.cancelAnimationFrame(rafId); rafId = null; } }
        document.addEventListener('visibilitychange', function(){ if (document.hidden) stop(); else start(); });

        primeScene();
        if (!document.hidden) start();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
