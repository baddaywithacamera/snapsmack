/**
 * SNAPSMACK - AURORA Tile Border Wave (Layer 2)
 *
 * Conic-gradient ring border, ported from _spec/aurora-prototype.html. Four
 * styles — circle each tile / circle + sweep across / wave across grid /
 * scatter pulse — on a slower clock than the sky, with an optional slow-fast-slow
 * "breath" rhythm. Colour is the TRUE palette (HSL-interpolated for the solid
 * models, real hex stops for the conic ring) — no hue-rotate.
 *
 * Self-contained. Reads config from the .au-aurora-bg dataset (data-au-palette,
 * data-au-border-style, data-au-border-dir, data-au-border-rhythm) and drives
 * each tile's `.au-ring` overlay. Respects prefers-reduced-motion (one static
 * frame) and pauses on document.hidden. No fetch / storage.
 *
 * SCALING: the per-frame paint runs ONLY on tiles currently in/near the viewport
 * (tracked via IntersectionObserver). A 1400-image grid therefore animates ~30
 * rings per frame instead of 1400 — the wave never pins the main thread, so the
 * grid stays smooth and native loading="lazy" is never starved. Tiles paint once
 * on entry so they are never blank. Falls back to all-tiles if IO is unavailable.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    // ── HSL helpers (self-contained; mirror aurora-bg.js) ───────────────────
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
    function hslStr(c){ return 'hsl('+c[0].toFixed(1)+' '+(c[1]*100).toFixed(1)+'% '+(c[2]*100).toFixed(1)+'%)'; }

    function init() {
        var cfg = document.querySelector('.au-aurora-bg');
        if (!cfg) return;

        var hexes = [];
        try { var raw = JSON.parse(cfg.getAttribute('data-au-palette') || '[]'); if (Array.isArray(raw)) hexes = raw; } catch (e) {}
        if (hexes.length < 2) hexes = ['#56e86a','#2fe6a0','#39b6f0','#9bf25a','#2f7fe0','#f2d24a','#ff5566','#46c0c0'];
        var PAL = hexes.map(hex2hsl);

        var bmodel  = cfg.getAttribute('data-au-border-style')  || 'circle';
        var bdir    = cfg.getAttribute('data-au-border-dir')    || 'dtlbr';
        var brhythm = cfg.getAttribute('data-au-border-rhythm') || 'breath';
        var bCycle  = 160; // border clock — slower counterpoint to the sky

        // conic ring uses the real hex stops so the true palette wraps the edge
        var ringStops = hexes.concat([hexes[0]]).map(function (c, i, a) {
            return c + ' ' + Math.round(i/(a.length-1)*360) + 'deg';
        }).join(',');

        function sampleHsl(t){ return hslStr(sampleArr(PAL, t)); }
        function solid(c){ return 'linear-gradient(' + c + ',' + c + ')'; }

        function posIndex(r, c) {
            switch (bdir) {
                case 'ltr':   return c;
                case 'rtl':   return 2 - c;
                case 'ttb':   return r;
                case 'btt':   return 3 - r;
                case 'dbrtl': return -(r + c); // diagonal ↖
                default:      return  (r + c); // dtlbr / diagonal ↘
            }
        }
        function borderBG(o, tw) {
            var idx = posIndex(o.row, o.col);
            if (bmodel === 'across') return solid(sampleHsl(tw/bCycle + idx*0.12)); // wavefront across grid
            if (bmodel === 'pulse')  return solid(sampleHsl(tw/bCycle + o.seed));   // scattered per-tile pulse
            if (bmodel === 'sweep')  return 'conic-gradient(from ' + (((tw/bCycle*360)+idx*40)%360) + 'deg, ' + ringStops + ')';
            return 'conic-gradient(from ' + ((tw/bCycle*360)%360) + 'deg, ' + ringStops + ')'; // circle each tile
        }

        // ── viewport scoping ────────────────────────────────────────────────
        // Only tiles on/near screen get repainted each frame. This is what lets
        // a 1400-tile grid animate without melting: ~30 rings/frame, not 1400.
        var supportsIO = typeof window.IntersectionObserver === 'function';
        var lastTw = 80; // current wave clock; tiles entering view paint at this value
        var io = supportsIO ? new IntersectionObserver(function (entries) {
            for (var i = 0; i < entries.length; i++) {
                var t = entries[i].target.__auTile;
                if (!t) continue;
                t.vis = entries[i].isIntersecting;
                if (t.vis) t.ring.style.background = borderBG(t, lastTw); // never blank on entry
            }
        }, { rootMargin: '200px 0px' }) : null;

        // ── tile lookup (each tile carries a .au-ring overlay) ──────────────
        var tiles = [], seedN = 0;
        function scanTiles() {
            if (io) io.disconnect();
            var nodes = document.querySelectorAll('.au-grid .au-tile');
            tiles = []; seedN = 0;
            for (var i = 0; i < nodes.length; i++) {
                var el = nodes[i];
                if (el.classList.contains('au-tile--phantom')) continue;
                var ring = el.querySelector('.au-ring');
                if (!ring) continue;
                var row = parseInt(el.getAttribute('data-row'), 10) || 0;
                var col = parseInt(el.getAttribute('data-col'), 10) || 0;
                var t = { el: el, ring: ring, row: row, col: col, seed: (seedN*0.137)%1, vis: !io };
                tiles.push(t);
                el.__auTile = t;
                seedN++;
            }
            if (io) for (var j = 0; j < tiles.length; j++) io.observe(tiles[j].el);
        }
        scanTiles();
        if (!tiles.length) return;
        // Re-scan when grid pages are appended via AJAX load-more.
        document.addEventListener('aurora:grid-updated', scanTiles);

        function paint(T) {
            var tw = (brhythm === 'breath') ? (T + 6*Math.sin(T*0.05)) : T;
            lastTw = tw;
            for (var k = 0; k < tiles.length; k++) {
                if (tiles[k].vis) tiles[k].ring.style.background = borderBG(tiles[k], tw);
            }
            // Expose current wave colour as a CSS var so other elements (e.g. nav
            // border lines) can track the aurora without extra JS.
            document.documentElement.style.setProperty('--au-wave-color', sampleHsl(tw / bCycle));
        }

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) {
            // one static, coloured frame across every tile (no animation, so no
            // viewport scoping needed) then stop observing.
            for (var s = 0; s < tiles.length; s++) tiles[s].ring.style.background = borderBG(tiles[s], 80);
            document.documentElement.style.setProperty('--au-wave-color', sampleHsl(80 / bCycle));
            if (io) io.disconnect();
            return;
        }

        var rafId = null, last = 0;
        function frame(now) {
            if (now - last >= 32) { last = now; paint(now/1000); }
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
