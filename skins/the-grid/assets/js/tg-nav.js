/**
 * SNAPSMACK - The Grid Sticky Nav
 *
 * Watches the profile header via IntersectionObserver.
 * Adds .profile-hidden to .tg-sticky-nav when the profile
 * scrolls out of view — CSS uses this to show the mini avatar.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    function initStickyNav() {
        var profile  = document.querySelector('.tg-profile');
        var nav      = document.querySelector('.tg-sticky-nav');

        if (!nav) return;

        if (!profile) {
            // No profile header on this page — treat nav as always-scrolled
            nav.classList.add('profile-hidden');
            return;
        }

        if (!('IntersectionObserver' in window)) {
            // Fallback: use scroll event
            var lastScrollY = 0;
            window.addEventListener('scroll', function () {
                var rect = profile.getBoundingClientRect();
                nav.classList.toggle('profile-hidden', rect.bottom < 0);
            }, { passive: true });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            nav.classList.toggle('profile-hidden', !entries[0].isIntersecting);
        }, {
            threshold: 0,
            rootMargin: '0px'
        });

        observer.observe(profile);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initStickyNav);
    } else {
        initStickyNav();
    }

})();
// ===== SNAPSMACK EOF =====
