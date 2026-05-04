/**
 * SNAPSMACK - Storage Consent Engine
 *
 * Manages user consent for browser storage (localStorage, sessionStorage)
 * under EU ePrivacy Directive / GDPR. Exposes a global snapConsent object
 * that other engines check before writing to storage.
 *
 * The consent preference itself is stored in a first-party cookie
 * (snap_consent), which is explicitly exempt from consent requirements
 * under ePrivacy Article 5(3) as it is strictly necessary for
 * respecting the user's own privacy choice.
 *
 * States:
 *   null  — no decision yet (banner visible)
 *   '1'   — accepted (functional storage allowed)
 *   '0'   — declined (no storage writes)
 *
 * Public API:
 *   snapConsent.ok()      — returns true if storage is allowed
 *   snapConsent.accepted() — returns true if user explicitly accepted
 *   snapConsent.declined() — returns true if user explicitly declined
 *   snapConsent.pending()  — returns true if no decision yet
 */

(function () {
    'use strict';

    // --- Cookie helpers ---

    function getCookie(name) {
        var safeName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + safeName + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var d = new Date();
            d.setTime(d.getTime() + days * 86400000);
            expires = '; expires=' + d.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value)
            + expires
            + '; path=/; SameSite=Lax';
    }

    // --- State ---

    var COOKIE_NAME = 'snap_consent';
    var state = getCookie(COOKIE_NAME); // '1', '0', or null

    // --- Public API ---

    window.snapConsent = {
        ok: function () { return state === '1'; },
        accepted: function () { return state === '1'; },
        declined: function () { return state === '0'; },
        pending: function () { return state === null; },

        /** Called by the banner buttons */
        accept: function () {
            state = '1';
            setCookie(COOKIE_NAME, '1', 365);
            hideBanner();
        },
        decline: function () {
            state = '0';
            setCookie(COOKIE_NAME, '0', 365);
            clearAllStorage();
            hideBanner();
        }
    };

    // --- Storage wipe on decline ---

    function clearAllStorage() {
        try { localStorage.clear(); } catch (e) { /* silent */ }
        try { sessionStorage.clear(); } catch (e) { /* silent */ }
    }

    // --- Banner UI ---

    function hideBanner() {
        var banner = document.getElementById('snap-consent-banner');
        if (banner) {
            banner.style.opacity = '0';
            banner.style.transform = 'translateY(100%)';
            setTimeout(function () {
                if (banner.parentNode) banner.parentNode.removeChild(banner);
                // Signal to other engines that the consent decision is done.
                document.dispatchEvent(new CustomEvent('snap:consent-resolved'));
            }, 300);
        } else {
            document.dispatchEvent(new CustomEvent('snap:consent-resolved'));
        }
    }

    // Wire up buttons once DOM is ready
    document.addEventListener('DOMContentLoaded', function () {
        var acceptBtn  = document.getElementById('snap-consent-accept');
        var declineBtn = document.getElementById('snap-consent-decline');

        if (acceptBtn) {
            acceptBtn.addEventListener('click', function () {
                window.snapConsent.accept();
            });
        }
        if (declineBtn) {
            declineBtn.addEventListener('click', function () {
                window.snapConsent.decline();
            });
        }
    });

})();
// EOF
