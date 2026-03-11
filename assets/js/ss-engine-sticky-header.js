/**
 * SNAPSMACK — Sticky Header Engine
 * ss-engine-sticky-header.js
 *
 * Auto-detects the site header and pins it to the top on scroll.
 * While scrolling the header goes transparent (glass-morphism);
 * hovering the header snaps it back to full opacity.
 *
 * Detection order:
 *   1. [data-sticky-header]    explicit opt-in attribute
 *   2. #header                 standard skin pattern
 *   3. <header>                first semantic header element
 *
 * Skip criteria:
 *   - Already position:fixed or position:sticky (e.g. pocket-rocket, photogram)
 *   - data-sticky-header="false"
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // --- DETECT HEADER ELEMENT ---
        var header =
            document.querySelector('[data-sticky-header]') ||
            document.getElementById('header') ||
            document.querySelector('header');

        if (!header) return;

        // Skip if explicitly opted out
        if (header.getAttribute('data-sticky-header') === 'false') return;

        // Skip if the header is already fixed/sticky (another skin handles it)
        var computed = window.getComputedStyle(header);
        if (computed.position === 'fixed' || computed.position === 'sticky') return;

        // --- MEASURE NATURAL POSITION ---
        var headerRect   = header.getBoundingClientRect();
        var headerTop    = headerRect.top + window.pageYOffset;
        var headerHeight = header.offsetHeight;

        // --- CREATE SPACER (prevents content jump when header goes fixed) ---
        var spacer = document.createElement('div');
        spacer.className = 'ss-sticky-spacer';
        spacer.style.height = headerHeight + 'px';
        header.parentNode.insertBefore(spacer, header.nextSibling);

        // --- STATE ---
        var isStuck  = false;
        var scrollTimeout = null;
        var idleDelay = 600; // ms after scroll stops → go transparent

        // --- SCROLL HANDLER ---
        function onScroll() {
            var scrollY = window.pageYOffset;

            if (scrollY > headerTop) {
                if (!isStuck) {
                    isStuck = true;
                    header.classList.add('ss-sticky-active');
                    // Brief delay before transparency kicks in
                    clearTimeout(scrollTimeout);
                    scrollTimeout = setTimeout(function () {
                        header.classList.add('ss-sticky-transparent');
                    }, idleDelay);
                } else {
                    // Still scrolling — reset idle timer
                    header.classList.remove('ss-sticky-transparent');
                    clearTimeout(scrollTimeout);
                    scrollTimeout = setTimeout(function () {
                        header.classList.add('ss-sticky-transparent');
                    }, idleDelay);
                }
            } else {
                if (isStuck) {
                    isStuck = false;
                    clearTimeout(scrollTimeout);
                    header.classList.remove('ss-sticky-active', 'ss-sticky-transparent');
                }
            }
        }

        window.addEventListener('scroll', onScroll, { passive: true });

        // --- RECALCULATE ON RESIZE (header height might change) ---
        var resizeTimeout = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(function () {
                // Only recalculate when not stuck (natural position)
                if (!isStuck) {
                    headerRect   = header.getBoundingClientRect();
                    headerTop    = headerRect.top + window.pageYOffset;
                    headerHeight = header.offsetHeight;
                    spacer.style.height = headerHeight + 'px';
                }
            }, 200);
        }, { passive: true });
    });
})();
