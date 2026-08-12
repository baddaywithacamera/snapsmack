/**
 * SNAPSMACK - Countdown Engine
 *
 * A tiny, dependency-free live countdown. Any element carrying the
 * `smack-countdown` class and a `data-until` ISO-8601 timestamp becomes a
 * ticking counter down to that moment.
 *
 *   <div class="smack-countdown pfd-countdown"
 *        data-until="2026-08-27T10:00:00Z"
 *        data-done="It's live.">
 *     <b data-cd="d">--</b> days
 *     <b data-cd="h">--</b> hrs
 *     <b data-cd="m">--</b> min
 *     <b data-cd="s">--</b> sec
 *   </div>
 *
 * The engine fills each [data-cd="d|h|m|s"] slot every second. When the target
 * passes, the slot region marked [data-cd-clock] (or, failing that, the whole
 * element's children) is replaced with the text in data-done. All display —
 * no interaction, no layout, no styling — so a skin owns the look and this
 * owns only the numbers. Skins ship zero JS; this is the shared engine they
 * point at.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!window._ssCountdownLoaded) {
window._ssCountdownLoaded = true;

function _ssCountdownInit() {

    const nodes = document.querySelectorAll('.smack-countdown[data-until]');
    if (!nodes.length) return;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function bind(el) {
        const target = Date.parse(el.getAttribute('data-until'));
        if (isNaN(target)) return null;            // bad date — leave the markup as-is

        const slots = {
            d: el.querySelector('[data-cd="d"]'),
            h: el.querySelector('[data-cd="h"]'),
            m: el.querySelector('[data-cd="m"]'),
            s: el.querySelector('[data-cd="s"]')
        };

        function finish() {
            const done = el.getAttribute('data-done');
            if (done === null) return;             // no fallback copy — just stop
            const clock = el.querySelector('[data-cd-clock]') || el;
            clock.textContent = done;
            el.classList.add('is-done');
        }

        function tick() {
            let diff = Math.floor((target - Date.now()) / 1000);
            if (diff <= 0) { finish(); return false; }

            const d = Math.floor(diff / 86400); diff -= d * 86400;
            const h = Math.floor(diff / 3600);  diff -= h * 3600;
            const m = Math.floor(diff / 60);
            const s = diff - m * 60;

            if (slots.d) slots.d.textContent = d;
            if (slots.h) slots.h.textContent = pad(h);
            if (slots.m) slots.m.textContent = pad(m);
            if (slots.s) slots.s.textContent = pad(s);
            return true;
        }

        return tick;
    }

    const ticks = [];
    nodes.forEach(function (el) {
        const t = bind(el);
        if (t && t()) ticks.push(t);              // seed immediately, keep the live ones
    });

    if (!ticks.length) return;

    const timer = setInterval(function () {
        for (let i = ticks.length - 1; i >= 0; i--) {
            if (ticks[i]() === false) ticks.splice(i, 1);
        }
        if (!ticks.length) clearInterval(timer);
    }, 1000);
}

// Scripts load at end of <body> — DOMContentLoaded may have already fired.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _ssCountdownInit);
} else {
    _ssCountdownInit();
}

} // end double-load guard
// ===== SNAPSMACK EOF =====
