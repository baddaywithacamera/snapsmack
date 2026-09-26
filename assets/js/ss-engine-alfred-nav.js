/**
 * SNAPSMACK - Alfred skin mobile navigation toggle
 * v1.0.0
 *
 * Wires the .nav-toggle hamburger button to show/hide .mobile-navigation.
 * CSS handles the X animation via .active class on the button.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('.nav-toggle');
        var drawer = document.querySelector('.mobile-navigation');
        if (!toggle || !drawer) return;

        toggle.addEventListener('click', function () {
            var open = toggle.classList.toggle('active');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            drawer.style.display = open ? 'block' : 'none';
        });

        // Escape closes the drawer and returns focus to the menu button.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && toggle.classList.contains('active')) {
                toggle.classList.remove('active');
                toggle.setAttribute('aria-expanded', 'false');
                drawer.style.display = 'none';
                toggle.focus();
            }
        });

        // Close drawer on outside click
        document.addEventListener('click', function (e) {
            if (!toggle.contains(e.target) && !drawer.contains(e.target)) {
                if (toggle.classList.contains('active')) {
                    toggle.classList.remove('active');
                    toggle.setAttribute('aria-expanded', 'false');
                    drawer.style.display = 'none';
                }
            }
        });
    });
}());
// ===== SNAPSMACK EOF =====
