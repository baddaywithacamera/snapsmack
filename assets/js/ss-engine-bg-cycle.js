/**
 * SNAPSMACK - Background cycle crossfader (ss-engine-bg-cycle.js)
 *
 * For INSTANT CAMERA's "Cycle all" background mode. All background layers are
 * emitted inside #ic-bg-cycle (each an .ic-bg element, its own engine already
 * running). This engine shows one layer at a time and crossfades to the next
 * on a timer. The 2-second fade is CSS (transition on .ic-bg-cyclelayer); this
 * script only toggles the .cycle-active class. Interval = data-secs seconds.
 *
 * Honours prefers-reduced-motion: shows the first layer and does not rotate.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    function init() {
        var host = document.getElementById('ic-bg-cycle');
        if (!host) return;
        var layers = host.querySelectorAll('.ic-bg');
        if (!layers.length) return;

        // Show the first layer immediately.
        layers[0].classList.add('cycle-active');
        if (layers.length < 2) return;   // nothing to cycle through

        var reduced = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) return;             // hold on the first background

        var secs = parseInt(host.getAttribute('data-secs'), 10);
        if (isNaN(secs) || secs < 5) secs = 15;

        var i = 0;
        setInterval(function () {
            // Only advance while the tab is visible — no point crossfading to a
            // backgrounded tab, and it keeps the engines' work relevant.
            if (document.hidden) return;
            layers[i].classList.remove('cycle-active');
            i = (i + 1) % layers.length;
            layers[i].classList.add('cycle-active');
        }, secs * 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
// ===== SNAPSMACK EOF =====
