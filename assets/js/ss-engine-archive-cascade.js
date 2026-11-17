/**
 * SNAPSMACK - Archive Cascade Reveal Engine
 *
 * On an archive / hashtag page, the grid of photographs reveals in a quick
 * ordered wave -- top-to-bottom, left-to-right -- instead of appearing all at
 * once, so a freshly loaded archive settles in like a classier slideshow rather
 * than a slab. Sean's request, 2026-11-09.
 *
 * IT IS MOTION, so it is FAIL-SAFE OFF. The hidden-then-revealed state is applied
 * entirely by this engine, inline at runtime, and only after every check below
 * passes. A stylesheet never hides a photograph on its own: if the engine does
 * not run -- no JS, an error, motion declined, the wrong skin -- nothing is
 * touched and the archive is fully visible.
 *   - prefers-reduced-motion: reduce            -> no cascade, nothing hidden
 *   - window.ssMotion present AND not allowed    -> no cascade (motion-warning consent)
 *   - fewer than two grid items / no grid        -> no cascade
 *   - a bounded safety net reveals everything shortly after, no matter what.
 *
 * PHASE ONE: NEW HORIZON only. Core auto-loads this on every archive/hashtag
 * page (core/public-runtime.php); the SKIN_GATE below is the only thing limiting
 * it to NEW HORIZON. To roll the effect out to all masonry + thumb skins, widen
 * SKIN_GATE to the approved list, or empty it to mean "every skin". No skin file
 * changes either way -- the gate reads the body class the CMS already prints.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';
    if (window._ssArchiveCascadeLoaded) { return; }
    window._ssArchiveCascadeLoaded = true;

    // Phase one: NEW HORIZON only. Widen (add skin classes) or empty (all skins)
    // to roll out. An empty list means every skin.
    var SKIN_GATE = ['skin-new-horizon'];

    var STEP_MS = 45;          // delay added per item along the wave (quick)
    var MAX_TOTAL_MS = 700;    // cap: items past this reveal together, so a big
                               // grid never crawls down the page
    var SAFETY_MS = MAX_TOTAL_MS + 900;   // everything is visible by now, always
    var DURATION = '0.45s';
    var RISE = '10px';

    function bodyHas(cls) {
        var c = ' ' + ((document.body && document.body.className) || '') + ' ';
        return c.indexOf(' ' + cls + ' ') !== -1;
    }

    function motionAllowed() {
        try {
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return false;
            }
        } catch (e) { /* matchMedia unavailable: fall through */ }
        // The motion-warning engine's contract: honour an explicit NO. When that
        // engine is absent (window.ssMotion undefined) this is simply skipped.
        if (window.ssMotion && window.ssMotion.allowed === false) { return false; }
        return true;
    }

    function skinMatches() {
        if (!SKIN_GATE.length) { return true; }   // empty gate = every skin
        for (var i = 0; i < SKIN_GATE.length; i++) {
            if (bodyHas(SKIN_GATE[i])) { return true; }
        }
        return false;
    }

    function gridItems() {
        var grid = document.querySelector('.archive-grid, #justified-grid');
        if (!grid) { return []; }
        // One entry per tile: the thumb link / masonry item, not its inner <img>.
        var nodes = grid.querySelectorAll(
            '.thumb-link, .justified-item, .fsog-archive-item, .rg-archive-item'
        );
        return Array.prototype.slice.call(nodes);
    }

    function hide(el) {
        // Instant hide (no transition yet) so nothing animates OUT.
        el.style.opacity = '0';
        el.style.transform = 'translateY(' + RISE + ')';
    }

    function reveal(el) {
        if (el.dataset._ssCascadeShown) { return; }
        el.dataset._ssCascadeShown = '1';
        // Add the transition now, against the already-painted hidden state, so the
        // tile fades + rises IN.
        el.style.transition = 'opacity ' + DURATION + ' ease-out, transform ' + DURATION + ' ease-out';
        el.style.opacity = '1';
        el.style.transform = 'none';
    }

    function run(items) {
        // Order by visual position: row-major (top band, then left), so the wave
        // reads top-to-bottom / left-to-right regardless of DOM or masonry order.
        var ranked = items.map(function (el) {
            var r = el.getBoundingClientRect();
            return { el: el, row: Math.round(r.top / 8), left: r.left };
        });
        ranked.sort(function (a, b) { return (a.row - b.row) || (a.left - b.left); });

        ranked.forEach(function (p, i) {
            var delay = Math.min(i * STEP_MS, MAX_TOTAL_MS);
            window.setTimeout(function () { reveal(p.el); }, delay);
        });

        // Bounded safety net: whatever happens above, nothing stays hidden.
        window.setTimeout(function () {
            ranked.forEach(function (p) { reveal(p.el); });
        }, SAFETY_MS);
    }

    function start() {
        if (!document.body) { return; }
        if (!bodyHas('archive-page')) { return; }   // the grid page only
        if (!skinMatches()) { return; }             // phase one: NEW HORIZON
        if (!motionAllowed()) { return; }           // reduced motion / declined: leave visible

        var items = gridItems();
        if (items.length < 2) { return; }           // nothing worth cascading

        // Hide now (instant, inline), then let layout settle one frame before
        // measuring positions and starting the wave.
        items.forEach(hide);
        var go = function () { run(items); };
        if (window.requestAnimationFrame) { window.requestAnimationFrame(go); }
        else { window.setTimeout(go, 16); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
// ===== SNAPSMACK EOF =====
