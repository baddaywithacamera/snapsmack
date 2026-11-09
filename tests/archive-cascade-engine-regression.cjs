/**
 * Archive Cascade Reveal engine — behaviour contract (Sean, 2026-11-09).
 *
 * Runs ss-engine-archive-cascade.js in a minimal DOM/window sandbox and pins:
 *   - FAIL-SAFE: it hides nothing unless skin + archive-page + motion + >=2 items
 *     all pass (wrong skin / reduced motion / ssMotion declined / not archive /
 *     one tile => tiles are never touched, so a failure leaves the grid visible);
 *   - when eligible it hides then reveals every tile, ending all-visible;
 *   - the reveal order is row-major (top-to-bottom, left-to-right) by geometry,
 *     NOT DOM order;
 *   - the bounded safety net reveals everything even if an early step is skipped.
 *
 * Run:  node tests/archive-cascade-engine-regression.cjs
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SRC = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'ss-engine-archive-cascade.js'), 'utf8');

let fails = 0;
function ok(cond, msg) { if (cond) { console.log('ok: ' + msg); } else { fails++; console.error('FAIL: ' + msg); } }

function makeEl(rect, cls) {
    return {
        className: cls || 'thumb-link',
        style: {},
        dataset: {},
        getBoundingClientRect: function () { return rect; },
    };
}

// opts: { skin, archive, reduced, ssMotionAllowed (undefined=absent), items:[{top,left}] or count }
function run(opts) {
    opts = Object.assign({ skin: 'skin-new-horizon', archive: true, reduced: false, ssMotionAllowed: undefined, items: null }, opts);

    const itemDefs = opts.items || [
        // DOM order deliberately NOT visual order: C, A, D, B
        { id: 'C', top: 100, left: 0 },
        { id: 'A', top: 0, left: 0 },
        { id: 'D', top: 100, left: 100 },
        { id: 'B', top: 0, left: 100 },
    ];
    const els = itemDefs.map(function (d) {
        const el = makeEl({ top: d.top, left: d.left });
        el._id = d.id;
        return el;
    });
    const grid = { querySelectorAll: function () { return els; } };

    const timers = [];
    const rafs = [];
    const htmlClasses = [];
    const bodyClass = [opts.archive ? 'archive-page' : '', opts.skin || ''].join(' ');

    const win = {};
    win.matchMedia = function () { return { matches: !!opts.reduced }; };
    win.setTimeout = function (fn, delay) { timers.push({ fn: fn, delay: delay }); return timers.length; };
    win.requestAnimationFrame = function (fn) { rafs.push(fn); };
    if (opts.ssMotionAllowed !== undefined) { win.ssMotion = { allowed: opts.ssMotionAllowed }; }

    const doc = {
        readyState: 'complete',
        body: { className: bodyClass },
        documentElement: { classList: { add: function (c) { htmlClasses.push(c); } } },
        querySelector: function () { return (opts.hasGrid === false) ? null : grid; },
        addEventListener: function () {},
    };

    const sandbox = { window: win, document: doc };
    sandbox.window.document = doc;
    vm.runInNewContext(SRC, sandbox);

    // Flush the rAF (start -> hide -> rAF(run)), then the timers in delay order,
    // recording which tile is revealed at each step (opacity flips to '1').
    const revealOrder = [];
    function recordReveals() {
        els.forEach(function (el) {
            if (el.style.opacity === '1' && !el._recorded) { el._recorded = true; revealOrder.push(el._id); }
        });
    }
    rafs.forEach(function (fn) { fn(); });
    timers.slice().sort(function (a, b) { return a.delay - b.delay; }).forEach(function (t) { t.fn(); recordReveals(); });

    return { els: els, htmlClasses: htmlClasses, revealOrder: revealOrder, timers: timers };
}

// ---- FAIL-SAFE: not eligible => tiles never touched ----
function untouched(r) { return r.els.every(function (el) { return el.style.opacity === undefined || el.style.opacity === ''; }); }

ok(untouched(run({ skin: 'skin-galleria' })), 'wrong skin: tiles are never hidden');
ok(untouched(run({ archive: false })), 'not an archive page: tiles are never hidden');
ok(untouched(run({ reduced: true })), 'prefers-reduced-motion: tiles are never hidden');
ok(untouched(run({ ssMotionAllowed: false })), 'ssMotion declined: tiles are never hidden');
ok(untouched(run({ items: [{ id: 'A', top: 0, left: 0 }] })), 'single tile: nothing to cascade, left visible');

// ---- ssMotion allowed true behaves like present-and-ok ----
(function () {
    const r = run({ ssMotionAllowed: true });
    ok(r.els.every(function (el) { return el.style.opacity === '1'; }), 'ssMotion allowed: all tiles end visible');
})();

// ---- Eligible: hide then reveal all, in row-major order ----
(function () {
    const r = run({});
    ok(r.htmlClasses.length === 0, 'no html class is added (reveal is inline, not CSS-armed)');
    ok(r.els.every(function (el) { return el.style.opacity === '1' && el.style.transform === 'none'; }),
       'eligible: every tile ends fully revealed');
    ok(r.revealOrder.join('') === 'ABCD',
       'reveal order is row-major (top-to-bottom, left-to-right), not DOM order CADB — got ' + r.revealOrder.join(''));
    ok(r.els.every(function (el) { return /opacity/.test(el.style.transition || ''); }),
       'each revealed tile carries an opacity transition');
})();

// ---- Safety net: even if the staggered timers are dropped, the last timer reveals all ----
(function () {
    const r = run({});
    // the final (largest-delay) timer is the safety net; on its own it must reveal everything
    const last = r.timers.slice().sort(function (a, b) { return a.delay - b.delay; }).pop();
    // reset opacity to simulate the per-item timers never having fired
    r.els.forEach(function (el) { el.style.opacity = '0'; el.dataset._ssCascadeShown = ''; });
    last.fn();
    ok(r.els.every(function (el) { return el.style.opacity === '1'; }), 'safety net alone reveals every tile');
})();

if (fails) { console.error('\n' + fails + ' assertion(s) FAILED'); process.exit(1); }
console.log('\narchive cascade engine: all checks passed');
// ===== SNAPSMACK EOF =====
