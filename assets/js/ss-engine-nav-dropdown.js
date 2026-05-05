/**
 * ss-engine-nav-dropdown.js
 * SnapSmack public navigation dropdown engine.
 *
 * Activates only when .nav-has-children exists in the DOM (i.e. the
 * current menu has at least one parent item with children). Handles
 * mouse hover automatically via CSS; this script adds tap/click toggle
 * for mobile and keyboard (Enter/Space/Escape) accessibility.
 *
 * Loaded globally via core/meta.php. Zero dependencies.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    // Nothing to do if no parent items exist in this page's nav.
    if (!document.querySelector('.nav-has-children')) return;

    var parents = document.querySelectorAll('.nav-has-children');

    parents.forEach(function (parent) {
        var link     = parent.querySelector(':scope > a');
        var submenu  = parent.querySelector('.nav-submenu');
        if (!link || !submenu) return;

        // Ensure the caret link is announced as a menu button to screen readers.
        link.setAttribute('aria-haspopup', 'true');
        link.setAttribute('aria-expanded', 'false');

        function openMenu() {
            // Close sibling menus only — do not close ancestors or descendants.
            parents.forEach(function (p) {
                if (p !== parent && !parent.contains(p) && !p.contains(parent)) closeMenu(p);
            });
            parent.classList.add('open');
            link.setAttribute('aria-expanded', 'true');
        }

        function closeMenu(target) {
            target = target || parent;
            target.classList.remove('open');
            var l = target.querySelector(':scope > a');
            if (l) l.setAttribute('aria-expanded', 'false');
        }

        // Tap/click toggle (primary mechanism on touch devices).
        link.addEventListener('click', function (e) {
            // Only intercept if the item has children (it always does here)
            // and only on narrow viewports or when the submenu is hidden.
            var submenuVisible = parent.classList.contains('open');
            if (submenuVisible) {
                closeMenu();
            } else {
                e.preventDefault();
                openMenu();
            }
        });

        // Keyboard: Enter/Space to open, Escape to close.
        link.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                parent.classList.contains('open') ? closeMenu() : openMenu();
            }
            if (e.key === 'Escape') closeMenu();
        });
    });

    // Click outside → close all.
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.nav-has-children')) {
            parents.forEach(function (p) {
                p.classList.remove('open');
                var l = p.querySelector(':scope > a');
                if (l) l.setAttribute('aria-expanded', 'false');
            });
        }
    });

})();

// ===== SNAPSMACK EOF =====
