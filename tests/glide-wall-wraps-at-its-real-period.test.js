/**
 * SNAPSMACK GLIDE wall: the wrap period is the content's own period, measured,
 * never a hard-coded fraction of the track.
 *
 * SS-1093. The engine wrapped each row at scrollWidth / 4. That divisor was
 * correct only while the markup repeated each row four times, which is how the
 * wall was first built -- and the markup stopped doing it. The engine was not
 * changed with it and duplicates nothing of its own, so the modulus became a
 * quarter of a single-copy track: a row could travel through a quarter of its
 * own photographs and then snapped back to the start. Measured on the offline
 * fixture at 1920, roughly half of each long row could never be brought on
 * screen, and rows with a portrait-heavy share wrapped sooner than the others,
 * so no two rows agreed.
 *
 * THE TEST IS THE MEASUREMENT, which is the point. It does not read the engine
 * and agree with it; it steps the wrap frame by frame across several periods
 * and asserts the apparent content position never jumps by more than one frame
 * step. A hard-coded divisor cannot satisfy that, whatever the comment above it
 * claims.
 *
 * Runs the real engine source in a fake DOM through node:vm, the same way
 * tests/columns-layout.test.js runs the columns engine.
 *
 * Run:  node tests/glide-wall-wraps-at-its-real-period.test.js
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const ENGINE = path.join(__dirname, '..', 'assets', 'js', 'ss-engine-glide.js');

function loadEngine() {
    // No wall in this document, so the engine exposes its arithmetic and then
    // returns without touching anything. That is deliberate: the pure parts
    // have to be reachable without a page.
    const document = {
        readyState: 'complete',
        addEventListener() {},
        querySelector() { return null; },
        querySelectorAll() { return []; },
        documentElement: {}
    };
    const window = {
        addEventListener() {},
        getComputedStyle() { return { getPropertyValue() { return '0'; } }; },
        matchMedia() { return { matches: false }; },
        innerWidth: 1920
    };
    const source = fs.readFileSync(ENGINE, 'utf8');
    vm.runInNewContext(source, { window, document, console });
    assert.ok(window.SSGlide, 'the engine exposes nothing to test; SSGlide is missing');
    return window.SSGlide;
}

/** A stand-in tile that only has to know how wide it is. */
function fakeTile(width) {
    const attrs = {};
    return {
        offsetWidth: width,
        tabIndex: 0,
        setAttribute(k, v) { attrs[k] = v; },
        getAttribute(k) { return Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null; },
        cloneNode() { return fakeTile(width); }
    };
}

function fakeTrack(widths, gap) {
    const kids = widths.map(fakeTile);
    return {
        children: kids,
        get scrollWidth() {
            const sum = kids.reduce((a, k) => a + k.offsetWidth, 0);
            return kids.length ? sum + gap * (kids.length - 1) : 0;
        },
        appendChild(node) { kids.push(node); return node; },
        style: {}
    };
}

test('the period is the content period, gap included -- not the track width', () => {
    const g = loadEngine();
    // Three tiles of 375, 250 and 375 with a 12px gap. One copy MEASURES
    // 1000 + 2 gaps = 1024 wide, but the pattern only repeats after the gap
    // that follows the last tile, so the period is 1000 + 3 gaps = 1036.
    assert.equal(g.periodFrom([375, 250, 375], 12), 1036);
    assert.equal(g.periodFrom([375, 250, 375], 0), 1000);
    assert.equal(g.periodFrom([], 12), 0);
    // The distinction the original bug lived in: the measured width of one
    // copy is NOT the period.
    assert.notEqual(g.periodFrom([375, 250, 375], 12), 1024);
});

test('a quarter of the track is nowhere near the period -- the old divisor, pinned', () => {
    const g = loadEngine();
    // Row 0 of the offline fixture: 22 tiles, measured 7877px at 1920.
    const widths = Array.from({ length: 22 }, (_, i) => (i % 7 === 1 ? 250 : 375));
    const gap = 12;
    const oneCopy = widths.reduce((a, w) => a + w, 0) + gap * (widths.length - 1);
    const period = g.periodFrom(widths, gap);
    assert.ok(Math.abs(period - (oneCopy + gap)) < 0.001, 'period is not one copy plus one gap');
    // The old divisor gave a quarter of this, so three quarters of the row
    // could never be translated into view.
    assert.ok(oneCopy / 4 < period / 3,
        'a quarter of the track is not dramatically smaller than the period, so this test proves nothing');
});

test('the wrap stays inside one period and never skips', () => {
    const g = loadEngine();
    const period = 1036;
    for (const c of [0, -1, -500, -1035.9, -1036, -1036.1, -5000, 7, 12345]) {
        const x = g.wrap(c, period);
        assert.ok(x > -period - 0.001 && x <= 0.001, `wrap(${c}) left the period: ${x}`);
    }
    assert.equal(g.wrap(-100, 0), 0, 'a zero period must not divide by zero');
    assert.equal(g.wrap(-100, -5), 0, 'a negative period must not be trusted');
});

test('THE MEASUREMENT: frame by frame, the apparent position never jumps', () => {
    const g = loadEngine();
    const period = 1036;
    const step = 7.5;            // a plausible per-frame easing step
    let current = 0;
    let previous = g.wrap(current, period);
    // DISTANCE TRAVELLED, so it starts at zero. The first version seeded this
    // with the initial wrap value and came out exactly one period long -- a
    // fault in the bookkeeping, not in the engine, and worth saying so because
    // the obvious reaction to "off by one period" is to go and change the
    // engine's period.
    let unwrapped = 0;
    let worst = 0;
    let wraps = 0;
    for (let frame = 0; frame < 2000; frame++) {
        current -= step;
        const x = g.wrap(current, period);
        let delta = x - previous;
        // A wrap shows up as exactly one period of forward movement. Undo it
        // and the apparent motion must be the frame step and nothing else.
        if (delta > 0) { delta -= period; wraps++; }
        unwrapped += delta;
        worst = Math.max(worst, Math.abs(delta));
        previous = x;
    }
    assert.ok(wraps > 10, `the run did not cross enough periods to prove anything: ${wraps}`);
    // THE ASSERTION THAT MATTERS: no apparent jump bigger than one frame step.
    assert.ok(worst < step + 0.001,
        `apparent position jumped ${worst.toFixed(2)}px in one frame; a frame step is ${step}px`);
    // And the motion really did travel the whole distance, rather than sitting
    // in one quarter of the row going nowhere.
    assert.ok(Math.abs(unwrapped - (-step * 2000)) < 0.01,
        `travelled ${unwrapped.toFixed(1)}px where ${(-step * 2000).toFixed(1)}px was asked for`);
});

test('the track is filled until the wrap point is off screen, and clones are inert', () => {
    const g = loadEngine();
    const widths = [375, 250, 375];
    const gap = 12;
    const track = fakeTrack(widths, gap);
    const period = g.periodFrom(widths, gap);
    const originals = widths.length;

    g.fill(track, period, 1920, gap);

    assert.ok(track.scrollWidth >= period + 1920,
        `track is ${track.scrollWidth}px; it must cover a period plus the viewport (${period + 1920}px)`);
    assert.equal(track.children.length % originals, 0, 'the track holds a partial copy');
    assert.ok(track.children.length > originals, 'nothing was duplicated, so the wrap seam is visible');
    // The period must NOT change by filling. If it did, the wrap would move
    // every time the engine made the track longer.
    const widthsNow = track.children.slice(0, originals).map((k) => k.offsetWidth);
    assert.equal(g.periodFrom(widthsNow, gap), period, 'filling the track changed its period');

    // The 3 August design note left this open: whether duplicated links should
    // be hidden from assistive technology. They must be -- otherwise a screen
    // reader reads the same wall three times and tab stops at every copy.
    for (let i = originals; i < track.children.length; i++) {
        assert.equal(track.children[i].getAttribute('aria-hidden'), 'true',
            `copy ${i} is not hidden from assistive technology`);
        assert.equal(track.children[i].tabIndex, -1, `copy ${i} is still a tab stop`);
    }
    // An empty track must not spin forever trying to fill itself.
    const empty = fakeTrack([], gap);
    g.fill(empty, 0, 1920, gap);
    assert.equal(empty.children.length, 0, 'filling an empty track invented children');
});

test('no hard-coded divisor survives in the engine', () => {
    const source = fs.readFileSync(ENGINE, 'utf8')
        .split('\n').filter((l) => !l.trim().startsWith('*') && !l.trim().startsWith('//')).join('\n');
    assert.ok(!/scrollWidth\s*\/\s*\d/.test(source),
        'the engine still divides a measured width by a constant; that is the whole of SS-1093');
});
// ===== SNAPSMACK EOF =====
