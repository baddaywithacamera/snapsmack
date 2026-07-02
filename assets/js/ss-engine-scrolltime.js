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

    var IDLE_MS = 5000;        // idle after 5s with no activity
    var engaged = 0;           // accumulated engaged milliseconds
    var lastTick = null;       // timestamp of the previous accumulation tick
    var lastActivity = Date.now();
    var sent = false;

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
        if (sent) return;
        tick();
        var ms = Math.round(engaged);
        if (ms < 1000) return;   // ignore sub-second / no real engagement
        sent = true;             // set-once client-side; server also enforces set-once
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
