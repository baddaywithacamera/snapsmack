/**
 * SNAPSMACK - Lazy Loading Engine
 * Alpha v0.7.6
 *
 * Progressive image loading via IntersectionObserver. Images start as
 * lightweight placeholders and fade in when they enter (or approach)
 * the viewport.
 *
 * Convention: any <img> with data-src (or data-lazy-src) will be lazy-
 * loaded. The src attribute should point to a 1×1 transparent GIF or
 * be omitted entirely. Skins using this engine should output:
 *
 *   <img data-src="images/photo.jpg" alt="..." class="ss-lazy">
 *
 * The engine also upgrades standard <img src="..."> tags inside known
 * containers (.justified-item, .archive-thumb-link, .grid-cell) so
 * existing skins get lazy loading without template changes.
 *
 * Guards against double-loading with internal flag.
 */

if (!window._ssLazyLoaded) {
window._ssLazyLoaded = true;

function _ssLazyInit() {

    // --- CONFIGURATION ---
    const rootMargin = (window.SMACK_CONFIG && window.SMACK_CONFIG.lazy && window.SMACK_CONFIG.lazy.rootMargin)
        ? window.SMACK_CONFIG.lazy.rootMargin
        : '200px';   // start loading 200px before entering viewport

    const fadeDuration = (window.SMACK_CONFIG && window.SMACK_CONFIG.lazy && window.SMACK_CONFIG.lazy.fadeDuration)
        ? parseInt(window.SMACK_CONFIG.lazy.fadeDuration, 10)
        : 300;        // ms

    // --- AUTO-UPGRADE EXISTING IMAGES ---
    // Convert standard <img src="..."> inside known gallery containers
    // to lazy-loadable images so skins get this for free.
    const autoContainers = '.justified-item, .archive-thumb-link, .grid-cell, .stats-image-card, .pile-card, .wall-cell';
    document.querySelectorAll(autoContainers).forEach(container => {
        container.querySelectorAll('img[src]:not([data-src]):not(.ss-lazy-done)').forEach(img => {
            if (!img.src || img.src.indexOf('data:') === 0) return;
            img.dataset.src = img.src;
            img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
            img.classList.add('ss-lazy');
        });
    });

    // --- COLLECT ALL LAZY TARGETS ---
    const lazyImages = document.querySelectorAll('img[data-src], img[data-lazy-src]');
    if (!lazyImages.length) return;

    // --- INITIAL STYLES ---
    lazyImages.forEach(img => {
        img.style.opacity = '0';
        img.style.transition = 'opacity ' + fadeDuration + 'ms ease-in';
    });

    // --- OBSERVER ---
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;

                const img = entry.target;
                const realSrc = img.dataset.src || img.dataset.lazySrc;
                if (!realSrc) return;

                img.src = realSrc;
                img.removeAttribute('data-src');
                img.removeAttribute('data-lazy-src');
                img.classList.add('ss-lazy-done');

                img.addEventListener('load', () => {
                    img.style.opacity = '1';
                }, { once: true });

                // If already cached, the load event may not fire
                if (img.complete) {
                    img.style.opacity = '1';
                }

                observer.unobserve(img);
            });
        }, {
            rootMargin: rootMargin,
            threshold: 0
        });

        lazyImages.forEach(img => observer.observe(img));
    } else {
        // Fallback: load everything immediately (old browsers)
        lazyImages.forEach(img => {
            img.src = img.dataset.src || img.dataset.lazySrc || img.src;
            img.style.opacity = '1';
            img.classList.add('ss-lazy-done');
        });
    }
}

// Scripts load at end of <body> — DOMContentLoaded may have already fired.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _ssLazyInit);
} else {
    _ssLazyInit();
}

} // end double-load guard
