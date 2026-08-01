/**
 * SNAPSMACK - Lazy Loading Engine
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

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


if (!window._ssLazyLoaded) {
window._ssLazyLoaded = true;

function _ssLazyInit(root) {

    // Optional scope. Called with no argument (or with anything that isn't an
    // element) it scans the whole document exactly as before — that is the only
    // way every existing consumer calls it, so their behaviour is unchanged.
    // Called with an element it scans ONLY that subtree, which is what lets
    // dynamically appended content (e.g. a SCROLL wall chunk) be picked up
    // without re-collecting, re-styling and re-observing every still-pending
    // image already on the page. Same retrofit as ss-engine-image-fade-load.js.
    var scope = (root && root.querySelectorAll) ? root : document;

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
    const autoContainers = '.justified-item, .archive-thumb-link, .grid-cell, .stats-image-card, .pile-card, .wall-cell, .ss-masonry-item';
    scope.querySelectorAll(autoContainers).forEach(container => {
        container.querySelectorAll('img[src]:not([data-src]):not(.ss-lazy-done)').forEach(img => {
            if (!img.src || img.src.indexOf('data:') === 0) return;
            img.dataset.src = img.src;
            img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
            img.classList.add('ss-lazy');
        });
    });

    // --- COLLECT ALL LAZY TARGETS ---
    const lazyImages = scope.querySelectorAll('img[data-src], img[data-lazy-src]');
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

// Re-scan hook for content injected after the initial pass. Pass the newly
// inserted container element; passing nothing rescans the whole document.
// Inert for any skin that never calls it.
//
// ALWAYS PASS THE NEW CONTAINER. The argument is optional to the parser, not in
// practice: called bare, this re-collects every image on the page that is still
// pending and hands them to a SECOND IntersectionObserver over targets the first
// one is already watching. That duplicate never releases them either — once the
// first observer swaps src and drops data-src, the second's handler returns at
// the `if (!realSrc)` guard BEFORE its unobserve(), so the observer leaks for the
// life of the page. Scoped to the injected subtree it is exactly one observer
// over exactly the new images, which is what makes repeated calls safe.
window.ssLazyScan = function (root) { _ssLazyInit(root); };

// Scripts load at end of <body> — DOMContentLoaded may have already fired.
// The listener is WRAPPED deliberately: an unwrapped listener receives the
// DOMContentLoaded Event as argument 1, which must never be mistaken for a
// scope root. (The duck-type guard above also catches it; both, on purpose.)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { _ssLazyInit(document); });
} else {
    _ssLazyInit(document);
}

} // end double-load guard
// ===== SNAPSMACK EOF =====
