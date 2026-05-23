/**
 * SNAPSMACK — Social Profile Dock Engine
 * ss-engine-social-dock.js
 *
 * 1. Hides the standalone download button when dock has absorbed it.
 * 2. Clamps the dock so it never overlaps the page header or bottom nav bar.
 * 3. For side-mounted docks (left-* / right-*): slides the dock off-screen
 *    while scrolling, brings it back 400ms after scroll stops.
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

        // --- BOUNDS CLAMPING ---
        // Keeps the dock within the content area between the header and the
        // bottom navigation bar, regardless of which skin is active.
        var EDGE_GAP = 12; // px clearance from header/footer edges

        function measureHeaderBottom() {
            // core/header.php always outputs .logo-area and .nav-menu.
            // Skin wrappers vary, so we take the greatest bottom edge of the
            // inner content elements that are guaranteed to be present.
            var candidates = ['.logo-area', '.nav-menu', '.ge-header',
                              '#tg-header', '#header', '.htbs-header'];
            var bottom = 0;
            for (var i = 0; i < candidates.length; i++) {
                var el = document.querySelector(candidates[i]);
                if (el) {
                    var b = el.getBoundingClientRect().bottom;
                    if (b > 0) { bottom = Math.max(bottom, b); }
                }
            }
            return bottom;
        }

        function measureFooterTop() {
            // navigation_bar.php renders <div class="nav-links"> as the
            // bottom navigation bar on single-image pages. Take the last one
            // in case there are also filter bars on archive pages.
            var vp = window.innerHeight;
            var navEls = document.querySelectorAll('.nav-links');
            if (!navEls.length) return vp;
            var last = navEls[navEls.length - 1];
            var rect = last.getBoundingClientRect();
            // Only constrain if the nav is actually visible in the viewport
            if (rect.top < vp && rect.height > 0) {
                return rect.top;
            }
            return vp;
        }

        function clampDock() {
            var pos = dock.getAttribute('data-dock-position') || '';
            var vp  = window.innerHeight;

            var headerBottom = measureHeaderBottom();
            var footerTop    = measureFooterTop();

            // Ensure sane values (header below footer would mean both scrolled away)
            if (headerBottom >= footerTop) {
                headerBottom = 0;
                footerTop    = vp;
            }

            var isTopAnchored = pos.indexOf('-top')    !== -1 || pos.indexOf('top-')    === 0;
            var isBotAnchored = pos.indexOf('-bottom')  !== -1 || pos.indexOf('bottom-') === 0;
            var isVertColumn  = pos.indexOf('left-')    === 0  || pos.indexOf('right-')  === 0;

            // Apply top constraint
            if (isTopAnchored) {
                dock.style.top = (headerBottom + EDGE_GAP) + 'px';
            }

            // Apply bottom constraint
            if (isBotAnchored) {
                dock.style.bottom = (vp - footerTop + EDGE_GAP) + 'px';
            }

            // For vertical column docks, also constrain max-height so a long
            // list of icons can't spill into the header or footer zones.
            if (isVertColumn) {
                var maxH = footerTop - headerBottom - EDGE_GAP * 2;
                dock.style.maxHeight = Math.max(48, maxH) + 'px';
            }
        }

        // Run immediately and on resize
        clampDock();
        window.addEventListener('resize', clampDock, { passive: true });

        // Re-clamp on scroll so non-sticky headers/footers are respected
        // as they enter/leave the viewport. Throttled via rAF.
        var rafPending = false;
        window.addEventListener('scroll', function () {
            if (!rafPending) {
                rafPending = true;
                requestAnimationFrame(function () {
                    clampDock();
                    rafPending = false;
                });
            }
        }, { passive: true });


        // --- SCROLL SLIDE ANIMATION (side-mounted only) ---
        var position = pos || dock.getAttribute('data-dock-position') || '';

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
