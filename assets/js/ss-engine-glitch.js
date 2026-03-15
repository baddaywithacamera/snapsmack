/**
 * SNAPSMACK - Glitch Engine
 * Alpha v0.7.3a
 *
 * Random chaos effects on page elements. Reads configuration from CSS variables
 * on .post-image element. Only active when enabled and on public pages.
 */

document.addEventListener('DOMContentLoaded', () => {

    const postImage = document.querySelector('.post-image');
    if (!postImage) return;

    // --- CONFIGURATION ---
    // Read glitch settings from CSS custom properties
    const computedStyle = getComputedStyle(postImage);
    const enabled   = computedStyle.getPropertyValue('--glitch-enabled').trim();
    const intensity = parseInt(computedStyle.getPropertyValue('--glitch-intensity')) || 10;
    const speed     = parseInt(computedStyle.getPropertyValue('--glitch-ms')) || 200;

    // Kill switch
    if (enabled === '0') return;

    document.documentElement.style.setProperty('--glitch-intensity', intensity + 'px');

    // --- EFFECT REPERTOIRE ---
    // Define which elements receive which effects and for how long
    const malfunctions = [
        { selector: '.logo-area',          effect: 'glitch-pop',    duration: 1500 },
        { selector: '.post-image',         effect: 'glitch-static', duration: 1500 },
        { selector: '.photo-title-footer', effect: 'glitch-jitter', duration: 1500 },
        { selector: 'body',                effect: 'glitch-reboot', duration: 2500 },
    ];

    // --- TRIGGER LOGIC ---
    // Apply a random glitch effect and schedule the next one
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

        // Scale timing based on speed setting
        const scaleFactor  = speed / 200;
        const minDelay     = Math.round(30000  * scaleFactor);
        const maxDelay     = Math.round(180000 * scaleFactor);
        const nextInterval = Math.floor(Math.random() * (maxDelay - minDelay + 1)) + minDelay;
        setTimeout(triggerMalfunction, nextInterval);
    }

    const firstHit = Math.floor(Math.random() * 30000) + 10000;
    setTimeout(triggerMalfunction, firstHit);
});
