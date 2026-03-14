/**
 * SNAPSMACK - Footer Controller
 * Alpha v0.7.3
 *
 * Manages animated footer panes: info, comments, and help. Guards against
 * double-loading (the script can appear twice via footer_injection_scripts).
 * Close animation uses max-height transition with a safety timeout so the
 * animating lock can never get permanently stuck.
 */

if (!window._ssFooterLoaded) {
window._ssFooterLoaded = true;

document.addEventListener('DOMContentLoaded', () => {
    const btnInfo = document.getElementById('show-details');
    const btnComm = document.getElementById('show-comments');
    const footer = document.getElementById('footer');

    const paneInfo = document.getElementById('pane-info');
    const paneComm = document.getElementById('pane-comments');
    const paneHelp = document.getElementById('pane-help');

    if (footer) {
        footer.style.display = 'none';

        let closing = false;
        const CLOSE_MS = 320;

        // --- SCROLL HELPER ---
        const scrollToDrawer = () => {
            requestAnimationFrame(() => {
                footer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        };

        // --- TOGGLE HANDLER ---
        const handleToggle = (target, e) => {
            if (e) e.preventDefault();
            if (closing) return;

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

            // Show footer and scroll into view
            footer.style.display = 'block';
            scrollToDrawer();
        };

        // --- CLOSE (animated slide-up) ---
        const closeFooter = () => {
            if (footer.style.display === 'none' || closing) return;
            closing = true;

            // Snapshot current height, enable transition, collapse to 0
            footer.style.overflow = 'hidden';
            footer.style.maxHeight = footer.scrollHeight + 'px';
            footer.style.transition = 'max-height ' + CLOSE_MS + 'ms cubic-bezier(.2,.9,.2,1)';
            footer.offsetHeight; // force reflow
            footer.style.maxHeight = '0';

            // Clean up after transition (or safety timeout)
            const finish = () => {
                footer.style.display = 'none';
                footer.style.transition = '';
                footer.style.maxHeight = '';
                footer.style.overflow = '';
                closing = false;
            };

            const safety = setTimeout(finish, CLOSE_MS + 50);

            footer.addEventListener('transitionend', function onClose(ev) {
                if (ev.propertyName === 'max-height') {
                    clearTimeout(safety);
                    footer.removeEventListener('transitionend', onClose);
                    finish();
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

} // end double-load guard
