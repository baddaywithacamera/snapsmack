/**
 * SNAPSMACK - Chaos Engine
 * Version: 2026.3 - Enable/Disable Control
 * Last changed: 2026-02-23
 * -------------------------------------------------------------------------
 * Reads --glitch-enabled, --glitch-intensity, --glitch-ms from .post-image.
 * Only runs on public pages with a .post-image present.
 * -------------------------------------------------------------------------
 */
document.addEventListener('DOMContentLoaded', () => {

    const postImage = document.querySelector('.post-image');
    if (!postImage) return;

    const computedStyle = getComputedStyle(postImage);
    const enabled   = computedStyle.getPropertyValue('--glitch-enabled').trim();
    const intensity = parseInt(computedStyle.getPropertyValue('--glitch-intensity')) || 10;
    const speed     = parseInt(computedStyle.getPropertyValue('--glitch-ms')) || 200;

    // Kill switch
    if (enabled === '0') return;

    document.documentElement.style.setProperty('--glitch-intensity', intensity + 'px');

    const malfunctions = [
        { selector: '.logo-area',          effect: 'glitch-pop',    duration: 1500 },
        { selector: '.post-image',         effect: 'glitch-static', duration: 1500 },
        { selector: '.photo-title-footer', effect: 'glitch-jitter', duration: 1500 },
        { selector: 'body',                effect: 'glitch-reboot', duration: 2500 },
    ];

    function triggerMalfunction() {
        const roll   = Math.floor(Math.random() * malfunctions.length);
        const entry  = malfunctions[roll];
        const target = (entry.selector === 'body')
            ? document.body
            : document.querySelector(entry.selector);

        if (target) {
            target.classList.add(entry.effect);
            setTimeout(() => target.classList.remove(entry.effect), entry.duration);
        }

        const scaleFactor  = speed / 200;
        const minDelay     = Math.round(30000  * scaleFactor);
        const maxDelay     = Math.round(180000 * scaleFactor);
        const nextInterval = Math.floor(Math.random() * (maxDelay - minDelay + 1)) + minDelay;
        setTimeout(triggerMalfunction, nextInterval);
    }

    const firstHit = Math.floor(Math.random() * 30000) + 10000;
    setTimeout(triggerMalfunction, firstHit);
});
