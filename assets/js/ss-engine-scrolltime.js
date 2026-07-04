/**
 * SNAPSMACK - Scroll Time engine (ss-engine-scrolltime.js)
 *
 * Measures ENGAGED active dwell time on GRAMOFSMACK landing feeds and
 * SMACKONEOUT archive pages — the read for visitors who browse tiles without
 * clicking through. The clock only advances while the tab is visible AND the
 * visitor has been active (scroll / move / touch) within IDLE_MS; it pauses on
 * tab-hidden or idle, so backgrounded and abandoned tabs don't inflate the
 * number. Reports once via navigator.sendBeacon() on page-leave.
 *
 * Config comes from this script tag's own data-* attributes (no inline JS):
 *   <script id="ss-scrolltime" data-hit="<snap_stats.id>" data-endpoint="...">
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
(function () {
    'use strict';

    var cfg = document.getElementById('ss-scrolltime');
    if (!cfg) return;

    var hit      = parseInt(cfg.getAttribute('data-hit'), 10) || 0;
    var endpoint = cfg.getAttribute('data-endpoint') || '';
    if (!hit || !endpoint) return;

    // Idle grace: how long stillness still counts as engaged reading after the
    // last scroll/move. On a PHOTO blog, lingering 30-60s on one image without
    // touching the trackpad IS the engaged read we want to capture — a 5s cutoff
    // (the original value) discarded almost all of it, counting only active
    // trackpad motion and collapsing a 10-minute browse to seconds. 30s captures
    // contemplative viewing while still self-limiting an abandoned tab (it counts
    // at most IDLE_MS past the last input, then freezes until activity resumes).
    var IDLE_MS = 30000;
    var engaged = 0;           // accumulated engaged milliseconds
    var lastTick = null;       // timestamp of the previous accumulation tick
    var lastActivity = Date.now();

    function isActive() {
        return document.visibilityState === 'visible' &&
               (Date.now() - lastActivity) < IDLE_MS;
    }

    function tick() {
        var now = Date.now();
        if (lastTick !== null && isActive()) {
            engaged += now - lastTick;
        }
        lastTick = now;
    }

    setInterval(tick, 1000);

    function bump() {
        lastActivity = Date.now();
        if (lastTick === null) lastTick = Date.now();
    }
    ['scroll', 'mousemove', 'touchstart', 'touchmove', 'keydown', 'click', 'wheel']
        .forEach(function (ev) {
            window.addEventListener(ev, bump, { passive: true });
        });

    function send() {
        tick();
        var ms = Math.round(engaged);
        if (ms < 1000) return;   // ignore sub-second / no real engagement
        // No client set-once: report the LATEST engaged time on every hidden /
        // pagehide / unload. The server keeps the MAX, so a mid-visit tab switch
        // (or phone lock) no longer freezes the number at a few seconds.
        var payload = JSON.stringify({ hit: hit, ms: ms });
        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, new Blob([payload], { type: 'application/json' }));
        } else {
            try {
                var x = new XMLHttpRequest();
                x.open('POST', endpoint, true);
                x.setRequestHeader('Content-Type', 'application/json');
                x.send(payload);
            } catch (e) { /* best-effort */ }
        }
    }

    document.addEventListener('visibilitychange', function () {
        tick();
        if (document.visibilityState === 'hidden') {
            send();
        } else {
            lastTick = Date.now();
        }
    });
    window.addEventListener('pagehide', send);
    window.addEventListener('beforeunload', send);
})();
// ===== SNAPSMACK EOF =====
