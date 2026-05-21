/**
 * SNAPSMACK - Chaplin Film Engine
 *
 * Silent-era effects that live BEHIND or ON the image frame, never on top:
 *   1. Film scratches  — animated vertical lines on the background canvas
 *   2. Flicker        — brief opacity dip on the frame-image element
 *   3. Frame jump     — rare lateral gate-slip on the frame-mount element
 *
 * The background canvas (#chap-film-bg) must be position:fixed, z-index:0.
 * CSS body::after handles static grain texture; this engine adds motion.
 *
 * Usage:
 *   ChaplinFilm.init({ scratchFreq, flickerFreq, jumpFreq, jumpMaxPx });
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

const ChaplinFilm = (function () {

    const DEFAULTS = {
        scratchFreq : 0.008,  // prob. per frame of spawning a new scratch
        flickerFreq : 0.012,  // prob. per 100 ms tick of a flicker event
        jumpFreq    : 0.004,  // prob. per 200 ms tick of a gate-slip jump
        jumpMaxPx   : 5,      // max slip distance in px
    };

    let canvas, ctx, raf, alive = false, cfg = {};
    let scratches = [];
    let frameEl   = null;

    // ── PUBLIC ───────────────────────────────────────────────────────────────

    function init(options = {}) {
        cfg = Object.assign({}, DEFAULTS, options);

        canvas  = document.getElementById('chap-film-bg');
        if (!canvas) return;

        ctx     = canvas.getContext('2d');
        frameEl = document.querySelector('.frame-mount');

        _resize();
        window.addEventListener('resize', _resize);

        alive = true;
        raf   = requestAnimationFrame(_tick);
        _scheduleFlicker();
        _scheduleJump();
    }

    function destroy() {
        alive = false;
        if (raf) cancelAnimationFrame(raf);
        window.removeEventListener('resize', _resize);
    }

    // ── CANVAS ANIMATION ─────────────────────────────────────────────────────

    function _resize() {
        if (!canvas) return;
        canvas.width  = window.innerWidth;
        canvas.height = window.innerHeight;
    }

    function _tick() {
        if (!alive) return;
        _drawFrame();
        raf = requestAnimationFrame(_tick);
    }

    function _drawFrame() {
        const W = canvas.width, H = canvas.height;
        ctx.clearRect(0, 0, W, H);

        // Spawn a new scratch occasionally
        if (Math.random() < cfg.scratchFreq) {
            scratches.push({
                x      : Math.random() * W,
                life   : Math.floor(Math.random() * 6 + 2),
                opacity: Math.random() * 0.32 + 0.08,
                width  : Math.random() < 0.75 ? 1 : 2,
                yStart : Math.random() < 0.55 ? 0 : Math.random() * H * 0.25,
                yEnd   : Math.random() < 0.55 ? H : H - Math.random() * H * 0.25,
            });
        }

        // Draw and age active scratches
        scratches = scratches.filter(s => s.life > 0);
        for (const s of scratches) {
            ctx.globalAlpha = s.opacity * (s.life / 7);
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth   = s.width;
            ctx.beginPath();
            ctx.moveTo(s.x, s.yStart);
            ctx.lineTo(s.x, s.yEnd);
            ctx.stroke();
            s.life--;
        }
        ctx.globalAlpha = 1;
    }

    // ── FLICKER ───────────────────────────────────────────────────────────────

    function _scheduleFlicker() {
        function tick() {
            if (!alive) return;
            if (Math.random() < cfg.flickerFreq) {
                const img = document.querySelector('.frame-image');
                if (img) {
                    img.classList.add('chap-flicker');
                    setTimeout(() => img.classList.remove('chap-flicker'),
                        Math.floor(Math.random() * 70 + 25));
                }
            }
            setTimeout(tick, 100);
        }
        tick();
    }

    // ── FRAME JUMP (gate slip) ────────────────────────────────────────────────

    function _scheduleJump() {
        function tick() {
            if (!alive || !frameEl) { setTimeout(tick, 200); return; }
            if (Math.random() < cfg.jumpFreq) {
                const mx  = cfg.jumpMaxPx;
                const dx  = (Math.random() * 2 - 1) * mx;
                const dy  = (Math.random() * 2 - 1) * mx * 0.35; // bias horizontal
                const dur = Math.floor(Math.random() * 55 + 25);

                frameEl.style.transition = 'none';
                frameEl.style.transform  =
                    `translate(${dx.toFixed(1)}px,${dy.toFixed(1)}px)`;

                setTimeout(() => {
                    frameEl.style.transition = 'transform 0.07s steps(2)';
                    frameEl.style.transform  = '';
                }, dur);
            }
            setTimeout(tick, 200);
        }
        tick();
    }

    return { init, destroy };

})();
// ===== SNAPSMACK EOF =====
