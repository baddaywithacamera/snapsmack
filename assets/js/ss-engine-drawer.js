/**
 * SNAPSMACK - Dual Drawer Controller
 * Alpha v0.7.4d
 *
 * Rational Geo drawer engine: comments slide DOWN from below the header
 * (masthead section), info/caption slides UP from above the page footer
 * (magazine caption). Intercepts core nav-bar buttons so ss-engine-footer.js
 * is bypassed (no #footer element means it no-ops).
 *
 * Depends on DOM elements:
 *   #rg-info-drawer     — bottom-up caption drawer
 *   #rg-comments-drawer — top-down comments drawer
 *   #show-details       — nav bar info button
 *   #show-comments      — nav bar comments button
 */
document.addEventListener('DOMContentLoaded', function() {

    var infoDrawer = document.getElementById('rg-info-drawer');
    var commDrawer = document.getElementById('rg-comments-drawer');
    var btnInfo    = document.getElementById('show-details');
    var btnComm    = document.getElementById('show-comments');
    var ease       = 'max-height 0.4s cubic-bezier(.2,.9,.2,1)';

    function initDrawer(el) {
        if (!el) return;
        el.style.maxHeight = '0';
        el.style.overflow  = 'hidden';
        el.style.transition = ease;
    }

    function openDrawer(el) {
        if (!el) return;
        el.style.maxHeight = el.scrollHeight + 'px';
        var onEnd = function(ev) {
            if (ev.propertyName !== 'max-height') return;
            el.removeEventListener('transitionend', onEnd);
            el.style.maxHeight = 'none';
            el.style.overflow  = 'visible';
        };
        el.addEventListener('transitionend', onEnd);
    }

    function closeDrawer(el, cb) {
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

    function isOpen(el) {
        return el && (el.style.maxHeight !== '0' && el.style.maxHeight !== '0px');
    }

    initDrawer(infoDrawer);
    initDrawer(commDrawer);

    // --- Toggle info (bottom-up caption block) ---
    if (btnInfo) {
        btnInfo.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (isOpen(infoDrawer)) {
                closeDrawer(infoDrawer);
            } else {
                if (isOpen(commDrawer)) closeDrawer(commDrawer);
                openDrawer(infoDrawer);
                infoDrawer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }

    // --- Toggle comments (top-down masthead section) ---
    if (btnComm) {
        btnComm.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (isOpen(commDrawer)) {
                closeDrawer(commDrawer);
            } else {
                if (isOpen(infoDrawer)) closeDrawer(infoDrawer);
                openDrawer(commDrawer);
                commDrawer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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
        if (isOpen(infoDrawer)) closeDrawer(infoDrawer);
        if (isOpen(commDrawer)) closeDrawer(commDrawer);
    };

});
