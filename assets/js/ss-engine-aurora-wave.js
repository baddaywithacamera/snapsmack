/**
 * SNAPSMACK - Grid-family Tile Border Wave (Layer 2, shared engine)
 *
 * Conic-gradient ring border, ported from _spec/aurora-prototype.html. Four
 * styles — circle each tile / circle + sweep across / wave across grid /
 * scatter pulse — on a slower clock than the sky, with an optional slow-fast-slow
 * "breath" rhythm. Colour is the TRUE palette (HSL-interpolated for the solid
 * models, real hex stops for the conic ring) — no hue-rotate.
 *
 * PREFIX-DERIVED (since 0.7.268): works for any Grid-family skin (au-, pa-, …)
 * with no fork. The prefix P is read from the page's `<P>-sticky-nav` (same idiom
 * as the shared grid engines), falling back to the known background elements.
 * Reads config from the element carrying `data-<P>-palette` (AURORA's
 * `.au-aurora-bg`, PARADE's `.pa-parade-bg`): data-<P>-border-style /
 * -border-dir / -border-rhythm, and drives each tile's `.<P>-ring` overlay.
 * Publishes `--<P>-wave-color` / `--<P>-wave-color-dark` CSS vars. Respects
 * prefers-reduced-motion (one static frame) and pauses on document.hidden.
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

    // ── Prefix derivation (shared-engine idiom) ─────────────────────────────
    function derivePrefix() {
        var nav = document.querySelector('nav[class*="-sticky-nav"]');
        if (nav) { var m = nav.className.match(/(?:^|\s)([a-z]+)-sticky-nav(?:\s|$)/); if (m) return m[1]; }
        var bg = document.querySelector('.au-aurora-bg, .pa-parade-bg');
        if (bg) { var m2 = bg.className.match(/(?:^|\s)([a-z]+)-(?:aurora|parade)-bg(?:\s|$)/); if (m2) return m2[1]; }
        return null;
    }

    function init() {
        var P = derivePrefix();
        if (!P) return;

        var cfg = document.querySelector('[data-' + P + '-palette]');
        if (!cfg) return;

        var hexes = [];
        try { var raw = JSON.parse(cfg.getAttribute('data-' + P + '-palette') || '[]'); if (Array.isArray(raw)) hexes = raw; } catch (e) {}
        if (hexes.length < 2) hexes = ['#56e86a','#2fe6a0','#39b6f0','#9bf25a','#2f7fe0','#f2d24a','#ff5566','#46c0c0'];
        var PAL = hexes.map(hex2hsl);

        var bmodel  = cfg.getAttribute('data-' + P + '-border-style')  || 'circle';
        var bdir    = cfg.getAttribute('data-' + P + '-border-dir')    || 'dtlbr';
        var brhythm = cfg.getAttribute('data-' + P + '-border-rhythm') || 'breath';
        var bCycle  = 160; // border clock — slower counterpoint to the sky

        // Optional dark-stop floor: lift very dark flag stops (e.g. the black /
        // brown on Progress + Non-Binary) toward grey so the border never reads
        // as a hard black band. data-<P>-border-minl is 0 (off — AURORA) .. 1.
        var minL = parseFloat(cfg.getAttribute('data-' + P + '-border-minl'));
        if (!(minL > 0)) minL = 0;
        if (minL > 0) PAL = PAL.map(function (c) { return [c[0], c[1], Math.max(c[2], minL)]; });

        // conic ring stops: raw hex when no floor (true palette, AURORA), or the
        // softened HSL when a floor is set (PARADE) so black never bands the ring.
        var ringHexes = (minL > 0) ? PAL.map(hslStr) : hexes;
        var ringStops = ringHexes.concat([ringHexes[0]]).map(function (c, i, a) {
            return c + ' ' + Math.round(i/(a.length-1)*360) + 'deg';
        }).join(',');

        function sampleHsl(t){ return hslStr(sampleArr(PAL, t)); }
        function solid(c){ return 'linear-gradient(' + c + ',' + c + ')'; }
        // Publish the live wave colour + a DARK tint (30% lightness) as CSS vars.
        // Nav divider lines read --<P>-wave-color-dark so they pulse in a dark
        // version of the palette without any extra DOM work.
        function setWaveVars(t){
            var wc = sampleArr(PAL, t), ds = document.documentElement.style;
            ds.setProperty('--' + P + '-wave-color', hslStr(wc));
            ds.setProperty('--' + P + '-wave-color-dark',
                'hsl(' + wc[0].toFixed(1) + ' ' + (wc[1]*100).toFixed(1) + '% ' + (wc[2]*30).toFixed(1) + '%)');
        }

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
                var t = entries[i].target.__ssWaveTile;
                if (!t) continue;
                t.vis = entries[i].isIntersecting;
                if (t.vis) t.ring.style.background = borderBG(t, lastTw); // never blank on entry
            }
        }, { rootMargin: '200px 0px' }) : null;

        // ── tile lookup (each tile carries a .<P>-ring overlay) ─────────────
        var tiles = [], seedN = 0;
        function scanTiles() {
            if (io) io.disconnect();
            var nodes = document.querySelectorAll('.' + P + '-grid .' + P + '-tile');
            tiles = []; seedN = 0;
            for (var i = 0; i < nodes.length; i++) {
                var el = nodes[i];
                if (el.classList.contains(P + '-tile--phantom')) continue;
                var ring = el.querySelector('.' + P + '-ring');
                if (!ring) continue;
                var row = parseInt(el.getAttribute('data-row'), 10) || 0;
                var col = parseInt(el.getAttribute('data-col'), 10) || 0;
                var t = { el: el, ring: ring, row: row, col: col, seed: (seedN*0.137)%1, vis: !io };
                tiles.push(t);
                el.__ssWaveTile = t;
                seedN++;
            }
            if (io) for (var j = 0; j < tiles.length; j++) io.observe(tiles[j].el);
        }
        scanTiles();
        if (!tiles.length) return;
        // Re-scan when grid pages are appended via AJAX load-more (either skin's event).
        document.addEventListener('aurora:grid-updated', scanTiles);
        document.addEventListener('parade:grid-updated', scanTiles);

        function paint(T) {
            var tw = (brhythm === 'breath') ? (T + 6*Math.sin(T*0.05)) : T;
            lastTw = tw;
            for (var k = 0; k < tiles.length; k++) {
                if (tiles[k].vis) tiles[k].ring.style.background = borderBG(tiles[k], tw);
            }
            // Expose current wave colour + a dark variant as CSS vars so other
            // elements (e.g. the nav divider lines) can track the palette.
            setWaveVars(tw / bCycle);
        }

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) {
            // one static, coloured frame across every tile (no animation, so no
            // viewport scoping needed) then stop observing.
            for (var s = 0; s < tiles.length; s++) tiles[s].ring.style.background = borderBG(tiles[s], 80);
            setWaveVars(80 / bCycle);
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
