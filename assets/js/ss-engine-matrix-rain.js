/**
 * SNAPSMACK - Matrix Rain (background effect)
 *
 * Salvaged from the retired Pimpotron/KIOSK engine — the one self-contained,
 * reusable piece. Canvas "Matrix-style" character rain. Manifest-delivered core
 * library asset (no inline JS, no per-skin copy): any skin or easter-egg that
 * wants it pulls `smack-matrix-rain` via require_scripts.
 *
 * Self-contained. Auto-attaches to every `canvas.ss-matrix-rain` on the page and
 * reads config from its dataset: data-mr-bg (hex), data-mr-color (hex),
 * data-mr-speed (ms/step), data-mr-density (0–100), data-mr-fontsize (px).
 * Respects prefers-reduced-motion (no animation) and pauses on document.hidden.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    // ── Standalone Matrix-rain class (lifted verbatim from the old engine) ──────
    class MatrixRain {
        constructor(canvas, config = {}) {
            this.canvas = canvas;
            this.ctx    = canvas.getContext('2d');
            this.config = {
                bgColor:   config.bgColor   ?? '#000000',
                rainColor: config.rainColor ?? '#00FF00',
                speed:     config.speed     ?? 150,
                density:   config.density   ?? 20,
                fontSize:  config.fontSize  ?? 14,
            };
            this.cols   = [];
            this.raf    = null;
            this.ticker = null;
            this.alive  = false;
            this.chars  = 'アイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワヲン' +
                          'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#$%&';
            this._resize = this._onResize.bind(this);
            window.addEventListener('resize', this._resize);
            requestAnimationFrame(() => this._init());
        }

        _init() {
            this._onResize();
            this.alive = true;
            this._tick();
        }

        _onResize() {
            this.canvas.width  = this.canvas.offsetWidth;
            this.canvas.height = this.canvas.offsetHeight;
            const colCount     = Math.floor(this.canvas.width / this.config.fontSize);
            this.cols = Array.from({ length: colCount }, (_, i) => this.cols[i] ?? Math.random() * -50);
        }

        _tick() {
            if (!this.alive) return;
            const { ctx, cols, config, chars } = this;
            const { width, height }            = this.canvas;

            ctx.fillStyle = this._hexToRgba(config.bgColor, 0.05);
            ctx.fillRect(0, 0, width, height);
            ctx.fillStyle = config.rainColor;
            ctx.font      = `${config.fontSize}px monospace`;

            cols.forEach((y, i) => {
                const char = chars[Math.floor(Math.random() * chars.length)];
                ctx.fillText(char, i * config.fontSize, y * config.fontSize);
                if (y * config.fontSize > height && Math.random() > (1 - config.density / 100)) {
                    cols[i] = 0;
                } else {
                    cols[i] = y + 1;
                }
            });

            this.ticker = setTimeout(() => {
                this.raf = requestAnimationFrame(() => this._tick());
            }, config.speed);
        }

        glitchSpike(duration = 150) {
            const original    = this.config.speed;
            this.config.speed = 16;
            setTimeout(() => { this.config.speed = original; }, duration);
        }

        pause()  { this.alive = false; if (this.raf) cancelAnimationFrame(this.raf); if (this.ticker) clearTimeout(this.ticker); }
        resume() { if (!this.alive) { this.alive = true; this._tick(); } }

        destroy() {
            this.alive = false;
            if (this.raf)    cancelAnimationFrame(this.raf);
            if (this.ticker) clearTimeout(this.ticker);
            window.removeEventListener('resize', this._resize);
        }

        _hexToRgba(hex, alpha) {
            const r = parseInt(hex.slice(1,3), 16);
            const g = parseInt(hex.slice(3,5), 16);
            const b = parseInt(hex.slice(5,7), 16);
            return `rgba(${r},${g},${b},${alpha})`;
        }
    }

    // ── Wiring: attach to each canvas.ss-matrix-rain, config from dataset ───────
    function init() {
        const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) return; // honour reduced-motion: no animation

        const hosts = document.querySelectorAll('canvas.ss-matrix-rain');
        if (!hosts.length) return;

        const instances = [];
        hosts.forEach(function (cv) {
            const d = cv.dataset;
            instances.push(new MatrixRain(cv, {
                bgColor:   d.mrBg,
                rainColor: d.mrColor,
                speed:     d.mrSpeed    ? parseFloat(d.mrSpeed)    : undefined,
                density:   d.mrDensity  ? parseFloat(d.mrDensity)  : undefined,
                fontSize:  d.mrFontsize ? parseFloat(d.mrFontsize) : undefined,
            }));
        });

        document.addEventListener('visibilitychange', function () {
            instances.forEach(function (m) { if (document.hidden) m.pause(); else m.resume(); });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
