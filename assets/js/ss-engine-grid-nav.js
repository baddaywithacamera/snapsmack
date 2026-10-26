/**
 * SNAPSMACK - Grid-family Sticky Nav (shared engine)
 *
 * Prefix-derived: works for any Grid-family skin (au-, tg-, …) with no fork.
 * Watches the profile header via IntersectionObserver and toggles
 * .profile-hidden on the <prefix>-sticky-nav so CSS can reveal the mini avatar.
 * Also reconnects validated per-image frame data to the family's established
 * CSS variables after the strict-skin boundary has made that data inert.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
(function () {
    'use strict';

    var SHADOWS = {
        '0': 'none',
        '1': '3px 3px 8px rgba(0,0,0,.20)',
        '2': '6px 6px 18px rgba(0,0,0,.40)',
        '3': '12px 12px 32px rgba(0,0,0,.60)'
    };

    function safePercent(value) {
        var match = String(value || '').match(/^(\d{1,3})%$/);
        if (!match) return '100%';
        return Math.max(1, Math.min(100, Number(match[1]))) + '%';
    }

    function safePixels(value) {
        var match = String(value || '').match(/^(\d{1,3})px$/);
        if (!match) return '0px';
        return Math.max(0, Math.min(100, Number(match[1]))) + 'px';
    }

    function safeColour(value, fallback) {
        value = String(value || '').trim();
        return /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
    }

    /*
     * The strict-skin boundary carries per-image presentation as inert data.
     * Reconnect it to the GRID family's original CSS variables here rather
     * than replacing the skin's established framing, sizing and shadow rules.
     */
    function applyFramePresentation(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var frames = [];
        if (scope.nodeType === 1 && scope.matches('[data-frame-size]')) frames.push(scope);
        Array.prototype.push.apply(frames, scope.querySelectorAll('[data-frame-size]'));
        frames.forEach(function (frame) {
            var size = safePercent(frame.dataset.frameSize);
            var border = safePixels(frame.dataset.frameBorderWidth);
            var colour = safeColour(frame.dataset.frameBorderColor, '#000000');
            var background = safeColour(frame.dataset.frameBackground, '#ffffff');
            var shadow = SHADOWS[String(frame.dataset.frameShadow || '0')] || 'none';
            frame.style.setProperty('--tile-img-size', size);
            frame.style.setProperty('--slide-img-size', size);
            frame.style.setProperty('--tile-border-w', border);
            frame.style.setProperty('--slide-border-w', border);
            frame.style.setProperty('--tile-border-c', colour);
            frame.style.setProperty('--slide-border-c', colour);
            frame.style.setProperty('--tile-bg', background);
            frame.style.setProperty('--slide-bg', background);
            frame.style.setProperty('--tile-shadow', shadow);
            frame.style.setProperty('--slide-shadow', shadow);
        });
    }

    function init() {
        applyFramePresentation(document);
        if ('MutationObserver' in window) {
            new MutationObserver(function (records) {
                records.forEach(function (record) {
                    record.addedNodes.forEach(function (node) {
                        if (node.nodeType === 1) applyFramePresentation(node);
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        }

        var nav = document.querySelector('.ss-grid-sticky-nav, nav[class*="-sticky-nav"]');
        if (!nav) return;
        if (nav.classList.contains('ss-grid-nav-always-visible')) return;
        var m = nav.className.match(/(?:^|\s)([a-z0-9_-]+)-sticky-nav(?:\s|$)/);
        if (!m) return;
        var P = m[1];

        var observerClass = nav.getAttribute('data-grid-nav-observer') || (P + '-profile');
        var profile = document.querySelector('.' + observerClass);
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
