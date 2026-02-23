/**
 * SNAPSMACK - Public UI Engine
 * Version: 1.0 - Visitor Logic Only
 */
document.addEventListener('DOMContentLoaded', () => {

    /* --- Footer Toggle Logic --- */
    const btnInfo = document.getElementById('show-details');
    const btnComm = document.getElementById('show-comments');
    const footer = document.getElementById('footer');
    const paneInfo = document.getElementById('pane-info');
    const paneComm = document.getElementById('pane-comments');

    if (footer && (btnInfo || btnComm)) {
        footer.style.display = 'none';
        footer.style.maxHeight = '0';
        footer.style.transition = 'max-height 0.32s cubic-bezier(.2,.9,.2,1)';

        let animating = false;

        const handleToggle = (target, e) => {
            if (e) e.preventDefault();
            if (animating) return;

            if (target === 'comments' && !paneComm) return;
            if (target === 'info' && !paneInfo) return;

            const isClosed = footer.style.display === 'none';
            const activePane = (paneInfo && paneInfo.style.display !== 'none') ? 'info' : (paneComm && paneComm.style.display !== 'none') ? 'comments' : null;

            if (!isClosed && target === activePane) {
                closeFooter();
                return;
            }

            if (target === 'comments' && paneComm) {
                if (paneInfo) paneInfo.style.display = 'none';
                paneComm.style.display = 'block';
            } else if (target === 'info' && paneInfo) {
                paneInfo.style.display = 'block';
                if (paneComm) paneComm.style.display = 'none';
            }

            footer.offsetHeight; // Force reflow so scrollHeight is accurate before animation

            if (isClosed) openFooter();
            else {
                footer.style.maxHeight = footer.scrollHeight + 'px';
                footer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };

        const openFooter = () => {
            animating = true;
            footer.style.display = 'block';
            footer.offsetHeight; 
            footer.style.maxHeight = footer.scrollHeight + 'px';
            footer.addEventListener('transitionend', function onOpen(ev) {
                if (ev.propertyName === 'max-height') {
                    footer.removeEventListener('transitionend', onOpen);
                    footer.style.maxHeight = 'none';
                    footer.style.overflow = 'visible';
                    footer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    animating = false;
                }
            });
        };

        const closeFooter = () => {
            if (footer.style.display === 'none' || animating) return;
            animating = true;
            footer.style.maxHeight = footer.scrollHeight + 'px';
            footer.offsetHeight;
            footer.style.maxHeight = '0';
            footer.style.overflow = 'hidden';
            footer.addEventListener('transitionend', function onClose(ev) {
                if (ev.propertyName === 'max-height') {
                    footer.removeEventListener('transitionend', onClose);
                    footer.style.display = 'none';
                    animating = false;
                }
            });
        };

        if (btnInfo) btnInfo.addEventListener('click', (e) => handleToggle('info', e));
        if (btnComm) btnComm.addEventListener('click', (e) => handleToggle('comments', e));

        // BRIDGE: Expose for Hotkey Engine
        window.smackdown = { toggleFooter: handleToggle, close: closeFooter };
    }

    /* --- Lightbox Logic --- */
    const photo = document.querySelector('.post-image');
    if (photo) {
        photo.style.cursor = 'pointer';
        let activeOverlay = null;

        const removeOverlay = () => {
            if (!activeOverlay) return;
            activeOverlay.style.opacity = '0';
            setTimeout(() => { if (activeOverlay.parentNode) activeOverlay.parentNode.removeChild(activeOverlay); activeOverlay = null; }, 180);
        };

        photo.addEventListener('click', () => {
            if (activeOverlay) return;
            const overlay = document.createElement('div');
            overlay.style.cssText = "position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.8); display:flex; align-items:center; justify-content:center; z-index:9999; opacity:0; transition:opacity 0.18s; cursor:zoom-out;";
            const big = document.createElement('img');
            big.src = photo.src;
            big.style.cssText = "max-width:95vw; max-height:95vh; box-shadow:0 0 20px #000;";
            overlay.appendChild(big);
            document.body.appendChild(overlay);
            activeOverlay = overlay;
            requestAnimationFrame(() => overlay.style.opacity = '1');
            overlay.addEventListener('click', removeOverlay);
            if(window.smackdown) window.smackdown.closeLightbox = removeOverlay;
        });
    }
});/**
 * SNAPSMACK - Public UI Engine
 * Version: 1.0 - Visitor Logic Only
 */
document.addEventListener('DOMContentLoaded', () => {

    /* --- Footer Toggle Logic --- */
    const btnInfo = document.getElementById('show-details');
    const btnComm = document.getElementById('show-comments');
    const footer = document.getElementById('footer');
    const paneInfo = document.getElementById('pane-info');
    const paneComm = document.getElementById('pane-comments');

    if (footer && (btnInfo || btnComm)) {
        footer.style.display = 'none';
        footer.style.maxHeight = '0';
        footer.style.transition = 'max-height 0.32s cubic-bezier(.2,.9,.2,1)';

        let animating = false;

        const handleToggle = (target, e) => {
            if (e) e.preventDefault();
            if (animating) return;

            if (target === 'comments' && !paneComm) return;
            if (target === 'info' && !paneInfo) return;

            const isClosed = footer.style.display === 'none';
            const activePane = (paneInfo && paneInfo.style.display !== 'none') ? 'info' : (paneComm && paneComm.style.display !== 'none') ? 'comments' : null;

            if (!isClosed && target === activePane) {
                closeFooter();
                return;
            }

            if (target === 'comments' && paneComm) {
                if (paneInfo) paneInfo.style.display = 'none';
                paneComm.style.display = 'block';
            } else if (target === 'info' && paneInfo) {
                paneInfo.style.display = 'block';
                if (paneComm) paneComm.style.display = 'none';
            }

            footer.offsetHeight; // Force reflow so scrollHeight is accurate before animation

            if (isClosed) openFooter();
            else {
                footer.style.maxHeight = footer.scrollHeight + 'px';
                footer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };

        const openFooter = () => {
            animating = true;
            footer.style.display = 'block';
            footer.offsetHeight; 
            footer.style.maxHeight = footer.scrollHeight + 'px';
            footer.addEventListener('transitionend', function onOpen(ev) {
                if (ev.propertyName === 'max-height') {
                    footer.removeEventListener('transitionend', onOpen);
                    footer.style.maxHeight = 'none';
                    footer.style.overflow = 'visible';
                    footer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    animating = false;
                }
            });
        };

        const closeFooter = () => {
            if (footer.style.display === 'none' || animating) return;
            animating = true;
            footer.style.maxHeight = footer.scrollHeight + 'px';
            footer.offsetHeight;
            footer.style.maxHeight = '0';
            footer.style.overflow = 'hidden';
            footer.addEventListener('transitionend', function onClose(ev) {
                if (ev.propertyName === 'max-height') {
                    footer.removeEventListener('transitionend', onClose);
                    footer.style.display = 'none';
                    animating = false;
                }
            });
        };

        if (btnInfo) btnInfo.addEventListener('click', (e) => handleToggle('info', e));
        if (btnComm) btnComm.addEventListener('click', (e) => handleToggle('comments', e));

        // BRIDGE: Expose for Hotkey Engine
        window.smackdown = { toggleFooter: handleToggle, close: closeFooter };
    }

    /* --- Lightbox Logic --- */
    const photo = document.querySelector('.post-image');
    if (photo) {
        photo.style.cursor = 'pointer';
        let activeOverlay = null;

        const removeOverlay = () => {
            if (!activeOverlay) return;
            activeOverlay.style.opacity = '0';
            setTimeout(() => { if (activeOverlay.parentNode) activeOverlay.parentNode.removeChild(activeOverlay); activeOverlay = null; }, 180);
        };

        photo.addEventListener('click', () => {
            if (activeOverlay) return;
            const overlay = document.createElement('div');
            overlay.style.cssText = "position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.8); display:flex; align-items:center; justify-content:center; z-index:9999; opacity:0; transition:opacity 0.18s; cursor:zoom-out;";
            const big = document.createElement('img');
            big.src = photo.src;
            big.style.cssText = "max-width:95vw; max-height:95vh; box-shadow:0 0 20px #000;";
            overlay.appendChild(big);
            document.body.appendChild(overlay);
            activeOverlay = overlay;
            requestAnimationFrame(() => overlay.style.opacity = '1');
            overlay.addEventListener('click', removeOverlay);
            if(window.smackdown) window.smackdown.closeLightbox = removeOverlay;
        });
    }
});