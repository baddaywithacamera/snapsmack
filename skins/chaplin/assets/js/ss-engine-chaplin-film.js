/**
 * SNAPSMACK - Chaplin Film Engine
 *
 * Silent-era effects that live BEHIND or ON the image frame, never on top:
 *   1. Film scratches  — animated vertical lines with lateral drift + wobble
 *   2. Dust spots      — brief white specks scattered across the frame
 *   3. Hairs           — rare curved dark strands caught in the gate
 *   4. Flicker         — brief opacity dip on the frame-image element
 *   5. Frame jump      — rare lateral gate-slip on the frame-mount element
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

    // Dust and hair are always on — no config needed
    const DUST_FREQ = 0.18;   // prob. per frame of spawning dust
    const HAIR_FREQ = 0.0012; // prob. per frame of spawning a hair (very rare)

    let canvas, ctx, raf, alive = false, cfg = {};
    let scratches = [];
    let dusts     = [];
    let hairs     = [];
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

        _updateScratches(W, H);
        _updateDust(W, H);
        _updateHairs(W, H);

        ctx.globalAlpha = 1;
    }

    // ── SCRATCHES ────────────────────────────────────────────────────────────
    // Real film scratches run the full frame height but wobble laterally
    // by a pixel or two per frame, and jitter slightly within the line itself.

    function _updateScratches(W, H) {
        if (Math.random() < cfg.scratchFreq) {
            scratches.push({
                x      : Math.random() * W,
                // Drift: how many px the scratch wanders left/right each frame
                drift  : (Math.random() - 0.5) * 2.4,
                // Wobble: max lateral jitter within the drawn line (per segment)
                wobble : Math.random() * 5 + 1.5,
                life   : Math.floor(Math.random() * 9 + 3),
                maxLife: 0,
                opacity: Math.random() * 0.45 + 0.18,
                width  : Math.random() < 0.65 ? 1 : Math.random() < 0.85 ? 2 : 3,
                yStart : Math.random() < 0.55 ? 0 : Math.random() * H * 0.2,
                yEnd   : Math.random() < 0.55 ? H : H - Math.random() * H * 0.2,
                segments: Math.floor(Math.random() * 8 + 6),
            });
            scratches[scratches.length - 1].maxLife = scratches[scratches.length - 1].life;
        }

        scratches = scratches.filter(s => s.life > 0);

        for (const s of scratches) {
            const fade = s.life / s.maxLife;
            ctx.globalAlpha = s.opacity * Math.min(1, fade * 2);
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth   = s.width;
            ctx.lineCap     = 'round';

            // Draw as segmented path so each segment can wobble independently
            const segH = (s.yEnd - s.yStart) / s.segments;
            ctx.beginPath();
            ctx.moveTo(s.x, s.yStart);
            for (let i = 1; i <= s.segments; i++) {
                const xOff = (Math.random() - 0.5) * s.wobble;
                ctx.lineTo(s.x + xOff, s.yStart + segH * i);
            }
            ctx.stroke();

            // Drift the scratch position for next frame
            s.x    += s.drift;
            s.life--;
        }
    }

    // ── DUST SPOTS ───────────────────────────────────────────────────────────
    // Small white/grey specks that flash on briefly — dirt and debris on the film.

    function _updateDust(W, H) {
        if (Math.random() < DUST_FREQ) {
            const count = Math.random() < 0.3 ? 3 : 1; // occasional cluster
            for (let i = 0; i < count; i++) {
                dusts.push({
                    x      : Math.random() * W,
                    y      : Math.random() * H,
                    rx     : Math.random() * 2.5 + 0.5,
                    ry     : Math.random() * 2.0 + 0.5,
                    angle  : Math.random() * Math.PI,
                    life   : Math.floor(Math.random() * 3 + 1),
                    maxLife: 0,
                    opacity: Math.random() * 0.55 + 0.15,
                });
                dusts[dusts.length - 1].maxLife = dusts[dusts.length - 1].life;
            }
        }

        dusts = dusts.filter(d => d.life > 0);

        for (const d of dusts) {
            ctx.globalAlpha = d.opacity * (d.life / d.maxLife);
            ctx.fillStyle   = '#e8e8e8';
            ctx.beginPath();
            ctx.ellipse(d.x, d.y, d.rx, d.ry, d.angle, 0, Math.PI * 2);
            ctx.fill();
            d.life--;
        }
    }

    // ── HAIRS ────────────────────────────────────────────────────────────────
    // Rare: a dark curved strand caught in the gate. Drawn as a quadratic bezier.
    // Persists for many frames — a hair sits in the gate until the reel advances.

    function _updateHairs(W, H) {
        if (Math.random() < HAIR_FREQ) {
            const x1 = Math.random() * W * 0.8 + W * 0.1;
            const y1 = Math.random() * H * 0.6;
            const len = Math.random() * 120 + 60;
            const curl = (Math.random() - 0.5) * 60;
            hairs.push({
                x1,
                y1,
                // Control point gives the hair its curl
                cx     : x1 + curl,
                cy     : y1 + len * 0.45,
                x2     : x1 + (Math.random() - 0.5) * 40,
                y2     : y1 + len,
                life   : Math.floor(Math.random() * 35 + 15),
                maxLife: 0,
                opacity: Math.random() * 0.55 + 0.25,
                // Hairs are dark — they're physical objects in the gate
                color  : Math.random() < 0.7
                    ? 'rgba(20,20,20,1)'
                    : 'rgba(50,40,30,1)',
                width  : Math.random() * 0.9 + 0.3,
            });
            hairs[hairs.length - 1].maxLife = hairs[hairs.length - 1].life;
        }

        hairs = hairs.filter(h => h.life > 0);

        for (const h of hairs) {
            const fade = h.life / h.maxLife;
            // Fade in quickly, linger, fade out at end
            const alpha = fade < 0.15
                ? h.opacity * (fade / 0.15)
                : h.opacity * Math.min(1, fade * 1.2);
            ctx.globalAlpha = alpha;
            ctx.strokeStyle = h.color;
            ctx.lineWidth   = h.width;
            ctx.lineCap     = 'round';
            ctx.beginPath();
            ctx.moveTo(h.x1, h.y1);
            ctx.quadraticCurveTo(h.cx, h.cy, h.x2, h.y2);
            ctx.stroke();
            h.life--;
        }
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
                const dy  = (Math.random() * 2 - 1) * mx * 0.35;
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
