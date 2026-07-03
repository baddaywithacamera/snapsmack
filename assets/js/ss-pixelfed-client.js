/*
 * SNAPSMACK — SMACKVERSE Pixelfed Client
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * Client-side shell for the faithful-Pixelfed admin page: tab switching and a
 * render layer that talks to the page's own AJAX endpoints. Colours are
 * inherited from the active admin skin via CSS (no theme logic here). Server
 * data arrives via data-* attributes on .sspf-app; no inline script anywhere
 * (skin/admin JS rule).
 */
(function () {
    'use strict';

    var app = document.querySelector('.sspf-app');
    if (!app) return;

    // ── Tab navigation ────────────────────────────────────────────────────────
    var navLinks = app.querySelectorAll('.sspf-nav a[data-panel]');
    var panels   = app.querySelectorAll('.sspf-panel');

    function activate(name) {
        navLinks.forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('data-panel') === name);
        });
        panels.forEach(function (p) {
            p.classList.toggle('active', p.getAttribute('data-panel') === name);
        });
        loadPanel(name);
    }

    navLinks.forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            activate(a.getAttribute('data-panel'));
        });
    });

    // ── Panel data loading ────────────────────────────────────────────────────
    // Each panel fetches from smack-pixelfed.php?ajax=<panel>. The backend feed/
    // notification/profile endpoints land in the next phase; until a panel has a
    // real endpoint it renders its static placeholder note (already in the DOM),
    // so the shell is fully usable now and lights up panel-by-panel.
    var WIRED = {};          // panel -> true once its endpoint exists
    var loaded = {};

    function loadPanel(name) {
        if (!WIRED[name] || loaded[name]) return;
        loaded[name] = true;
        var body = app.querySelector('.sspf-panel[data-panel="' + name + '"] .sspf-panel-body');
        if (!body) return;
        body.innerHTML = '<div class="sspf-note">Loading…</div>';
        fetch('smack-pixelfed.php?ajax=' + encodeURIComponent(name), { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) { render(name, body, data); })
            .catch(function () {
                loaded[name] = false;
                body.innerHTML = '<div class="sspf-note">Couldn’t reach the fediverse just now — try again.</div>';
            });
    }

    function render(name, body, data) {
        // Renderers land with their endpoints in the next phase.
        body.innerHTML = '<div class="sspf-note">Nothing here yet.</div>';
    }

    // ── Search (phase 1: placeholder; profile crawl wires next) ───────────────
    var search = app.querySelector('.sspf-search input');
    if (search) {
        search.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var q = search.value.trim();
            if (!q) return;
            activate('search');
            var body = app.querySelector('.sspf-panel[data-panel="search"] .sspf-panel-body');
            if (body) {
                body.innerHTML = '<div class="sspf-note">Profile lookup for <strong>' +
                    q.replace(/[<>&]/g, '') +
                    '</strong> comes online with the outbox-crawl endpoint (next phase).</div>';
            }
        });
    }

    // Open the default panel.
    activate((app.getAttribute('data-default-panel') || 'home'));
})();
// ===== SNAPSMACK EOF =====
