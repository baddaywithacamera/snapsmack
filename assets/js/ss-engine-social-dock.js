/**
 * SNAPSMACK — Social Profile Dock Engine
 * ss-engine-social-dock.js
 *
 * For side-mounted docks (left-* / right-*): slides the dock off-screen
 * while the user is scrolling, then brings it back 400ms after scroll stops.
 * Corner-mounted docks stay put — no scroll behavior needed.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var dock = document.querySelector('.social-dock');
        if (!dock) return;

        var position = dock.getAttribute('data-dock-position') || '';

        // Only apply scroll behavior to side-mounted positions
        if (position.indexOf('left-') !== 0 && position.indexOf('right-') !== 0) {
            return;
        }

        var scrollTimeout = null;
        var isHidden = false;

        window.addEventListener('scroll', function () {
            // Hide on scroll
            if (!isHidden) {
                dock.classList.add('dock-hidden');
                isHidden = true;
            }

            // Clear existing timeout and set a new one
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(function () {
                dock.classList.remove('dock-hidden');
                isHidden = false;
            }, 400);
        }, { passive: true });
    });
})();
