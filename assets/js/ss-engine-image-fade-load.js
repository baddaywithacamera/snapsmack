/**
 * SNAPSMACK - Image Fade Load Engine
 *
 * Gracefully fades in images as they load, preventing layout jumps and font resets
 * on pages where image dimensions aren't predetermined. Images start at opacity 0
 * and fade in to opacity 1 once they've loaded.
 *
 * Applies to:
 *   - .post-image (solo photo pages, archive)
 *   - .pg-post-image (photo gallery pages)
 *   - .tg-image (true-grit skin specific)
 *   - .inline-asset (parsed shortcode images in descriptions)
 *   - img[data-lightbox-src] (CSS starts these at opacity:0 — MUST be revealed)
 *
 * 2026-07-18: re-scan on modal open. GRAM-family solo posts open as an AJAX
 * modal (ss-engine-grid-modal.js injects the post AFTER load, then fires
 * 'snapsmack:modal:opened'). This engine only ran on DOMContentLoaded, so a
 * modal image carrying data-lightbox-src (which CSS sets to opacity:0) was never
 * faded in → it stayed invisible (black panel). Now we re-run the reveal on the
 * modal's injected content too.
 *
 * Prevents layout shift by keeping space reserved for images during load.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!window._ssImageFadeLoaded) {
window._ssImageFadeLoaded = true;

var _ssImageFadeSelectors = [
    '.post-image',
    '.pg-post-image',
    '.tg-image',
    '.inline-asset',
    'img[data-lightbox-src]'
];

function _ssImageFadeInit(root) {
    root = root || document;
    var images = root.querySelectorAll(_ssImageFadeSelectors.join(','));

    images.forEach(function (img) {
        // Skip if already handled (idempotent across the initial scan + modal re-scans).
        if (img.dataset._ssFadeDone) { img.style.opacity = '1'; return; }

        // Skip if image is already fully loaded (cached images may have loaded before script ran).
        if (img.complete && img.naturalHeight !== 0) {
            img.style.opacity = '1';
            img.dataset._ssFadeDone = '1';
            return;
        }

        // Set initial state: invisible, but space reserved.
        img.style.opacity = '0';
        img.style.transition = 'opacity 0.4s ease-in-out';

        var fadeIn = function () { img.style.opacity = '1'; img.dataset._ssFadeDone = '1'; };
        img.addEventListener('load', fadeIn, { once: true });
        img.addEventListener('error', fadeIn, { once: true });

        // Safari/Firefox: image may have loaded synchronously after addEventListener.
        if (img.complete && img.naturalHeight !== 0) { fadeIn(); }
    });
}

// Re-reveal images inside a GRAM modal once its content is injected.
document.addEventListener('snapsmack:modal:opened', function (e) {
    var root = (e && e.target && e.target.querySelectorAll) ? e.target : document;
    _ssImageFadeInit(root);
});

// Scripts load at end of <body> — DOMContentLoaded may have already fired.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { _ssImageFadeInit(document); });
} else {
    _ssImageFadeInit(document);
}

} // end double-load guard
// ===== SNAPSMACK EOF =====
