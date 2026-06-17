/**
 * SNAPSMACK - AURORA Tile Border Colour Wave (Layer 2)
 *
 * Drives each grid tile's border-color from a sine wave whose phase is offset
 * by the tile's position, so a slow colour wave appears to travel across the
 * grid. Self-contained: reads all config from the .au-aurora-bg element's data
 * attributes (set by skin-profile.php from the admin config). No core hooks, no
 * fetch, no cookies, no storage, no DOM changes beyond border-color.
 *
 *   phase = (time * speedBase * speedMult) + (positionIndex * WAVE_SPREAD)
 *   positionIndex = col (ltr) | -col (rtl) | row (ttb) | -row (btt)
 *
 * Respects prefers-reduced-motion (paints one frozen frame, no loop) and pauses
 * on document.hidden to avoid background CPU drain.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    var SPEED_BASE  = 0.6;   // rad/s at speedMult 1.0 → ~10s per cycle (geological)
    var WAVE_SPREAD = 0.6;   // radians of phase offset per tile (wavefront width)

    function clamp01(n) { return n < 0 ? 0 : (n > 1 ? 1 : n); }

    function hexToRgb(hex) {
        if (typeof hex !== 'string') return null;
        hex = hex.trim().replace('#', '');
        if (hex.length === 3) {
            hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
        }
        if (hex.length !== 6) return null;
        var n = parseInt(hex, 16);
        if (isNaN(n)) return null;
        return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
    }

    function mix(a, b, t) {
        return {
            r: Math.round(a.r + (b.r - a.r) * t),
            g: Math.round(a.g + (b.g - a.g) * t),
            b: Math.round(a.b + (b.b - a.b) * t)
        };
    }

    function rgbStr(c) { return 'rgb(' + c.r + ',' + c.g + ',' + c.b + ')'; }

    function init() {
        var cfg = document.querySelector('.au-aurora-bg');
        if (!cfg) return;

        // ── Parse palette ────────────────────────────────────────────────
        var palette = [];
        try {
            var raw = JSON.parse(cfg.getAttribute('data-au-palette') || '[]');
            if (Array.isArray(raw)) {
                for (var i = 0; i < raw.length; i++) {
                    var c = hexToRgb(raw[i]);
                    if (c) palette.push(c);
                }
            }
        } catch (e) { /* fall through to default below */ }
        if (palette.length < 2) {
            palette = [
                { r: 97, g: 233, b: 110 }, { r: 0, g: 206, b: 201 },
                { r: 72, g: 153, b: 240 }, { r: 165, g: 94, b: 234 },
                { r: 224, g: 86, b: 215 }, { r: 97, g: 233, b: 110 }
            ];
        }

        var dir       = cfg.getAttribute('data-au-direction') || 'ltr';
        var speedMult = parseFloat(cfg.getAttribute('data-au-speed')) || 0.6;
        var intensity = parseFloat(cfg.getAttribute('data-au-intensity'));
        if (isNaN(intensity)) intensity = 0.85;
        intensity = clamp01(intensity);

        // Peak colour is blended from a near-neutral dark up to the full palette
        // colour by the intensity setting (0% = barely-there, 100% = saturated).
        var neutral = hexToRgb(cfg.getAttribute('data-au-neutral') || '#1a1a1a')
                      || { r: 26, g: 26, b: 26 };

        // ── Build tile lookup ────────────────────────────────────────────
        var nodes = document.querySelectorAll('.au-grid .au-tile');
        var tiles = [];
        for (var j = 0; j < nodes.length; j++) {
            var el = nodes[j];
            if (el.classList.contains('au-tile--phantom')) continue;
            var row = parseInt(el.getAttribute('data-row'), 10) || 0;
            var col = parseInt(el.getAttribute('data-col'), 10) || 0;
            var posIndex;
            switch (dir) {
                case 'rtl': posIndex = -col; break;
                case 'ttb': posIndex =  row; break;
                case 'btt': posIndex = -row; break;
                default:    posIndex =  col; // ltr
            }
            tiles.push({ el: el, pos: posIndex });
        }
        if (!tiles.length) return;

        // ── Colour from a normalized wave position s in [0,1] ─────────────
        function colourAt(s) {
            var span = palette.length - 1;          // segments between stops
            var x    = clamp01(s) * span;
            var idx  = Math.floor(x);
            if (idx >= span) idx = span - 1;
            var frac = x - idx;
            var col  = mix(palette[idx], palette[idx + 1], frac);
            return rgbStr(mix(neutral, col, intensity));
        }

        function paint(timeSec) {
            var base = timeSec * SPEED_BASE * speedMult;
            for (var k = 0; k < tiles.length; k++) {
                var phase = base + tiles[k].pos * WAVE_SPREAD;
                var s = (Math.sin(phase) + 1) / 2;  // → [0,1]
                tiles[k].el.style.borderColor = colourAt(s);
            }
        }

        // ── Reduced motion: paint one frozen frame, no loop ──────────────
        var reduce = window.matchMedia &&
                     window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduce) {
            paint(0);   // position offsets still differ → a frozen wave front
            return;
        }

        // ── Animation loop, paused while the tab is hidden ───────────────
        var rafId = null;
        function frame(now) {
            paint(now / 1000);
            rafId = window.requestAnimationFrame(frame);
        }
        function start() {
            if (rafId === null) rafId = window.requestAnimationFrame(frame);
        }
        function stop() {
            if (rafId !== null) { window.cancelAnimationFrame(rafId); rafId = null; }
        }
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { stop(); } else { start(); }
        });
        if (!document.hidden) start();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
// ===== SNAPSMACK EOF =====
