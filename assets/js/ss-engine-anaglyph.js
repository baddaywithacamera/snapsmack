/**
 * SNAPSMACK — Anaglyph 3D Engine
 * ss-engine-anaglyph.js
 *
 * Red/cyan stereoscopic channel separation for text and image frames.
 * Designed for sites displaying real anaglyph 3D photography — extends
 * the stereoscopic depth into the UI chrome so text and frames exist
 * in the same dimensional space as the photographs.
 *
 * TEXT:  Stamps data-text attributes, generates ::before (red channel)
 *        and ::after (cyan channel) layers via CSS. Offset controlled
 *        by --anaglyph-text-depth custom property.
 *
 * FRAMES: Adds red/cyan shadow pairs to image containers, creating
 *         depth separation on borders and shadows. Controlled by
 *         --anaglyph-frame-depth.
 *
 * Settings read from a global object or data attributes on [data-anaglyph]:
 *   data-anaglyph              — marker attribute (presence triggers init)
 *   data-text-depth            — text offset in px (default: 3)
 *   data-frame-depth           — frame offset in px (default: 4)
 *   data-text-targets          — CSS selector for text elements (default: h1,.post-title,.site-title-text)
 *   data-frame-targets         — CSS selector for frame elements (default: .frame-mount,.image-wrap,.post-image)
 *   data-animation             — none | pulse | drift | glitch (default: none)
 *   data-pulse-speed           — pulse cycle duration in ms (default: 4000)
 *   data-red                   — red channel colour (default: #ff0000)
 *   data-cyan                  — cyan channel colour (default: #00ffff)
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    var root = document.querySelector('[data-anaglyph]');
    if (!root) return;

    // ── Config ───────────────────────────────────────────────────────────
    var cfg = {
        textDepth:    parseFloat(root.dataset.textDepth) || 3,
        frameDepth:   parseFloat(root.dataset.frameDepth) || 4,
        textTargets:  root.dataset.textTargets || 'h1, .post-title, .site-title-text, .plaque-title',
        frameTargets: root.dataset.frameTargets || '.frame-mount, .image-wrap, .post-image-container',
        animation:    root.dataset.animation || 'none',
        pulseSpeed:   parseInt(root.dataset.pulseSpeed, 10) || 4000,
        red:          root.dataset.red || '#ff0000',
        cyan:         root.dataset.cyan || '#00ffff',
    };

    // ── Utilities ────────────────────────────────────────────────────────
    function hexToRgba(hex, alpha) {
        var r = parseInt(hex.slice(1, 3), 16);
        var g = parseInt(hex.slice(3, 5), 16);
        var b = parseInt(hex.slice(5, 7), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    }

    // ── Text Anaglyph ────────────────────────────────────────────────────
    // Stamps data-text on targeted elements so CSS ::before/::after can
    // render the red and cyan offset layers.
    function initText() {
        var els = document.querySelectorAll(cfg.textTargets);
        els.forEach(function (el) {
            if (el.dataset.anaglyphText) return; // already processed
            el.dataset.anaglyphText = '1';
            el.dataset.text = el.textContent;
            el.classList.add('anaglyph-text');
        });

        // Set custom properties on root
        document.documentElement.style.setProperty('--anaglyph-text-depth', cfg.textDepth + 'px');
        document.documentElement.style.setProperty('--anaglyph-red', cfg.red);
        document.documentElement.style.setProperty('--anaglyph-cyan', cfg.cyan);
    }

    // ── Frame Anaglyph ───────────────────────────────────────────────────
    // Adds colour-separated box shadows to image containers.
    function initFrames() {
        var els = document.querySelectorAll(cfg.frameTargets);
        var d = cfg.frameDepth;
        var redShadow  = (-d) + 'px 0 0 ' + hexToRgba(cfg.red, 0.5);
        var cyanShadow = d + 'px 0 0 ' + hexToRgba(cfg.cyan, 0.5);

        els.forEach(function (el) {
            if (el.dataset.anaglyphFrame) return; // already processed
            el.dataset.anaglyphFrame = '1';
            el.classList.add('anaglyph-frame');

            // Preserve existing box-shadow and append anaglyph layers
            var existing = getComputedStyle(el).boxShadow;
            if (existing === 'none') existing = '';
            else existing += ', ';

            el.style.boxShadow = existing + redShadow + ', ' + cyanShadow;
        });

        document.documentElement.style.setProperty('--anaglyph-frame-depth', d + 'px');
    }

    // ── Animation: Pulse ─────────────────────────────────────────────────
    // Smoothly oscillates text depth between 50% and 150% of the base.
    var pulseRAF = null;
    function startPulse() {
        var startTime = performance.now();
        var baseDepth = cfg.textDepth;

        function tick(now) {
            var elapsed = now - startTime;
            var phase = (Math.sin((elapsed / cfg.pulseSpeed) * Math.PI * 2) + 1) / 2;
            var depth = baseDepth * (0.5 + phase);
            document.documentElement.style.setProperty('--anaglyph-text-depth', depth.toFixed(1) + 'px');
            pulseRAF = requestAnimationFrame(tick);
        }
        pulseRAF = requestAnimationFrame(tick);
    }

    // ── Animation: Drift ─────────────────────────────────────────────────
    // Red and cyan channels wander independently with different frequencies.
    var driftRAF = null;
    function startDrift() {
        var startTime = performance.now();
        var base = cfg.textDepth;

        function tick(now) {
            var t = (now - startTime) / 1000;
            var redX  = Math.sin(t * 0.7) * base * 1.2;
            var cyanX = Math.sin(t * 0.5 + 1.8) * base * 1.2;
            document.documentElement.style.setProperty('--anaglyph-red-x', redX.toFixed(1) + 'px');
            document.documentElement.style.setProperty('--anaglyph-cyan-x', cyanX.toFixed(1) + 'px');
            document.documentElement.style.setProperty('--anaglyph-drift', '1');
            driftRAF = requestAnimationFrame(tick);
        }
        driftRAF = requestAnimationFrame(tick);
    }

    // ── Animation: Glitch ────────────────────────────────────────────────
    // Random offset snaps at irregular intervals.
    var glitchTimer = null;
    function startGlitch() {
        function snap() {
            var intensity = cfg.textDepth * (1 + Math.random() * 3);
            var dir = Math.random() > 0.5 ? 1 : -1;
            document.documentElement.style.setProperty('--anaglyph-text-depth', (intensity * dir).toFixed(1) + 'px');

            // Occasionally do a hard flash (double intensity, very brief)
            if (Math.random() > 0.85) {
                var flash = cfg.textDepth * 5;
                document.documentElement.style.setProperty('--anaglyph-text-depth', flash.toFixed(1) + 'px');
                setTimeout(function () {
                    document.documentElement.style.setProperty('--anaglyph-text-depth', cfg.textDepth + 'px');
                }, 50);
            }

            // Return to base after a brief hold
            setTimeout(function () {
                document.documentElement.style.setProperty('--anaglyph-text-depth', cfg.textDepth + 'px');
            }, 80 + Math.random() * 120);

            // Schedule next glitch at random interval
            glitchTimer = setTimeout(snap, 800 + Math.random() * 4000);
        }
        glitchTimer = setTimeout(snap, 1000 + Math.random() * 2000);
    }

    // ── MutationObserver for dynamic content ─────────────────────────────
    // Re-stamps data-text when DOM changes (e.g. AJAX navigation).
    var observer = new MutationObserver(function () {
        initText();
        initFrames();
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // ── Depth slider API ─────────────────────────────────────────────────
    // Exposed on window so skins can wire up a live slider control.
    window.snapAnaglyph = {
        setTextDepth: function (px) {
            cfg.textDepth = parseFloat(px);
            document.documentElement.style.setProperty('--anaglyph-text-depth', cfg.textDepth + 'px');
        },
        setFrameDepth: function (px) {
            cfg.frameDepth = parseFloat(px);
            // Re-apply frame shadows
            document.querySelectorAll('.anaglyph-frame').forEach(function (el) {
                el.dataset.anaglyphFrame = '';
            });
            initFrames();
        },
        getConfig: function () { return cfg; }
    };

    // ── Init ─────────────────────────────────────────────────────────────
    initText();
    initFrames();

    if (cfg.animation === 'pulse') startPulse();
    else if (cfg.animation === 'drift') startDrift();
    else if (cfg.animation === 'glitch') startGlitch();

})();
// ===== SNAPSMACK EOF =====
