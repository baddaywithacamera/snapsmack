/**
 * SNAPSMACK - Browser Fingerprint Engine
 *
 * Passively collects browser signals and derives a stable 64-character
 * SHA-256 fingerprint. Requires no user interaction.
 *
 * On completion:
 *   - Sets window.__ssFpHash (hex string, 64 chars)
 *   - Injects a hidden <input name="fp_hash"> into every element matching
 *     FORM_SELECTORS so traditional POST forms carry the hash automatically
 *   - Fires a CustomEvent 'ss:fp-ready' on document with detail.hash
 *
 * The community comment engine (ss-engine-community.js) listens for
 * ss:fp-ready and adds fp_hash to every AJAX comment payload.
 *
 * Signals collected (all passive, no user interaction required):
 *   Canvas pixel hash, WebGL renderer, screen geometry, timezone,
 *   browser language, hardware concurrency, device memory,
 *   colour depth, pixel ratio, touch support, platform string.
 *
 * Nothing is sent anywhere by this file. Storage and transmission of the
 * hash is the responsibility of the form/AJAX submission handlers.
 */

(function () {
    'use strict';

    /** CSS selectors for forms that should receive the hidden fp_hash input. */
    const FORM_SELECTORS = [
        'form#comment-form',          // public photo comment form (process-comment.php)
        'form.ss-comment-form',       // community comment forms
    ];

    // ── Signal collectors ─────────────────────────────────────────────────────

    function canvasSignal() {
        try {
            const c = document.createElement('canvas');
            c.width = 280; c.height = 60;
            const ctx = c.getContext('2d');
            if (!ctx) return 'no-canvas';
            ctx.textBaseline = 'alphabetic';
            ctx.fillStyle = '#f60';
            ctx.fillRect(125, 1, 62, 20);
            ctx.fillStyle = '#069';
            ctx.font = '11pt "Times New Roman"';
            ctx.fillText('SnapSmack \ud83d\udcf8 Cwm fjordbank glyphs vext quiz', 2, 15);
            ctx.fillStyle = 'rgba(102,204,0,0.7)';
            ctx.font = '18pt Arial';
            ctx.fillText('SnapSmack \ud83d\udcf8 Cwm fjordbank glyphs vext quiz', 4, 45);
            return c.toDataURL();
        } catch (e) {
            return 'canvas-error';
        }
    }

    function webglSignal() {
        try {
            const c = document.createElement('canvas');
            const gl = c.getContext('webgl') || c.getContext('experimental-webgl');
            if (!gl) return 'no-webgl';
            const ext = gl.getExtension('WEBGL_debug_renderer_info');
            const vendor   = ext ? gl.getParameter(ext.UNMASKED_VENDOR_WEBGL)   : gl.getParameter(gl.VENDOR);
            const renderer = ext ? gl.getParameter(ext.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER);
            return `${vendor}~${renderer}`;
        } catch (e) {
            return 'webgl-error';
        }
    }

    function audioSignal() {
        try {
            const ctx = new (window.OfflineAudioContext || window.webkitOfflineAudioContext)(1, 44100, 44100);
            const osc = ctx.createOscillator();
            const comp = ctx.createDynamicsCompressor();
            [['threshold',-50],['knee',40],['ratio',12],['attack',0],['release',0.25]]
                .forEach(([k,v]) => comp[k] && comp[k].setValueAtTime(v, ctx.currentTime));
            osc.connect(comp);
            comp.connect(ctx.destination);
            osc.start(0);
            // Return a promise resolving to the audio fingerprint string
            return ctx.startRendering().then(buf => {
                const data = buf.getChannelData(0);
                let sum = 0;
                for (let i = 4500; i < 5000; i++) sum += Math.abs(data[i]);
                return sum.toString();
            }).catch(() => 'audio-error');
        } catch (e) {
            return Promise.resolve('audio-unavailable');
        }
    }

    function screenSignal() {
        const s = window.screen;
        return [s.width, s.height, s.colorDepth, s.pixelDepth,
                window.devicePixelRatio || 1].join('x');
    }

    function envSignal() {
        const n = navigator;
        return [
            Intl.DateTimeFormat().resolvedOptions().timeZone || new Date().getTimezoneOffset(),
            (n.languages || [n.language]).join(','),
            n.platform || '',
            n.hardwareConcurrency || 0,
            n.deviceMemory           || 0,
            n.maxTouchPoints        || 0,
        ].join('|');
    }

    // ── SHA-256 via Web Crypto ────────────────────────────────────────────────

    async function sha256(str) {
        const buf = await crypto.subtle.digest(
            'SHA-256',
            new TextEncoder().encode(str)
        );
        return Array.from(new Uint8Array(buf))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }

    // ── Main ──────────────────────────────────────────────────────────────────

    async function collect() {
        const audioResult = await (typeof audioSignal() === 'string'
            ? Promise.resolve(audioSignal())
            : audioSignal());

        const raw = [
            canvasSignal(),
            webglSignal(),
            screenSignal(),
            envSignal(),
            audioResult,
        ].join('||');

        return sha256(raw);
    }

    function injectIntoForms(hash) {
        FORM_SELECTORS.forEach(sel => {
            document.querySelectorAll(sel).forEach(form => {
                // Don't double-inject
                if (form.querySelector('input[name="fp_hash"]')) return;
                const input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = 'fp_hash';
                input.value = hash;
                form.appendChild(input);
            });
        });
    }

    collect().then(hash => {
        window.__ssFpHash = hash;
        injectIntoForms(hash);
        document.dispatchEvent(new CustomEvent('ss:fp-ready', { detail: { hash } }));
    }).catch(() => {
        // Fingerprinting failed silently — comments still work, just without hash
        window.__ssFpHash = '';
    });

})();
// EOF
