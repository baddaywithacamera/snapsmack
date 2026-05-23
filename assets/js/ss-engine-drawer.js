/**
 * SNAPSMACK - Dual Drawer Controller
 *
 * Rational Geo drawer engine:
 *   - Comments slide DOWN from below the header (position:absolute overlay).
 *   - Info/caption is an in-flow footer below #infobox — same pattern as
 *     50-shades #footer. Shown with display:block + scrollIntoView; hidden
 *     with display:none. Page scroll is enabled (spacebar navigation still
 *     works because ss-engine-comms.js calls e.preventDefault() on Space).
 *
 * Depends on DOM elements:
 *   #rg-info-drawer     — in-flow info footer below #infobox
 *   #rg-comments-drawer — top-down comments overlay (position:absolute)
 *   #show-details       — nav bar info button
 *   #show-comments      — nav bar comments button
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


document.addEventListener('DOMContentLoaded', function() {

    var infoDrawer = document.getElementById('rg-info-drawer');
    var commDrawer = document.getElementById('rg-comments-drawer');
    var btnInfo    = document.getElementById('show-details');
    var btnComm    = document.getElementById('show-comments');
    var ease       = 'max-height 0.4s cubic-bezier(.2,.9,.2,1)';

    // --- Comments drawer (position:absolute overlay — max-height animation) ---

    function initCommDrawer(el) {
        if (!el) return;
        el.style.maxHeight = '0';
        el.style.overflow  = 'hidden';
        el.style.transition = ease;
    }

    function openCommDrawer(el) {
        if (!el) return;
        el.style.maxHeight = el.scrollHeight + 'px';
        var onEnd = function(ev) {
            if (ev.propertyName !== 'max-height') return;
            el.removeEventListener('transitionend', onEnd);
            el.style.maxHeight = 'none';
            el.style.overflow  = 'auto';
        };
        el.addEventListener('transitionend', onEnd);
    }

    function closeCommDrawer(el, cb) {
        if (!el) return;
        el.style.overflow  = 'hidden';
        el.style.maxHeight = el.scrollHeight + 'px';
        el.offsetHeight; // reflow
        el.style.maxHeight = '0';
        var onEnd = function(ev) {
            if (ev.propertyName !== 'max-height') return;
            el.removeEventListener('transitionend', onEnd);
            if (cb) cb();
        };
        el.addEventListener('transitionend', onEnd);
    }

    function isCommOpen(el) {
        return el && (el.style.maxHeight !== '0' && el.style.maxHeight !== '0px');
    }

    // --- Info footer (in-flow below #infobox — display toggle + scrollIntoView) ---

    function isInfoOpen(el) {
        return el && el.style.display === 'block';
    }

    function openInfoFooter(el) {
        if (!el) return;
        el.style.display = 'block';
        requestAnimationFrame(function() {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    function closeInfoFooter(el) {
        if (!el) return;
        el.style.display = 'none';
    }

    // --- Initialise ---
    initCommDrawer(commDrawer);
    // infoDrawer starts display:none via CSS — no JS init needed

    // --- Toggle info (in-flow footer below infobox) ---
    if (btnInfo) {
        btnInfo.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (isInfoOpen(infoDrawer)) {
                closeInfoFooter(infoDrawer);
            } else {
                if (isCommOpen(commDrawer)) closeCommDrawer(commDrawer);
                openInfoFooter(infoDrawer);
            }
        });
    }

    // --- Toggle comments (top-down overlay) ---
    if (btnComm) {
        btnComm.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (isCommOpen(commDrawer)) {
                closeCommDrawer(commDrawer);
            } else {
                if (isInfoOpen(infoDrawer)) closeInfoFooter(infoDrawer);
                openCommDrawer(commDrawer);
            }
        });
    }

    // --- Smackdown API bridge (keyboard engine compat) ---
    window.smackdown = window.smackdown || {};
    window.smackdown.toggleFooter = function(target, e) {
        if (e) e.preventDefault();
        if (target === 'info' && btnInfo) btnInfo.click();
        else if (target === 'comments' && btnComm) btnComm.click();
    };
    window.smackdown.closeFooter = function() {
        closeInfoFooter(infoDrawer);
        if (isCommOpen(commDrawer)) closeCommDrawer(commDrawer);
    };

});
// ===== SNAPSMACK EOF =====
