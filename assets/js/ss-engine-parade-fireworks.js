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
        var L = clamp(lerp(c[2], 0.80, amt*0.80), 0, 0.9);
        var S = clamp(lerp(c[1], c[1]*0.78, amt), 0, 1);
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

        var GRAV = 58;
        var rockets = [], parts = [], spawnAcc = 0;
        var MAX_PARTS = 2200;

        function launch(){
            var w = cv.width, h = cv.height;
            rockets.push({ x:w*(0.03+0.94*Math.random()), y:h,
                vx:(Math.random()-0.5)*14, vy:-(h*(0.5+0.42*Math.random())),
                targetY:h*(0.05+0.6*Math.random()), palStart:Math.random() });
        }
        function burst(r){
            var n = burstSize + Math.round((Math.random()-0.5)*burstSize*0.3);
            var vmax = cv.height*spreadAmt;
            for (var i = 0; i < n && parts.length < MAX_PARTS; i++){
                var ang = Math.random()*Math.PI*2;
                var sp = Math.cos(Math.random()*Math.PI/2)*vmax;   // cos-weighted shell + core depth
                var col = pastel(sampleArr(PAL, r.palStart + i/n), softAmt);
                parts.push({ x:r.x, y:r.y, px:r.x, py:r.y, vx:Math.cos(ang)*sp, vy:Math.sin(ang)*sp,
                    col:col, size:(5+5*Math.random()), alpha:1, fade:0.006+0.010*Math.random(), flick:true });
            }
        }
        function fadeFrame(amt){
            ctx.globalCompositeOperation = 'destination-out';
            ctx.fillStyle = 'rgba(0,0,0,'+amt.toFixed(3)+')';
            ctx.fillRect(0, 0, cv.width, cv.height);
            ctx.globalCompositeOperation = 'source-over';
        }
        function drawParticle(p){
            var a = Math.max(0,p.alpha), w = p.flick ? (0.8+0.25*Math.random()) : 1;
            ctx.strokeStyle = hsla([p.col[0],Math.min(1,p.col[1]+0.06),Math.max(0.42,p.col[2]-0.05)], a*0.9);
            ctx.lineWidth = Math.max(0.6, p.size*0.55*streamerW); ctx.lineCap='round'; ctx.lineJoin='round';
            ctx.beginPath(); ctx.moveTo(p.px,p.py); ctx.lineTo(p.x,p.y); ctx.stroke();
            var r = Math.max(0.4, p.size*0.42*streamerW*w);
            var g = ctx.createRadialGradient(p.x,p.y,0,p.x,p.y,r);
            g.addColorStop(0, hsla([p.col[0],Math.min(1,p.col[1]+0.12),Math.min(0.95,p.col[2]+0.10)], a*0.5));
            g.addColorStop(1, hsla(p.col, 0));
            ctx.fillStyle = g; ctx.beginPath(); ctx.arc(p.x,p.y,r,0,7); ctx.fill();
        }

        var lastT = performance.now();
        function step(now){
            var dt = (now-lastT)/1000; lastT = now; dt = Math.min(dt, 0.05);
            var rdt = dt*launchSpeed*2.0;                         // rocket rise
            var k = Math.max(0.0001, explodeSpeed*1.7);           // burst sim speed
            spawnAcc += dt*launchRate;
            while (spawnAcc >= 1){ spawnAcc -= 1; launch(); }
            fadeFrame(Math.max(0.012, Math.min(0.13, 0.035*k)));  // fade scales with speed → constant streamer length
            for (var i = rockets.length-1; i >= 0; i--){
                var r = rockets[i];
                r.vy += GRAV*rdt; r.x += r.vx*rdt; r.y += r.vy*rdt;
                ctx.fillStyle = 'rgba(255,170,70,0.11)';          // dim launch spark
                ctx.beginPath(); ctx.arc(r.x, r.y, 1.6, 0, 7); ctx.fill();
                if (r.vy >= 0 || r.y <= r.targetY){ burst(r); rockets.splice(i, 1); }
            }
            var resK = Math.pow(0.90,k), shrinkK = Math.pow(0.94,k), gravK = cv.height*0.0011*k;
            for (var j = parts.length-1; j >= 0; j--){
                var p = parts[j];
                p.px = p.x; p.py = p.y;
                p.vx *= resK; p.vy *= resK; p.vy += gravK;
                p.x += p.vx*k; p.y += p.vy*k;
                p.size *= shrinkK; p.alpha -= p.fade*k;
                if (p.alpha <= 0.05 || p.size < 0.5){ parts.splice(j, 1); continue; }
                drawParticle(p);
            }
        }

        // prime the scene so the page opens MID-display — bursts already in the air
        // at mixed ages, so you don't wait for the first rocket to rise and pop.
        function primeScene(){
            var w = cv.width, h = cv.height;
            for (var b = 0; b < 7; b++) burst({ x:w*(0.06+0.88*Math.random()), y:h*(0.08+0.5*Math.random()), palStart:Math.random() });
            var resK = Math.pow(0.90,1), shrinkK = Math.pow(0.94,1), gravK = cv.height*0.0011;
            for (var i = 0; i < parts.length; i++){
                var p = parts[i], age = Math.floor(Math.random()*Math.random()*45);   // mostly young, a few fading
                for (var s = 0; s < age; s++){
                    p.px = p.x; p.py = p.y;
                    p.vx *= resK; p.vy *= resK; p.vy += gravK;
                    p.x += p.vx; p.y += p.vy;
                    p.size *= shrinkK; p.alpha -= p.fade;
                }
            }
            for (var r = 0; r < 2; r++) launch();   // a couple already climbing
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
