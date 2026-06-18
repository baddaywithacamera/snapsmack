/**
 * SNAPSMACK - AURORA Background Curtains (Layer 1)
 *
 * Canvas-rendered northern-lights curtains, ported from the canonical prototype
 * (_spec/aurora-prototype.html). Each curtain is a slowly-waving baseline with
 * ragged vertical light-rays and a drifting brightness band; colour advances
 * through the active palette over `cycle` seconds, HSL-interpolated so it reads
 * as living light, not a flat wheel. NO hue-rotate — palette colours stay true.
 *
 * Self-contained. Reads all config from the .au-aurora-bg element's dataset
 * (set by skin-profile.php): data-au-palette (JSON hex array), data-au-cycle
 * (seconds), data-au-opacity (0–1), data-au-sky (hex). No fetch / storage.
 * Respects prefers-reduced-motion (one static frame) and pauses on document.hidden.
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
    function lerpHue(a,b,t){ var d = ((b-a)%360+540)%360-180; return (a+d*t+360)%360; }
    function sampleArr(hs,t){
        var n = hs.length, x = (((t%1)+1)%1)*n, i = Math.floor(x), f = x-i;
        var a = hs[i%n], b = hs[(i+1)%n];
        return [lerpHue(a[0],b[0],f), lerp(a[1],b[1],f), lerp(a[2],b[2],f)];
    }
    function hsla(c,a){ return 'hsla('+c[0].toFixed(1)+' '+(c[1]*100).toFixed(1)+'% '+(c[2]*100).toFixed(1)+'% / '+a.toFixed(3)+')'; }

    // ── cheap 2D value-noise (ragged tops + drifting banding) ───────────────
    function _fr(n){ return n-Math.floor(n); }
    function _h2(x,y){ return _fr(Math.sin(x*127.1+y*311.7)*43758.5453); }
    function _sm(t){ return t*t*(3-2*t); }
    function noise2(x,y){
        var xi=Math.floor(x), yi=Math.floor(y), xf=x-xi, yf=y-yi, u=_sm(xf), v=_sm(yf);
        return lerp(lerp(_h2(xi,yi),_h2(xi+1,yi),u), lerp(_h2(xi,yi+1),_h2(xi+1,yi+1),u), v);
    }

    var CURTAINS = [ // my, amp, hh, f1, f2, dr, ph, po, vA, vS, vP, sk
        {my:0.26,amp:0.10,hh:0.48,f1:1.1,f2:2.7,dr:0.05,ph:0.0,po:0.00,vA:0.05,vS:0.021,vP:0.0,sk:1},
        {my:0.36,amp:0.13,hh:0.58,f1:0.8,f2:3.3,dr:0.04,ph:2.1,po:0.18,vA:0.07,vS:0.017,vP:1.7,sk:0},
        {my:0.46,amp:0.09,hh:0.42,f1:1.4,f2:2.1,dr:0.06,ph:4.2,po:0.40,vA:0.10,vS:0.025,vP:3.4,sk:0},
        {my:0.60,amp:0.16,hh:0.66,f1:0.6,f2:1.7,dr:0.03,ph:1.0,po:0.62,vA:0.30,vS:0.033,vP:0.6,sk:1}
    ];

    function init() {
        var host = document.querySelector('.au-aurora-bg');
        if (!host) return;

        // Parse palette → HSL stop list.
        var hexes = [];
        try {
            var raw = JSON.parse(host.getAttribute('data-au-palette') || '[]');
            if (Array.isArray(raw)) hexes = raw;
        } catch (e) {}
        if (hexes.length < 2) {
            hexes = ['#56e86a','#2fe6a0','#39b6f0','#9bf25a','#2f7fe0','#f2d24a','#ff5566','#46c0c0'];
        }
        var PAL = hexes.map(hex2hsl);

        var cycle     = parseFloat(host.getAttribute('data-au-cycle'))   || 240;
        var bgOpacity = parseFloat(host.getAttribute('data-au-opacity'));
        if (isNaN(bgOpacity)) bgOpacity = 0.5;

        // Canvas — created/owned here, lives inside the fixed .au-aurora-bg layer.
        var cv = host.querySelector('canvas.au-canvas');
        if (!cv) { cv = document.createElement('canvas'); cv.className = 'au-canvas'; host.appendChild(cv); }
        var ctx = cv.getContext('2d');
        var SC = 0.5; // half-res then CSS-upscale + blur = soft
        function sizeCanvas(){ cv.width = Math.round(window.innerWidth*SC); cv.height = Math.round(window.innerHeight*SC); ctx.imageSmoothingEnabled = true; }
        window.addEventListener('resize', sizeCanvas);
        sizeCanvas();

        function drawCanvas(now) {
            var w = cv.width, h = cv.height, T = now/1000;
            ctx.globalCompositeOperation = 'source-over'; ctx.clearRect(0,0,w,h);
            ctx.globalCompositeOperation = 'lighter';
            var palT = T/cycle, step = Math.max(3, Math.round(w/240));
            for (var ci = 0; ci < CURTAINS.length; ci++) {
                var c = CURTAINS[ci];
                var colLow = sampleArr(PAL, palT + c.po);
                var colHigh = [colLow[0], colLow[1]*0.6, Math.min(0.9, colLow[2]+0.22)];
                var myc = c.my + c.vA*Math.sin(T*c.vS + c.vP);
                myc = Math.max(0.18, Math.min(0.95, myc));
                var midY = h*myc, amp = h*c.amp, H = h*c.hh;
                for (var x = 0; x <= w; x += step) {
                    var nx = x/w*60;
                    var sharp = c.sk ? Math.max(0, Math.sin(T*0.018 + c.ph))
                        * Math.sin(nx*c.f2*0.13 + T*c.dr + c.ph*0.7)*amp*0.9 : 0;
                    var base = midY
                        + Math.sin(nx*c.f1*0.06 + T*c.dr + c.ph)*amp
                        + Math.sin(nx*c.f2*0.06 - T*c.dr*0.7 + c.ph*1.7)*amp*0.5
                        + sharp;
                    var rh = H*(0.58 + 0.42*noise2(nx*0.45, T*0.05 + c.ph));
                    var band = noise2(nx*0.11 - T*0.03, c.ph*3.0);
                    band = _sm(_sm(band)); band = Math.pow(band, 1.4);
                    var fine = noise2(nx*0.8 - T*0.05, c.ph*7.0);
                    var bright = band*(0.5 + 0.5*fine);
                    var a = (0.04 + 0.96*bright)*bgOpacity;
                    var g = ctx.createLinearGradient(0, base-rh, 0, base);
                    g.addColorStop(0.00, hsla(colHigh, 0));
                    g.addColorStop(0.45, hsla(colHigh, a*0.45));
                    g.addColorStop(0.85, hsla(colLow,  a));
                    g.addColorStop(1.00, hsla(colLow,  a*0.25));
                    ctx.fillStyle = g;
                    ctx.fillRect(x, base-rh, step+1, rh);
                }
            }
        }

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) { drawCanvas(0); return; }

        var rafId = null, lastDraw = 0;
        function frame(now) {
            if (now - lastDraw >= 32) { lastDraw = now; drawCanvas(now); }
            rafId = window.requestAnimationFrame(frame);
        }
        function start(){ if (rafId === null) rafId = window.requestAnimationFrame(frame); }
        function stop(){ if (rafId !== null) { window.cancelAnimationFrame(rafId); rafId = null; } }
        document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
        if (!document.hidden) start();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
