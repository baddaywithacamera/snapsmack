/**
 * SNAPSMACK - THE OTHER SIDE engine
 *
 * A deliberately small state engine for dual-reality skins. Presentation lives
 * in the skin stylesheet; this library only crosses the documented class boundary.
 *
 * Contract:
 *   [data-stanley-variant="retro-flicker"]  base retro, briefly show other
 *   [data-stanley-variant="other-flicker"]  base other, briefly show retro
 *   *-stable variants never cross
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const page = document.querySelector('#stanley-page[data-stanley-variant]');
    if (!page) return;

    const variant = page.dataset.stanleyVariant || '';
    const productionEnabled = variant === 'retro-flicker' || variant === 'other-flicker';
    const testMode = page.dataset.stanleyTest === '1';
    if (!productionEnabled && !testMode) return;

    // This is an atmospheric surprise, not essential information. Respect the
    // visitor's request for reduced motion by leaving their chosen reality stable.
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const body = document.body;
    const baseIsOther = variant === 'other-flicker';
    const minimumDelay = 25 * 60 * 1000;
    const delayRange = 10 * 60 * 1000;
    let timer = null;
    let crossing = false;

    function setOtherSide(active) {
        page.classList.toggle('stanley-other-side-active', active);
        body.classList.toggle('stanley-other-side-active', active);
    }

    function schedule() {
        window.clearTimeout(timer);
        if (!productionEnabled) return;
        const delay = minimumDelay + Math.floor(Math.random() * delayRange);
        timer = window.setTimeout(cross, delay);
    }

    function cross() {
        if (document.hidden || crossing) {
            schedule();
            return;
        }

        crossing = true;
        page.classList.add('stanley-reality-tear');
        body.classList.add('stanley-reality-tear');

        window.setTimeout(function () {
            setOtherSide(!baseIsOther);
        }, 120);

        window.setTimeout(function () {
            setOtherSide(baseIsOther);
            page.classList.add('stanley-reality-snap');
            body.classList.add('stanley-reality-snap');
        }, 980);

        window.setTimeout(function () {
            page.classList.remove('stanley-reality-tear', 'stanley-reality-snap');
            body.classList.remove('stanley-reality-tear', 'stanley-reality-snap');
            crossing = false;
            schedule();
        }, 1160);
    }

    // Ensure the body matches the server-rendered page before the first crossing.
    setOtherSide(baseIsOther);
    if (testMode) {
        timer = window.setTimeout(cross, 1500);
    } else {
        schedule();
    }
});
// ===== SNAPSMACK EOF =====
