/**
 * SnapSmack Engine: Lightbox
 * Version: 2.2 - Double-Load Guard
 * MASTER DIRECTIVE: Full file return. Logic only.
 */
if (!window._ssLightboxLoaded) {
window._ssLightboxLoaded = true;

document.addEventListener('DOMContentLoaded', () => {
    const photo = document.querySelector('.post-image');
    if (!photo) return;

    photo.style.cursor = 'zoom-in';
    let activeOverlay = null;

    // Pull settings from global config, default to 0.8 if not yet defined by admin
    const opacitySetting = (window.SMACK_CONFIG && window.SMACK_CONFIG.lightbox && window.SMACK_CONFIG.lightbox.opacity) 
        ? window.SMACK_CONFIG.lightbox.opacity 
        : '0.8';

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

    photo.addEventListener('click', () => {
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

        // Force browser reflow to ensure the CSS transition fires
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                overlay.style.opacity = '1';
            });
        });

        overlay.addEventListener('click', removeOverlay);
        
        // BRIDGE: Expose to Comms engine so the ESC key works
        window.smackdown = window.smackdown || {};
        window.smackdown.closeLightbox = removeOverlay;
    });
});

} // end double-load guard
