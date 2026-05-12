/**
 * SNAPSMACK — Sticky Header Engine
 * ss-engine-sticky-header.js
 *
 * Auto-detects the site header and pins it to the top on scroll.
 * Transparent glass-morphism kicks in the instant the header
 * sticks and stays transparent while scrolling — solid only on hover.
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

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
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
        var isStuck   = false;
        var releasing = false;  // true while fade-out transition is playing

        // --- SCROLL HANDLER ---
        function onScroll() {
            var scrollY = window.pageYOffset;

            if (scrollY > headerTop) {
                if (releasing) {
                    // User scrolled back down during fade-out — cancel release
                    releasing = false;
                    header.classList.add('ss-sticky-transparent');
                }
                if (!isStuck) {
                    isStuck = true;
                    header.classList.add('ss-sticky-active', 'ss-sticky-transparent');
                }
                // Stays transparent the whole time — solid only on :hover (CSS)
            } else {
                if (isStuck && !releasing) {
                    // Fade to opaque first, then unpin after transition ends
                    releasing = true;
                    header.classList.remove('ss-sticky-transparent');
                    header.addEventListener('transitionend', function handler() {
                        header.removeEventListener('transitionend', handler);
                        if (releasing) {
                            releasing = false;
                            isStuck   = false;
                            header.classList.remove('ss-sticky-active');
                        }
                    });
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
// ===== SNAPSMACK EOF =====
