document.addEventListener('DOMContentLoaded', () => {

    /* ---------------------------------------------------------
       DUAL-PANE FOOTER TOGGLE (INFO vs COMMENTS)
       --------------------------------------------------------- */

    const btnInfo = document.getElementById('show-details');
    const btnComm = document.getElementById('show-comments');
    const footer = document.getElementById('footer');
    const paneInfo = document.getElementById('pane-info');
    const paneComm = document.getElementById('pane-comments');

    if (footer && btnInfo && btnComm) {
        footer.style.display = 'none';
        footer.style.overflow = 'hidden';
        footer.style.maxHeight = '0';
        footer.style.transition = 'max-height 0.32s cubic-bezier(.2,.9,.2,1)';

        let animating = false;

        const handleToggle = (target, e) => {
            if (e) e.preventDefault();
            if (animating) return;

            const isClosed = footer.style.display === 'none';
            const activePane = paneInfo.style.display === 'none' ? 'comments' : 'info';

            // 1. If footer is open and user clicks the SAME button, close it.
            if (!isClosed && target === activePane) {
                closeFooter();
                return;
            }

            // 2. Switch the content panes immediately
            if (target === 'comments') {
                paneInfo.style.display = 'none';
                paneComm.style.display = 'block';
            } else {
                paneInfo.style.display = 'block';
                paneComm.style.display = 'none';
            }

            // 3. Open or Recalculate Height
            if (isClosed) {
                openFooter();
            } else {
                const newHeight = footer.scrollHeight;
                footer.style.maxHeight = newHeight + 'px';
                footer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };

        const openFooter = () => {
            animating = true;
            footer.style.display = 'block';
            footer.style.overflow = 'hidden';
            footer.offsetHeight; // Force reflow
            const fullHeight = footer.scrollHeight;
            footer.style.maxHeight = fullHeight + 'px';

            const onOpenEnd = (ev) => {
                if (ev.propertyName === 'max-height') {
                    footer.removeEventListener('transitionend', onOpenEnd);
                    footer.style.maxHeight = 'none';
                    footer.style.overflow = 'visible';
                    footer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    animating = false;
                }
            };
            footer.addEventListener('transitionend', onOpenEnd);
        };

        const closeFooter = () => {
            animating = true;
            if (!footer.style.maxHeight || footer.style.maxHeight === 'none') {
                footer.style.maxHeight = footer.scrollHeight + 'px';
                footer.offsetHeight;
            }
            footer.style.overflow = 'hidden';
            footer.style.maxHeight = '0';

            const onCloseEnd = (ev) => {
                if (ev.propertyName === 'max-height') {
                    footer.removeEventListener('transitionend', onCloseEnd);
                    footer.style.display = 'none';
                    animating = false;
                }
            };
            footer.addEventListener('transitionend', onCloseEnd);
        };

        btnInfo.addEventListener('click', (e) => handleToggle('info', e));
        btnComm.addEventListener('click', (e) => handleToggle('comments', e));

        // EXPOSE handleToggle globally so we can open the comments pane programmatically
        window.smackdown = { toggleFooter: handleToggle };
    }

    /* ---------------------------------------------------------
       LIGHTBOX / EMBIGGEN
       --------------------------------------------------------- */

    const photo = document.querySelector('.post-image');

    if (photo) {
        photo.style.cursor = 'pointer';
        let activeOverlay = null;
        let onKeyDown = null;

        const openOverlay = () => {
            if (activeOverlay) return;

            const overlay = document.createElement('div');
            overlay.style.position = 'fixed';
            overlay.style.top = '0'; overlay.style.left = '0';
            overlay.style.width = '100vw'; overlay.style.height = '100vh';
            overlay.style.background = 'rgba(0,0,0,0.8)';
            overlay.style.display = 'flex'; overlay.style.alignItems = 'center'; overlay.style.justifyContent = 'center';
            overlay.style.zIndex = '9999'; overlay.style.cursor = 'zoom-out';
            overlay.style.transition = 'opacity 0.18s ease-out';
            overlay.style.opacity = '0';

            const big = document.createElement('img');
            big.src = photo.src;
            big.style.maxWidth = '95vw'; big.style.maxHeight = '95vh';
            big.style.boxShadow = '0 0 20px #000';
            big.style.transform = 'scale(0.88)';
            big.style.transition = 'transform 0.18s cubic-bezier(.2,.9,.2,1)';

            overlay.appendChild(big);
            document.body.appendChild(overlay);
            activeOverlay = overlay;

            requestAnimationFrame(() => {
                overlay.style.opacity = '1';
                big.style.transform = 'scale(1)';
            });

            const removeOverlay = () => {
                if (!activeOverlay) return;
                const ov = activeOverlay;
                const imgEl = ov.querySelector('img');
                ov.style.opacity = '0';
                if (imgEl) imgEl.style.transform = 'scale(0.88)';
                setTimeout(() => {
                    if (ov.parentNode) ov.parentNode.removeChild(ov);
                    activeOverlay = null;
                }, 180);
                if (onKeyDown) {
                    document.removeEventListener('keydown', onKeyDown);
                    onKeyDown = null;
                }
            };

            overlay.addEventListener('click', (ev) => {
                if (ev.target === overlay) removeOverlay();
            });

            const bigImg = overlay.querySelector('img');
            if (bigImg) {
                bigImg.style.cursor = 'zoom-out';
                bigImg.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    removeOverlay();
                });
            }

            onKeyDown = (ev) => {
                if (ev.key === 'Escape') removeOverlay();
            };
            document.addEventListener('keydown', onKeyDown);
        };

        photo.addEventListener('click', openOverlay);
    }

    /* ---------------------------------------------------------
       SYSTEM NOTIFICATIONS (HUD)
       --------------------------------------------------------- */
    const urlParams = new URLSearchParams(window.location.search);
    
    if (urlParams.get('status') === 'received') {
        // 1. Create the notification element
        const hud = document.createElement('div');
        hud.className = 'hud-msg';
        hud.innerText = 'TRANSMISSION RECEIVED // AWAITING AUTHORIZATION';
        document.body.appendChild(hud);

        // 2. Trigger animation
        setTimeout(() => hud.classList.add('show'), 100);

        // 3. Open the comments pane automatically so they see where it goes
        if (window.smackdown) {
            setTimeout(() => {
                window.smackdown.toggleFooter('comments');
            }, 600);
        }

        // 4. Cleanup
        setTimeout(() => {
            hud.classList.remove('show');
            setTimeout(() => hud.remove(), 600);
            
            // Wipe the URL status so it doesn't re-trigger on refresh
            window.history.replaceState({}, document.title, window.location.pathname);
        }, 5000);
    }
});