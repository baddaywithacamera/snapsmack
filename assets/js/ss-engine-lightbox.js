/**
 * SNAPSMACK - Lightbox Engine
 * Alpha v0.7.1
 *
 * Full-screen image viewer with fade-in overlay. Click to open, click to close
 * or press ESC. Guards against double-loading with internal flag.
 */

if (!window._ssLightboxLoaded) {
window._ssLightboxLoaded = true;

document.addEventListener('DOMContentLoaded', () => {
    const photo = document.querySelector('.post-image, .pg-post-image');
    if (!photo) return;

    photo.style.cursor = 'zoom-in';
    let activeOverlay = null;

    // --- CONFIGURATION ---
    // Pull opacity setting from global config, default to 0.8
    const opacitySetting = (window.SMACK_CONFIG && window.SMACK_CONFIG.lightbox && window.SMACK_CONFIG.lightbox.opacity)
        ? window.SMACK_CONFIG.lightbox.opacity
        : '0.8';

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
    };

    // --- OPEN FUNCTION ---
    const openLightbox = () => {
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
        big.src = photo.src;
        big.style.cssText = "max-width:95vw; max-height:95vh; box-shadow:0 0 40px rgba(0,0,0,0.8); object-fit:contain;";

        overlay.appendChild(big);
        document.body.appendChild(overlay);
        activeOverlay = overlay;

        // Force reflow to trigger CSS transition
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

    // Touch: fire immediately on finger-up (no 300ms synthetic-click delay).
    photo.addEventListener('touchend', (e) => {
        if (e.target.closest('a, button')) return;
        e.preventDefault();
        openLightbox();
    }, { passive: false });

    // Mouse / desktop click
    photo.addEventListener('click', () => {
        openLightbox();
    });
});

} // end double-load guard
