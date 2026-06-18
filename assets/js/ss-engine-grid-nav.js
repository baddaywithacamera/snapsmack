/**
 * SNAPSMACK - Grid-family Sticky Nav (shared engine)
 *
 * Prefix-derived: works for any Grid-family skin (au-, tg-, …) with no fork.
 * Watches the profile header via IntersectionObserver and toggles
 * .profile-hidden on the <prefix>-sticky-nav so CSS can reveal the mini avatar.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
(function () {
    'use strict';

    function init() {
        var nav = document.querySelector('nav[class*="-sticky-nav"]');
        if (!nav) return;
        var m = nav.className.match(/(?:^|\s)([a-z]+)-sticky-nav(?:\s|$)/);
        if (!m) return;
        var P = m[1];

        var profile = document.querySelector('.' + P + '-profile');
        if (!profile) { nav.classList.add('profile-hidden'); return; }

        if (!('IntersectionObserver' in window)) {
            window.addEventListener('scroll', function () {
                nav.classList.toggle('profile-hidden', profile.getBoundingClientRect().bottom < 0);
            }, { passive: true });
            return;
        }

        new IntersectionObserver(function (entries) {
            nav.classList.toggle('profile-hidden', !entries[0].isIntersecting);
        }, { threshold: 0, rootMargin: '0px' }).observe(profile);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
