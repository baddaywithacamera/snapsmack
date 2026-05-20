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

function _ssImageFadeInit() {
    const imageSelectorList = [
        '.post-image',
        '.pg-post-image',
        '.tg-image',
        '.inline-asset',
        'img[data-lightbox-src]'
    ];

    // Select all images matching any of the selectors
    const images = document.querySelectorAll(imageSelectorList.join(','));

    images.forEach(img => {
        // Skip if image is already fully loaded (cached images may have loaded before script ran)
        if (img.complete && img.naturalHeight !== 0) {
            img.style.opacity = '1';
            return;
        }

        // Set initial state: invisible, but space reserved
        img.style.opacity = '0';
        img.style.transition = 'opacity 0.4s ease-in-out';

        // Fade in once image has loaded
        const fadeIn = () => {
            img.style.opacity = '1';
        };

        // Handle successful load
        img.addEventListener('load', fadeIn, { once: true });

        // If image fails to load, still make it visible (shows broken image icon)
        img.addEventListener('error', fadeIn, { once: true });

        // Safari/Firefox workaround: images may fire load synchronously after addEventListener
        // Check again in case it already loaded
        if (img.complete && img.naturalHeight !== 0) {
            fadeIn();
        }
    });
}

// Scripts load at end of <body> — DOMContentLoaded may have already fired.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _ssImageFadeInit);
} else {
    _ssImageFadeInit();
}

} // end double-load guard
// ===== SNAPSMACK EOF =====
