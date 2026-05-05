/**
 * SNAPSMACK - Lightbox Engine
 *
 * Full-screen image viewer with fade-in overlay. Handles:
 *   - Single post image (.post-image / .pg-post-image) on layout/archive views
 *   - Inline page images rendered by the [img:ID|size|align] shortcode parser
 *     (identified by the data-lightbox-src attribute)
 *
 * Click/tap to open, click overlay or press ESC to close.
 * Guards against double-loading with internal flag.
 *
 * NOTE: Scripts are loaded at the end of <body> by skin-footer.php so
 * DOMContentLoaded will have already fired by the time this executes. Use
 * readyState guard instead of a bare DOMContentLoaded listener.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


if (!window._ssLightboxLoaded) {
window._ssLightboxLoaded = true;

function _ssLightboxInit() {

    // --- CONFIGURATION ---
    const opacitySetting = (window.SMACK_CONFIG && window.SMACK_CONFIG.lightbox && window.SMACK_CONFIG.lightbox.opacity)
        ? window.SMACK_CONFIG.lightbox.opacity
        : '0.8';

    let activeOverlay = null;

    // --- OVERLAY REMOVAL ---
    const removeOverlay = () => {
        if (!activeOverlay) return;
        activeOverlay.style.opacity = '0';
        setTimeout(() => {
            if (activeOverlay && activeOverlay.parentNode) {
                activeOverlay.parentNode.removeChild(activeOverlay);
            }
            activeOverlay = null;
        }, 180);
        // Unregister hotkey
        if (window.smackdown) window.smackdown.closeLightbox = null;
    };

    // --- OPEN FUNCTION ---
    // src: URL of the full-size image to display
    const openLightbox = (src) => {
        if (activeOverlay) return;

        const overlay = document.createElement('div');
        overlay.id = 'ss-lightbox-overlay';
        overlay.style.cssText = `
            position:fixed; top:0; left:0; width:100vw; height:100vh;
            background:rgba(0,0,0,${opacitySetting});
            display:flex; align-items:center; justify-content:center;
            z-index:9999; opacity:0; transition:opacity 0.18s ease-out; cursor:zoom-out;
        `;

        const big = document.createElement('img');
        big.src = src;
        big.style.cssText = "max-width:95vw; max-height:95vh; box-shadow:0 0 40px rgba(0,0,0,0.8); object-fit:contain;";

        overlay.appendChild(big);
        document.body.appendChild(overlay);
        activeOverlay = overlay;

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                overlay.style.opacity = '1';
            });
        });

        overlay.addEventListener('click', removeOverlay);

        // Expose to hotkey engine so ESC key works
        window.smackdown = window.smackdown || {};
        window.smackdown.closeLightbox = removeOverlay;
    };

    // -------------------------------------------------------------------------
    //  1. SINGLE POST IMAGE  (.post-image / .pg-post-image)
    //     Existing behaviour — direct src of the rendered image element.
    // -------------------------------------------------------------------------
    const photo = document.querySelector('.post-image, .pg-post-image');
    if (photo) {
        photo.style.cursor = 'zoom-in';

        photo.addEventListener('touchend', (e) => {
            if (e.target.closest('a, button')) return;
            e.preventDefault();
            openLightbox(photo.src);
        }, { passive: false });

        photo.addEventListener('click', () => {
            openLightbox(photo.src);
        });
    }

    // -------------------------------------------------------------------------
    //  2. INLINE PAGE IMAGES  (img[data-lightbox-src])
    //     Rendered by core/parser.php from [img:ID|size|align] shortcodes.
    //     data-lightbox-src always points to the full-size original file.
    // -------------------------------------------------------------------------
    document.querySelectorAll('img[data-lightbox-src]').forEach(img => {
        img.addEventListener('touchend', (e) => {
            if (e.target.closest('a, button')) return;
            e.preventDefault();
            openLightbox(img.dataset.lightboxSrc);
        }, { passive: false });

        img.addEventListener('click', () => {
            openLightbox(img.dataset.lightboxSrc);
        });
    });
}

// Scripts load at end of <body> — DOMContentLoaded may have already fired.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _ssLightboxInit);
} else {
    _ssLightboxInit();
}

} // end double-load guard
// ===== SNAPSMACK EOF =====
