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
 * Recurring counters: add data-every="7d" (or 1w / 12h / raw seconds) and the
 * counter re-aims at the next occurrence instead of finishing — a weekly prompt
 * clock that resets itself. Add data-roll-caption="Next prompt drops in" and,
 * on the first roll, the [data-cd-caption] element is relabelled from its launch
 * wording to that text. An optional [data-cd-date] slot is kept in sync with the
 * active target as an unambiguous UTC date in YYYY-MM-DD format.
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

    // Optional recurring cadence. `data-every` ("7d", "1w", "12h", or a raw
    // number of seconds) rolls the target forward by that step whenever it
    // lapses, so a weekly prompt counter never dies — it re-aims at the next
    // drop. On the first roll, if `data-roll-caption` is set, the element marked
    // [data-cd-caption] is relabelled (e.g. "First prompt drops in" -> "Next
    // prompt drops in"). With no data-every, behaviour is unchanged: it finishes.
    function parseEvery(v) {
        if (!v) return 0;
        const m = String(v).trim().match(/^(\d+(?:\.\d+)?)\s*([smhdw]?)$/i);
        if (!m) return 0;
        const mult = { s: 1, m: 60, h: 3600, d: 86400, w: 604800 }[(m[2] || 's').toLowerCase()] || 1;
        const ms = parseFloat(m[1]) * mult * 1000;
        return ms > 0 ? Math.round(ms) : 0;
    }

    function bind(el) {
        let target = Date.parse(el.getAttribute('data-until'));
        if (isNaN(target)) return null;            // bad date — leave the markup as-is

        const everyMs = parseEvery(el.getAttribute('data-every'));
        let rolled = false;

        const slots = {
            d: el.querySelector('[data-cd="d"]'),
            h: el.querySelector('[data-cd="h"]'),
            m: el.querySelector('[data-cd="m"]'),
            s: el.querySelector('[data-cd="s"]'),
            date: el.querySelector('[data-cd-date]')
        };

        function showTargetDate() {
            if (!slots.date) return;
            const isoDate = new Date(target).toISOString().slice(0, 10);
            slots.date.textContent = isoDate;
            slots.date.setAttribute('datetime', isoDate);
        }

        function finish() {
            const done = el.getAttribute('data-done');
            if (done === null) return;             // no fallback copy — just stop
            const clock = el.querySelector('[data-cd-clock]') || el;
            clock.textContent = done;
            el.classList.add('is-done');
        }

        function roll() {
            const now = Date.now();
            while (target <= now) target += everyMs;   // re-aim at the next occurrence
            if (!rolled) {
                rolled = true;
                const cap  = el.querySelector('[data-cd-caption]');
                const next = el.getAttribute('data-roll-caption');
                if (cap && next !== null) cap.textContent = next;
                el.classList.add('is-rolled');
            }
        }

        function tick() {
            if (Date.now() >= target) {
                if (everyMs <= 0) { finish(); return false; }
                roll();                            // recurring: aim at the next drop
            }
            let diff = Math.floor((target - Date.now()) / 1000);
            if (diff < 0) diff = 0;

            const d = Math.floor(diff / 86400); diff -= d * 86400;
            const h = Math.floor(diff / 3600);  diff -= h * 3600;
            const m = Math.floor(diff / 60);
            const s = diff - m * 60;

            if (slots.d) slots.d.textContent = d;
            if (slots.h) slots.h.textContent = pad(h);
            if (slots.m) slots.m.textContent = pad(m);
            if (slots.s) slots.s.textContent = pad(s);
            showTargetDate();
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
