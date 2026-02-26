/**
 * SnapSmack - Logo Glitch Engine (v1.0)
 * -------------------------------------------------------------------------
 * Standalone idle glitch loop for .snapsmack-logo.
 * Completely independent of Pimpotron — runs alongside it, never conflicts.
 * Reads config from window.SNAP_LOGO_CONFIG injected by the skin.
 * -------------------------------------------------------------------------
 * WHAT IT DOES:
 * - Randomly twitches the logo at irregular intervals (not a loop)
 * - Three glitch types: font-swap (BlackCasper), intrusion (Courier New),
 *   inversion, horizontal slice, white blowout, stutter
 * - Colour split position drifts on violent hits
 * - Sometimes fires rapid-fire bursts, sometimes long silences
 * - Additive with Pimpotron .smack class — they coexist
 * -------------------------------------------------------------------------
 */

class SnapSmackLogoEngine {
    constructor(config = {}) {
        this.config = {
            enabled:        config.enabled        ?? true,
            frequency:      config.frequency      ?? 'normal', // low | normal | high | chaos
            fonts:          config.fonts          ?? ['blackcasper', 'courier'],
            splitPosition:  config.splitPosition  ?? 50,       // % left/right split
            splitDrift:     config.splitDrift     ?? true,     // drift split on violent hits
        };

        this.logo      = document.querySelector('.snapsmack-logo');
        this.timer     = null;
        this.running   = false;

        // Glitch repertoire
        this.repertoire = [
            'font-blackcasper',
            'font-courier',
            'inversion',
            'slice',
            'blowout',
            'stutter',
        ];
    }

    init() {
        if (!this.config.enabled || !this.logo) return;
        this.running = true;
        this._scheduleNext();
        console.log('[LogoEngine] Initialised. Standing by to cause problems.');
    }

    // -------------------------------------------------------------------------
    // SCHEDULER: Irregular timing — the soul of the engine
    // -------------------------------------------------------------------------
    _scheduleNext() {
        if (!this.running) return;

        const intervals = {
            low:   [4000, 12000],
            normal:[1500, 7000],
            high:  [500,  3000],
            chaos: [100,  5000],
        };

        const [min, max] = intervals[this.config.frequency] ?? intervals.normal;

        // Occasionally schedule a burst (3-5 rapid hits)
        const doBurst = Math.random() < 0.15;

        if (doBurst) {
            this._runBurst();
        } else {
            const delay = min + Math.random() * (max - min);
            this.timer = setTimeout(() => {
                this._fire();
                this._scheduleNext();
            }, delay);
        }
    }

    _runBurst() {
        const hits  = 3 + Math.floor(Math.random() * 3); // 3-5 hits
        const gap   = 80 + Math.random() * 120;           // 80-200ms apart
        let   fired = 0;

        const shoot = () => {
            if (!this.running || fired >= hits) {
                // Long pause after burst
                const rest = 3000 + Math.random() * 5000;
                this.timer = setTimeout(() => this._scheduleNext(), rest);
                return;
            }
            this._fire();
            fired++;
            setTimeout(shoot, gap);
        };
        shoot();
    }

    // -------------------------------------------------------------------------
    // FIRE: Pick a glitch type and execute it
    // -------------------------------------------------------------------------
    _fire() {
        const available = this._filterRepertoire();
        const type      = available[Math.floor(Math.random() * available.length)];
        this._execute(type);
    }

    _filterRepertoire() {
        return this.repertoire.filter(t => {
            if (t === 'font-blackcasper') return this.config.fonts.includes('blackcasper');
            if (t === 'font-courier')     return this.config.fonts.includes('courier');
            return true;
        });
    }

    // -------------------------------------------------------------------------
    // EXECUTE: Apply the glitch, clean it up
    // -------------------------------------------------------------------------
    _execute(type) {
        switch (type) {

            case 'font-blackcasper': {
                const dur = 60 + Math.random() * 80;
                this.logo.classList.add('logo-glitch--blackcasper');
                setTimeout(() => this.logo.classList.remove('logo-glitch--blackcasper'), dur);
                break;
            }

            case 'font-courier': {
                // System intrusion — Courier New, eerie, longer hold
                const dur = 150 + Math.random() * 100;
                this.logo.classList.add('logo-glitch--courier');
                setTimeout(() => this.logo.classList.remove('logo-glitch--courier'), dur);
                break;
            }

            case 'inversion': {
                // Flip the colour split briefly
                const dur = 80 + Math.random() * 60;
                this.logo.classList.add('logo-glitch--invert');
                setTimeout(() => this.logo.classList.remove('logo-glitch--invert'), dur);
                break;
            }

            case 'slice': {
                // VHS horizontal tracking slice
                const dur = 100 + Math.random() * 80;
                this.logo.classList.add('logo-glitch--slice');
                setTimeout(() => this.logo.classList.remove('logo-glitch--slice'), dur);
                break;
            }

            case 'blowout': {
                // Full white blowout then crash back
                const dur = 60 + Math.random() * 40;
                this.logo.classList.add('logo-glitch--blowout');
                setTimeout(() => this.logo.classList.remove('logo-glitch--blowout'), dur);
                break;
            }

            case 'stutter': {
                // 3-4 rapid inversions in quick succession
                const flips = 3 + Math.floor(Math.random() * 2);
                let f = 0;
                const flip = () => {
                    if (f >= flips * 2) return;
                    this.logo.classList.toggle('logo-glitch--invert');
                    f++;
                    setTimeout(flip, 30 + Math.random() * 30);
                };
                flip();
                break;
            }
        }
    }

    destroy() {
        this.running = false;
        clearTimeout(this.timer);
        this.logo.classList.remove(
            'logo-glitch--blackcasper',
            'logo-glitch--courier',
            'logo-glitch--invert',
            'logo-glitch--slice',
            'logo-glitch--blowout'
        );
        console.log('[LogoEngine] Destroyed. Back to normal. How boring.');
    }
}

// =============================================================================
// BOOT
// =============================================================================
document.addEventListener('DOMContentLoaded', () => {
    const cfg = window.SNAP_LOGO_CONFIG ?? {};
    if (cfg.enabled === false) return;

    const engine = new SnapSmackLogoEngine(cfg);
    engine.init();

    // Expose for external control if needed
    window._logoEngine = engine;
});
