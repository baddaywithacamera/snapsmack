/**
 * SNAPSMACK - Footer Controller
 * Alpha v0.6
 *
 * Manages animated footer panes: info, comments, and help. Handles sliding
 * animations and exposes control methods to other engines.
 */

document.addEventListener('DOMContentLoaded', () => {
    const btnInfo = document.getElementById('show-details');
    const btnComm = document.getElementById('show-comments');
    const footer = document.getElementById('footer');

    const paneInfo = document.getElementById('pane-info');
    const paneComm = document.getElementById('pane-comments');
    const paneHelp = document.getElementById('pane-help');

    if (footer) {
        footer.style.display = 'none';
        footer.style.maxHeight = '0';
        footer.style.transition = 'max-height 0.32s cubic-bezier(.2,.9,.2,1)';

        let animating = false;

        // --- TOGGLE HANDLER ---
        // Determines which pane to show and manages open/close state
        const handleToggle = (target, e) => {
            if (e) e.preventDefault();
            if (animating) return;

            // Safety checks for pane existence
            if (target === 'comments' && !paneComm) return;
            if (target === 'info' && !paneInfo) return;
            if (target === 'help' && !paneHelp) return;

            const isClosed = footer.style.display === 'none';

            // Determine what is currently open
            let activePane = null;
            if (paneInfo && paneInfo.style.display !== 'none') activePane = 'info';
            else if (paneComm && paneComm.style.display !== 'none') activePane = 'comments';
            else if (paneHelp && paneHelp.style.display !== 'none') activePane = 'help';

            // Clicking the active pane closes the footer
            if (!isClosed && target === activePane) {
                closeFooter();
                return;
            }

            // Hide all panes first
            if (paneInfo) paneInfo.style.display = 'none';
            if (paneComm) paneComm.style.display = 'none';
            if (paneHelp) paneHelp.style.display = 'none';

            // Open the requested pane
            if (target === 'comments') paneComm.style.display = 'block';
            else if (target === 'info') paneInfo.style.display = 'block';
            else if (target === 'help') paneHelp.style.display = 'block';

            footer.offsetHeight; // Force reflow

            if (isClosed) openFooter();
            else {
                footer.style.maxHeight = footer.scrollHeight + 'px';
                footer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };

        // --- OPEN ANIMATION ---
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

        // --- CLOSE ANIMATION ---
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

        // Attach click listeners to navigation buttons
        if (btnInfo) btnInfo.addEventListener('click', (e) => handleToggle('info', e));
        if (btnComm) btnComm.addEventListener('click', (e) => handleToggle('comments', e));

        // Expose control functions to other engines via global bridge
        window.smackdown = window.smackdown || {};
        window.smackdown.toggleFooter = handleToggle;
        window.smackdown.closeFooter = closeFooter;
    }
});
