/**
 * SNAPSMACK - Motion & Flashing Warning Engine (989D, GRAMOFSMACK MOTION WARNING)
 *
 * Shared engine, one copy, declared by a skin manifest (smack-motion-warning)
 * for every skin that auto-moves or flashes. It holds all motion until the
 * visitor chooses, warns before anything animates, and remembers the choice in
 * the browser only.
 *
 * THE CONTRACT every motion engine and animated stylesheet must honour:
 *   - window.ssMotion.allowed is false until the visitor allows motion (or a
 *     stored "allow" choice is read). A JS motion engine MUST check
 *     `window.ssMotion && window.ssMotion.allowed` before it animates, and treat
 *     a missing window.ssMotion as NOT allowed (fail safe). It listens for the
 *     'ssmotionchange' event on document to start/stop.
 *   - <html> carries class 'ss-motion-paused' while held and 'ss-motion-ok' once
 *     allowed, set the instant this script runs -- so CSS keyframes gated on
 *     `html.ss-motion-ok` stay still before the modal even paints.
 *
 * The choice lives in localStorage (ss_motion = allow|disable), a display
 * preference on this device -- exactly what the storage-consent banner already
 * describes ("remember display and navigation preferences on this device"), so
 * tnb.php's "no cookies" statement stays true. It is a strictly-necessary safety
 * choice about flashing content (ePrivacy Art. 5(3)), so it is written directly
 * and persists regardless of the analytics-consent decision; without it the
 * modal simply returns next visit, which is the safe default. No server ever
 * sees it. prefers-reduced-motion is treated as DISABLE with no modal at all.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    if (window.ssMotion) return; // load-once guard; one engine, never doubled

    var STORE_KEY = 'ss_motion';     // 'allow' | 'disable'
    var root = document.documentElement;

    function readChoice() {
        try { return window.localStorage.getItem(STORE_KEY); } catch (e) { return null; }
    }
    function writeChoice(value) {
        if (!persistOk) return; // consent declined: this page view only, never stored
        try { window.localStorage.setItem(STORE_KEY, value); } catch (e) { /* private mode: modal returns next visit, which is safe */ }
    }

    var reduceMotion = false;
    try {
        reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) { reduceMotion = false; }

    // Fail-safe from the first paint: motion is HELD until a decision releases
    // it. allowed stays false and <html> is paused the instant this runs.
    var allowed = false;
    var decided = false;
    var persistOk = true; // may the choice be remembered? (no when consent declined)

    function applyClass() {
        root.classList.toggle('ss-motion-ok', allowed);
        root.classList.toggle('ss-motion-paused', !allowed);
    }
    applyClass();

    // Storage-consent integration (ss-engine-consent). PM ruling 14:05 #6:
    //   declined -> default STILL, no modal, footer toggle for this page view only;
    //   pending  -> consent banner first, the motion modal waits for it;
    //   ok / no consent engine -> the choice is strictly-necessary and persists.
    function consentState() {
        var c = window.snapConsent;
        if (!c) return 'ok';
        if (c.declined && c.declined()) return 'declined';
        if (c.pending && c.pending()) return 'pending';
        return 'ok';
    }
    function decide() {
        var cs = consentState();
        if (cs === 'declined') {
            persistOk = false; allowed = false; decided = true;
            applyClass(); emitChange(); syncToggle();
            return;
        }
        persistOk = (cs === 'ok');
        if (reduceMotion) { allowed = false; decided = true; }
        else {
            var stored = readChoice();
            if (stored === 'allow') { allowed = true; decided = true; }
            else if (stored === 'disable') { allowed = false; decided = true; }
            else { allowed = false; decided = false; } // no decision yet -> ask
        }
        applyClass(); emitChange(); syncToggle();
    }

    function emitChange() {
        try {
            document.dispatchEvent(new CustomEvent('ssmotionchange',
                { detail: { allowed: allowed } }));
        } catch (e) {
            // Old engines: a plain Event still lets listeners re-read window.ssMotion.
            var ev = document.createEvent('Event');
            ev.initEvent('ssmotionchange', true, false);
            document.dispatchEvent(ev);
        }
    }

    function set(nextAllowed, persist) {
        allowed = !!nextAllowed;
        decided = true;
        applyClass();
        if (persist) writeChoice(allowed ? 'allow' : 'disable');
        emitChange();
        syncToggle();
    }

    window.ssMotion = {
        get allowed() { return allowed; },
        get decided() { return decided; },
        reduceMotion: reduceMotion,
        allow: function (persist) { set(true, persist !== false); },
        disable: function (persist) { set(false, persist !== false); },
        toggle: function () { set(!allowed, true); }
    };

    // --- The footer on/off toggle (persistent, reverses the choice any time) ---
    var toggleBtn = null;
    function syncToggle() {
        if (!toggleBtn) return;
        toggleBtn.setAttribute('aria-pressed', allowed ? 'true' : 'false');
        toggleBtn.textContent = allowed ? 'Motion: on' : 'Motion: off';
    }
    function buildToggle() {
        if (toggleBtn) return;
        var footer = document.getElementById('system-footer')
            || document.querySelector('footer')
            || document.body;
        if (!footer) return;
        var wrap = document.createElement('div');
        wrap.className = 'ss-motion-toggle-wrap';
        toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'ss-motion-toggle';
        toggleBtn.setAttribute('aria-pressed', allowed ? 'true' : 'false');
        toggleBtn.textContent = allowed ? 'Motion: on' : 'Motion: off';
        toggleBtn.addEventListener('click', function () { window.ssMotion.toggle(); });
        wrap.appendChild(toggleBtn);
        footer.appendChild(wrap);
    }

    // --- The warning modal (only when no decision has been made) ---
    var modal = null, lastFocus = null;
    function trapFocus(e) {
        if (e.key === 'Escape') { e.preventDefault(); choose(false); return; }
        if (e.key !== 'Tab' || !modal) return;
        var f = modal.querySelectorAll('button, input, [tabindex]:not([tabindex="-1"])');
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
    function closeModal() {
        if (!modal) return;
        document.removeEventListener('keydown', trapFocus, true);
        if (modal.parentNode) modal.parentNode.removeChild(modal);
        modal = null;
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }
    var remember = null;
    function choose(allow) {
        var persist = !remember || remember.checked; // "don't show again" (default on)
        closeModal();
        set(allow, persist);
    }
    function showModal() {
        if (modal || decided) return;
        lastFocus = document.activeElement;
        modal = document.createElement('div');
        modal.id = 'ss-motion-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'ss-motion-modal-title');

        var box = document.createElement('div');
        box.className = 'ss-motion-box';

        var h = document.createElement('h2');
        h.id = 'ss-motion-modal-title';
        h.className = 'ss-motion-title';
        h.textContent = 'This site contains movement and flashing images';
        box.appendChild(h);

        var p = document.createElement('p');
        p.className = 'ss-motion-text';
        p.textContent = 'Parts of this site animate on their own. Choose whether to allow that. '
            + 'You can change it any time with the motion toggle in the footer.';
        box.appendChild(p);

        var btns = document.createElement('div');
        btns.className = 'ss-motion-buttons';
        var allow = document.createElement('button');
        allow.type = 'button';
        allow.className = 'ss-motion-allow';
        allow.textContent = 'Allow motion';
        allow.addEventListener('click', function () { choose(true); });
        var disable = document.createElement('button');
        disable.type = 'button';
        disable.className = 'ss-motion-disable';
        disable.textContent = 'Keep it still';
        disable.addEventListener('click', function () { choose(false); });
        btns.appendChild(disable);
        btns.appendChild(allow);
        box.appendChild(btns);

        var rememberLabel = document.createElement('label');
        rememberLabel.className = 'ss-motion-remember';
        remember = document.createElement('input');
        remember.type = 'checkbox';
        remember.checked = true;
        rememberLabel.appendChild(remember);
        rememberLabel.appendChild(document.createTextNode(' Remember my choice on this device'));
        box.appendChild(rememberLabel);

        modal.appendChild(box);
        document.body.appendChild(modal);
        document.addEventListener('keydown', trapFocus, true);
        disable.focus(); // default focus on the safer option
    }

    function onReady() {
        buildToggle();
        if (consentState() === 'pending') {
            // The consent banner is up; let the visitor answer it first, then
            // decide and ask about motion only if still needed.
            document.addEventListener('snap:consent-resolved', function onres() {
                document.removeEventListener('snap:consent-resolved', onres);
                decide();
                if (!decided) showModal();
            });
            return;
        }
        decide();
        if (!decided) showModal();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();

// ===== SNAPSMACK EOF =====
