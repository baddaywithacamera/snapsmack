/**
 * SNAPSMACK — Social Profile Dock Engine
 * ss-engine-social-dock.js
 *
 * 1. Hides the standalone download button when dock has absorbed it.
 * 2. For side-mounted docks (left-* / right-*): slides the dock
 *    off-screen while scrolling, brings it back 400ms after scroll stops.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var dock = document.querySelector('.social-dock');
        if (!dock) return;

        // --- ABSORB STANDALONE DOWNLOAD BUTTON ---
        // If the dock contains a download icon, hide the standalone button
        // that was rendered inside the skin layout
        var dockHasDownload = dock.querySelector('.snap-download-icon');
        if (dockHasDownload) {
            var standalone = document.querySelectorAll('.snap-download-btn');
            for (var i = 0; i < standalone.length; i++) {
                // Only hide buttons NOT inside the dock
                if (!dock.contains(standalone[i])) {
                    standalone[i].style.display = 'none';
                }
            }
        }

        // --- SCROLL BEHAVIOR (side-mounted only) ---
        var position = dock.getAttribute('data-dock-position') || '';

        if (position.indexOf('left-') !== 0 && position.indexOf('right-') !== 0) {
            return;
        }

        var scrollTimeout = null;
        var isHidden = false;

        window.addEventListener('scroll', function () {
            if (!isHidden) {
                dock.classList.add('dock-hidden');
                isHidden = true;
            }

            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(function () {
                dock.classList.remove('dock-hidden');
                isHidden = false;
            }, 400);
        }, { passive: true });
    });
})();
