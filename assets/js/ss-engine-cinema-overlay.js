/**
 * SNAPSMACK - Chaplin Overlay Controller + Film Engine Init
 *
 * Loaded via manifest (smack-chaplin-overlay). Depends on smack-chaplin-film
 * being listed first in require_scripts so ChaplinFilm is defined.
 *
 * Responsibilities:
 *   1. Auto-init ChaplinFilm, reading scratchFreq from the data attribute
 *      set by skin-header.php on #rg-header (data-chaplin-scratch-freq).
 *   2. Manage the Chaplin intertitle overlay (#chap-comments-drawer):
 *      open/close, tab switching, backdrop + close-button wiring.
 *   3. Intercept #show-details / #show-comments in capture phase so
 *      Chaplin's full-screen overlay fires instead of the default drawer.
 *   4. Provide window.smackdown bridge for smack-keyboard.js hotkeys.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        /* ── 1. ChaplinFilm init ─────────────────────────────────────────── */
        if (typeof ChaplinFilm !== 'undefined') {
            var header     = document.getElementById('rg-header');
            var scratchFreq = header ? parseFloat(header.dataset.chaplinScratchFreq) : NaN;
            ChaplinFilm.init({
                scratchFreq : isNaN(scratchFreq) ? 0.008 : scratchFreq,
                flickerFreq : 0.012,
                jumpFreq    : 0.004,
                jumpMaxPx   : 4,
            });
        }

        /* ── 2. Overlay Controller ───────────────────────────────────────── */
        var overlay = document.getElementById('chap-comments-drawer');
        if (!overlay) return; /* Not a single-image page — nothing more to do */

        var backdrop = overlay.querySelector('.chap-overlay-backdrop');
        var closeBtn = overlay.querySelector('.chap-overlay-close');
        var tabs     = overlay.querySelectorAll('.chap-tab');
        var panes    = overlay.querySelectorAll('.chap-pane');

        function showPane(name) {
            for (var i = 0; i < tabs.length; i++) {
                tabs[i].classList.toggle('active', tabs[i].getAttribute('data-pane') === name);
            }
            for (var j = 0; j < panes.length; j++) {
                panes[j].classList.toggle('active', panes[j].id === 'chap-pane-' + name);
            }
        }

        function openOverlay(pane) {
            showPane(pane);
            overlay.classList.add('open');
            overlay.removeAttribute('aria-hidden');
        }

        function closeOverlay() {
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
        }

        function isOpen()    { return overlay.classList.contains('open'); }
        function activePane() {
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].classList.contains('active')) return tabs[i].getAttribute('data-pane');
            }
            return null;
        }

        for (var i = 0; i < tabs.length; i++) {
            tabs[i].addEventListener('click', function () { showPane(this.getAttribute('data-pane')); });
        }

        if (closeBtn) closeBtn.addEventListener('click', closeOverlay);
        if (backdrop)  backdrop.addEventListener('click', closeOverlay);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) closeOverlay();
        });

        /* ── 3. Capture-phase intercept of INFO / COMMENTS nav buttons ──── */
        document.addEventListener('click', function (e) {
            var t = e.target;
            if (!t) return;
            var infoBtn = t.id === 'show-details'  || (t.closest && t.closest('[id="show-details"]'));
            var commBtn = t.id === 'show-comments' || (t.closest && t.closest('[id="show-comments"]'));
            if (infoBtn) {
                e.preventDefault(); e.stopImmediatePropagation();
                if (isOpen() && activePane() === 'info') closeOverlay(); else openOverlay('info');
            } else if (commBtn) {
                e.preventDefault(); e.stopImmediatePropagation();
                if (isOpen() && activePane() === 'signals') closeOverlay(); else openOverlay('signals');
            }
        }, true /* capture */);

        /* ── 4. smackdown bridge for smack-keyboard.js ───────────────────── */
        window.smackdown = window.smackdown || {};
        window.smackdown.toggleFooter = function (target) {
            if (target === 'info') {
                if (isOpen() && activePane() === 'info') closeOverlay(); else openOverlay('info');
            } else if (target === 'comments') {
                if (isOpen() && activePane() === 'signals') closeOverlay(); else openOverlay('signals');
            }
        };
        window.smackdown.closeFooter = closeOverlay;
    });

}());
// ===== SNAPSMACK EOF =====
