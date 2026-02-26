/**
 * SnapSmack - Pimpotron JS Engine (v3.0)
 * -------------------------------------------------------------------------
 * Slide types: image | text | video | matrix
 * Image slides: background fill + text overlay + per-slide image glitch
 * Matrix slides: MatrixRain class, destroy() on transition
 * Glitch system: frequency gate, intensity tiers, stage shift
 * Config-driven boot via window.PIMPOTRON_CONFIG
 * -------------------------------------------------------------------------
 * MODULAR DESIGN NOTE:
 * MatrixRain is a standalone class. It knows nothing about the sequencer.
 * The sequencer knows nothing about how rain works. It just calls
 * new MatrixRain(canvas, config) and rain.destroy().
 * This is intentional and we are very proud of it.
 * -------------------------------------------------------------------------
 */

// =============================================================================
// CLASS: MatrixRain
// =============================================================================

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
        // Defer init until after browser has laid out the canvas
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

// =============================================================================
// CLASS: PimpotronEngine
// =============================================================================

class PimpotronEngine {
    constructor(apiEndpoint, stageElementId) {
        this.apiEndpoint  = apiEndpoint;
        this.stage        = document.getElementById(stageElementId);
        this.logo         = document.querySelector('.snapsmack-logo');
        this.manifest     = null;
        this.global       = null;
        this.slides       = [];
        this.currentIndex = 0;
        this.timer        = null;
        this.textTimer    = null;
        this.activeRain   = null;
        this.isReady      = false;
    }

    async init() {
        try {
            const response = await fetch(this.apiEndpoint);
            this.manifest  = await response.json();
            this.global    = this.manifest.global;
            this.slides    = this.manifest.slides;
            console.log(`[Pimpotron] Manifest loaded: ${this.slides.length} slides.`);
            await this.preloadAssets();
            this.isReady = true;
            this.strike();
        } catch (error) {
            console.error('[Pimpotron] Engine Failure.', error);
            if (this.stage) this.stage.innerHTML = `<div class="error-glitch">DATA CORRUPTION</div>`;
        }
    }

    async preloadAssets() {
        const promises = this.slides.map(slide => {
            if (slide.slide_type !== 'image' || !slide.image_url) return Promise.resolve();
            return new Promise(resolve => {
                const img = new Image();
                img.src = slide.image_url;
                img.onload = img.onerror = resolve;
            });
        });
        await Promise.all(promises);
        console.log('[Pimpotron] Assets preloaded. Ready to smack.');
    }

    strike() {
        if (!this.isReady || this.slides.length === 0) return;
        clearTimeout(this.timer);
        clearInterval(this.textTimer);

        if (this.activeRain) {
            this.activeRain.destroy();
            this.activeRain = null;
        }

        const slide    = this.slides[this.currentIndex];
        const duration = slide.display_duration_ms || this.global.default_speed_ms;

        if (this.shouldGlitch(slide.glitch_frequency)) {
            this.triggerGlitch(slide.glitch_intensity, slide.stage_shift_enabled, slide);
        }

        this.renderSlide(slide);

        if (slide.overlay_text && slide.text_animation_type !== 'static') {
            this.animateText(slide);
        }

        this.currentIndex = (this.currentIndex + 1) % this.slides.length;
        this.timer = setTimeout(() => this.strike(), duration);
    }

    renderSlide(slide) {
        this.stage.style.backgroundColor = slide.bg_color_hex || '#000';

        let contentHTML = '';

        if (slide.slide_type === 'image' && slide.image_url) {
            contentHTML = `<div class="pimpotron-bg-image ${slide.image_glitch_enabled ? 'can-glitch' : ''}"
                               style="background-image:url('${slide.image_url}');"></div>`;

        } else if (slide.slide_type === 'video' && slide.video_url) {
            contentHTML = `<video class="pimpotron-active-video"
                                  ${slide.video_autoplay ? 'autoplay' : ''}
                                  ${slide.video_loop     ? 'loop'     : ''}
                                  ${slide.video_muted    ? 'muted'    : ''}
                                  playsinline>
                               <source src="${slide.video_url}">
                           </video>`;

        } else if (slide.slide_type === 'matrix') {
            contentHTML = `<canvas class="pimpotron-matrix-canvas"></canvas>`;
        }

        const hudHTML = slide.overlay_text
            ? `<div class="pimpotron-hud"
                    style="left:${slide.pos_x_pct}%;top:${slide.pos_y_pct}%;color:${slide.font_color_hex};">
                   ${this.prepareText(slide.overlay_text, slide.text_animation_type)}
               </div>`
            : '';

        this.stage.innerHTML = contentHTML + hudHTML;

        if (slide.slide_type === 'matrix') {
            const canvas    = this.stage.querySelector('.pimpotron-matrix-canvas');
            this.activeRain = new MatrixRain(canvas, {
                bgColor:   slide.bg_color_hex  || '#000000',
                rainColor: slide.rain_color_hex || '#00FF00',
                speed:     slide.rain_speed     || 150,
                density:   slide.rain_density   || 20,
            });
        }
    }

    shouldGlitch(frequency) {
        switch (frequency) {
            case 'every_slide': return true;
            case 'occasional':  return Math.random() < 0.5;
            case 'rare':        return Math.random() < 0.2;
            case 'random':      return Math.random() < Math.random();
            default:            return true;
        }
    }

    triggerGlitch(intensity = 'normal', stageShift = false, slide = null) {
        const duration = intensity === 'subtle' ? 80 : intensity === 'violent' ? 180 : 100;

        if (this.logo) {
            this.logo.classList.add('smack', `smack--${intensity}`);
            setTimeout(() => this.logo.classList.remove('smack', `smack--${intensity}`), duration);
        }

        this.stage.classList.add('smack-active', `smack-active--${intensity}`);
        setTimeout(() => this.stage.classList.remove('smack-active', `smack-active--${intensity}`), duration + 50);

        if (slide && slide.slide_type === 'image' && slide.image_glitch_enabled) {
            this.triggerImageGlitch(intensity, duration);
        }

        if (this.activeRain) {
            this.activeRain.glitchSpike(duration);
        }

        if (stageShift) {
            this.applyStageShift(intensity);
        }
    }

    triggerImageGlitch(intensity, duration) {
        const bg = this.stage.querySelector('.pimpotron-bg-image');
        if (!bg) return;
        const filters = {
            subtle:  'hue-rotate(30deg) saturate(2) brightness(1.3)',
            normal:  'hue-rotate(90deg) saturate(4) brightness(1.8) contrast(1.5)',
            violent: 'hue-rotate(180deg) saturate(8) brightness(2.5) contrast(2) invert(0.2)',
        };
        bg.style.filter    = filters[intensity] || filters.normal;
        bg.style.transform = intensity === 'violent'
            ? `translateX(${(Math.random() * 10 - 5).toFixed(1)}px)` : 'none';
        setTimeout(() => { bg.style.filter = 'none'; bg.style.transform = 'none'; }, duration);
    }

    applyStageShift(intensity) {
        const maxPx    = this.global.stage_shift_max_px || 8;
        const maxScale = this.global.stage_scale_max    || 1.015;
        const mult     = intensity === 'subtle' ? 0.3 : intensity === 'violent' ? 1.0 : 0.6;
        const tx       = (Math.random() * 2 - 1) * maxPx * mult;
        const ty       = (Math.random() * 2 - 1) * maxPx * mult;
        const scale    = 1 + ((maxScale - 1) * mult);
        this.stage.style.transition = 'none';
        this.stage.style.transform  = `translate(${tx}px,${ty}px) scale(${scale})`;
        setTimeout(() => {
            this.stage.style.transition = 'transform 0.08s steps(2)';
            this.stage.style.transform  = 'translate(0,0) scale(1)';
        }, intensity === 'violent' ? 160 : 90);
    }

    prepareText(text, animationType = 'staccato') {
        if (!text) return '';
        if (animationType === 'static') {
            return text.split(' ').map(w => `<span class="smack-word">${w}</span>`).join(' ');
        }
        return text.split(' ').map(w => `<span class="smack-word" style="opacity:0;">${w}</span>`).join(' ');
    }

    animateText(slide) {
        const words    = this.stage.querySelectorAll('.smack-word');
        if (!words.length) return;
        const delay    = slide.word_delay_ms || 200;
        const isGlitch = slide.text_animation_type === 'glitch';
        let   idx      = 0;
        words[idx].style.opacity = 1;
        if (isGlitch) words[idx].classList.add('glitch-reveal');
        idx++;
        this.textTimer = setInterval(() => {
            if (idx >= words.length) { clearInterval(this.textTimer); return; }
            words[idx].style.opacity = 1;
            if (isGlitch) words[idx].classList.add('glitch-reveal');
            idx++;
        }, delay);
    }
}

// =============================================================================
// BOOT
// =============================================================================
document.addEventListener('DOMContentLoaded', () => {
    const cfg      = window.PIMPOTRON_CONFIG ?? {};
    const endpoint = cfg.endpoint ?? '/api/pimpotron-payload.php?slideshow_id=1';
    const stageId  = cfg.stageId  ?? 'pimpotron-sequencer';

    if (!document.getElementById(stageId)) {
        console.warn(`[Pimpotron] Stage element #${stageId} not found. Engine aborted.`);
        return;
    }

    const engine = new PimpotronEngine(endpoint, stageId);
    engine.init();
});
