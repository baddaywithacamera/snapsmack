/**
 * SNAPSMACK — 52 PICKUP Interaction Layer
 * ss-engine-52-pickup.js
 *
 * The interaction skin layer built on top of ORGANIZED MAYHEM
 * (ss-engine-organized-mayhem.js). Spec: _spec/52-pickup-spec-v0.1.docx.
 *
 * Handles, on any page that runs the tabletop:
 *   • Hover lift (spec §4.1)  — scale + deepen shadow + raise z; rotation kept.
 *   • Click to expand (§4.2)  — scale up, rotate upright, fade, then navigate
 *                               to the solo post ("picking up a card to read").
 *   • Ghost chrome (§3.2)     — nav appears on top-edge hover, footer on
 *                               bottom-edge hover; hidden otherwise.
 *   • ESC return (§4.3)       — from a solo post, ESC returns to the tabletop
 *                               at the exact prior viewport position.
 *
 * Independently available to any skin under SPL share-alike terms; declared in
 * the manifest as require_scripts => ['smack-52-pickup']. No inline JS in skins.
 *
 * MARKUP CONTRACT (set by the consuming skin)
 *   [data-mayhem]          the tabletop container (from Organized Mayhem).
 *   [data-ghost-nav]       nav element to reveal on top-edge hover.
 *   [data-ghost-footer]    footer element to reveal on bottom-edge hover.
 *   [data-52-return="URL"] present on solo/inner pages; ESC navigates to URL.
 *
 * View persistence uses window.OrganizedMayhem.getView()/setView() plus
 * sessionStorage — see ss-engine-organized-mayhem.js. Degrades gracefully:
 * if the API/storage is unavailable, ESC still returns (engine just recenters).
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    function fault(where, err) {
        try { if (window.console && console.error) console.error('[52-pickup] ' + where, err); } catch (e) {}
    }

    try { init(); } catch (e) { fault('init', e); }

    function init() {
        setupGhostChrome();
        setupTabletop();
        setupEscReturn();
    }

    // ── Ghost chrome (spec §3.2) ─────────────────────────────────────────
    function setupGhostChrome() {
        var nav = document.querySelector('[data-ghost-nav]');
        var footer = document.querySelector('[data-ghost-footer]');
        if (!nav && !footer) return;
        var EDGE = 90;

        document.addEventListener('mousemove', function (e) {
            var h = window.innerHeight;
            if (nav) nav.classList.toggle('om-chrome-show', e.clientY <= EDGE);
            if (footer) footer.classList.toggle('om-chrome-show', e.clientY >= h - EDGE);
        }, { passive: true });

        // Touch has no hover: tap near an edge flashes that chrome briefly.
        document.addEventListener('touchstart', function (e) {
            var t = e.touches[0]; if (!t) return;
            var h = window.innerHeight;
            if (t.clientY <= EDGE && nav) flash(nav);
            if (t.clientY >= h - EDGE && footer) flash(footer);
        }, { passive: true });

        function flash(el) {
            el.classList.add('om-chrome-show');
            clearTimeout(el._omHide);
            el._omHide = setTimeout(function () { el.classList.remove('om-chrome-show'); }, 2500);
        }
    }

    // ── Hover lift + click-to-expand (spec §4) ───────────────────────────
    function setupTabletop() {
        var stage = document.querySelector('[data-mayhem]');
        if (!stage) return;

        var topZ = 100000;           // lift stacking, above normal tile z
        var downX = 0, downY = 0, moved = false;

        // Delegated hover — tiles mount/unmount as you pan.
        stage.addEventListener('mouseover', function (e) {
            var card = closestCard(e.target); if (!card) return;
            card.classList.add('om-lift');
            card.style.zIndex = (++topZ);   // bump to top of the stack (§4.1)
        });
        stage.addEventListener('mouseout', function (e) {
            var card = closestCard(e.target); if (!card) return;
            card.classList.remove('om-lift');
        });

        // Distinguish a click from a pan-drag (Organized Mayhem owns the pan).
        stage.addEventListener('mousedown', function (e) { downX = e.clientX; downY = e.clientY; moved = false; });
        stage.addEventListener('mousemove', function (e) {
            if (Math.abs(e.clientX - downX) + Math.abs(e.clientY - downY) > 6) moved = true;
        });
        stage.addEventListener('click', function (e) {
            var card = closestCard(e.target); if (!card) return;
            e.preventDefault();
            if (moved) return;       // it was a pan, not a pick-up
            expandAndGo(card);
        });
    }

    function closestCard(el) { return (el && el.closest) ? el.closest('.om-card') : null; }

    // The signature moment: lift the card, rotate it upright, scale it, fade,
    // then load the solo post (spec §4.2). Directional, not physically tracked.
    function expandAndGo(card) {
        var href = card.getAttribute('href') || '';
        saveView();

        // Keep the card's translate, drop its rotation to upright, scale + fade.
        var tr = card.style.transform || '';
        var m = tr.match(/translate3d\([^)]*\)/);
        var base = m ? m[0] : '';
        card.classList.add('om-expand');
        card.style.transformOrigin = 'center center';
        card.style.transform = base + ' rotate(0deg) scale(2.4)';
        card.style.opacity = '0';

        var done = false;
        function go() { if (done) return; done = true; if (href) window.location.assign(href); }
        card.addEventListener('transitionend', go);
        setTimeout(go, 650);         // fallback if transitionend doesn't fire
    }

    function saveView() {
        try {
            if (window.OrganizedMayhem && OrganizedMayhem.getView) {
                sessionStorage.setItem('om-view', JSON.stringify(OrganizedMayhem.getView()));
                sessionStorage.setItem('om-restore', '1');   // consumed on the next landing load
            }
        } catch (e) { fault('saveView', e); }
    }

    // ── ESC return (spec §4.3) ───────────────────────────────────────────
    function setupEscReturn() {
        var ret = document.querySelector('[data-52-return]');
        if (!ret) return;
        var url = ret.getAttribute('data-52-return') || '';
        if (!url) return;
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) { e.preventDefault(); window.location.assign(url); }
        });
    }

})();
// ===== SNAPSMACK EOF =====
