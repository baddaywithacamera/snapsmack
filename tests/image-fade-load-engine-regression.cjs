/**
 * Image Fade-Load engine — behaviour contract.
 *
 * Sean, 2026-11-10, on a NEW HORIZON single-photo page: images "blink in
 * rapidly if already loaded". They did. The engine read "already complete, so
 * set opacity 1 and return" BEFORE attaching any transition, so a cached
 * photograph jumped straight to visible. The better the browser cached, the
 * worse the site looked — it degraded most for the owner and for returning
 * visitors, which is exactly who notices.
 *
 * This runs the real engine in a minimal DOM sandbox and pins the behaviour,
 * not the source text. Earlier tonight two of my tests passed while the thing
 * they protected was broken, both times because they asserted that something
 * had been WRITTEN rather than that it BEHAVED. So:
 *
 *   - a CACHED image starts at 0 and only reaches 1 on a later frame, so there
 *     is a start state for the transition to animate from;
 *   - a NOT-yet-loaded image does the same once its load event arrives;
 *   - PREFERS-REDUCED-MOTION shows the image immediately and animates nothing,
 *     and never leaves it invisible;
 *   - the safety nets (1500ms timeout, visibilitychange, pageshow) reveal
 *     WITHOUT waiting for a frame, because requestAnimationFrame does not fire
 *     in a background tab and a photograph must never be stranded at zero;
 *   - a broken image is still revealed rather than left blank;
 *   - re-scanning is idempotent, as the GRAM modal path depends on.
 *
 * Run:  node tests/image-fade-load-engine-regression.cjs
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SRC = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'js', 'ss-engine-image-fade-load.js'),
    'utf8'
);

let fails = 0;
function ok(cond, msg) {
    if (cond) { console.log('ok: ' + msg); }
    else { fails++; console.error('FAIL: ' + msg); }
}

function makeImg(opts) {
    opts = opts || {};
    const listeners = {};
    return {
        complete: !!opts.complete,
        naturalHeight: opts.naturalHeight === undefined ? 100 : opts.naturalHeight,
        className: opts.className || 'post-image',
        dataset: {},
        style: { opacity: '', transition: '' },
        addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
        _fire(type) { (listeners[type] || []).forEach((fn) => fn()); },
        _has(type) { return (listeners[type] || []).length > 0; }
    };
}

/**
 * One sandbox per scenario. requestAnimationFrame is MANUAL: nothing animates
 * until the scenario pumps frames, which is what lets the test prove the gap
 * between "set to 0" and "raised to 1" actually exists.
 */
function run(scenario) {
    const images = scenario.images;
    const frameQueue = [];
    const timers = [];
    const docListeners = {};
    const winListeners = {};

    const document = {
        readyState: 'complete',
        hidden: false,
        querySelectorAll: () => images,
        addEventListener(type, fn) { (docListeners[type] = docListeners[type] || []).push(fn); }
    };
    const window = {
        document,
        matchMedia: (q) => ({
            matches: !!scenario.reduceMotion && /prefers-reduced-motion/.test(q)
        }),
        getComputedStyle: () => ({ transition: scenario.existingTransition || 'none' }),
        requestAnimationFrame: scenario.noRaf ? undefined : (cb) => { frameQueue.push(cb); return frameQueue.length; },
        setTimeout: (cb, ms) => { timers.push({ cb, ms }); return timers.length; },
        addEventListener(type, fn) { (winListeners[type] = winListeners[type] || []).push(fn); }
    };
    window.window = window;

    const sandbox = { window, document, console };
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(SRC, sandbox, { filename: 'ss-engine-image-fade-load.js' });

    return {
        images,
        pumpFrames(n) {
            for (let i = 0; i < (n || 4); i++) {
                const batch = frameQueue.splice(0, frameQueue.length);
                batch.forEach((cb) => cb());
            }
        },
        fireTimers(atLeastMs) {
            timers
                .filter((t) => t.ms >= (atLeastMs || 0))
                .forEach((t) => t.cb());
        },
        fireDoc(type) { (docListeners[type] || []).forEach((fn) => fn({})); },
        fireWin(type) { (winListeners[type] || []).forEach((fn) => fn({})); }
    };
}

// ── 1. THE BUG SEAN REPORTED: a cached image must not blink ────────────────
{
    const img = makeImg({ complete: true });
    const h = run({ images: [img] });

    ok(img.style.opacity === '0',
        'cached image starts at opacity 0 (so the transition has a start state)');
    ok(/opacity 0\.4s/.test(img.style.transition),
        'cached image has an opacity transition attached before it is raised');
    ok(img.dataset._ssFadeDone === undefined,
        'cached image is not marked done before it has been raised');

    h.pumpFrames(4);
    ok(img.style.opacity === '1', 'cached image reaches opacity 1 after frames run');
    ok(img.dataset._ssFadeDone === '1', 'cached image is marked done once raised');
}

// ── 2. The ordinary path still works ───────────────────────────────────────
{
    const img = makeImg({ complete: false });
    const h = run({ images: [img] });

    ok(img.style.opacity === '0', 'not-yet-loaded image starts at opacity 0');
    ok(img._has('load'), 'a load listener is attached');
    h.pumpFrames(4);
    ok(img.style.opacity === '0', 'it stays invisible until the image actually loads');

    img.complete = true;
    img._fire('load');
    ok(img.style.opacity === '0', 'load does not jump it straight to visible');
    h.pumpFrames(4);
    ok(img.style.opacity === '1', 'it fades in on the frame after load');
}

// ── 3. Reduced motion: shown, not animated, never stranded ─────────────────
{
    const img = makeImg({ complete: true });
    const h = run({ images: [img], reduceMotion: true });

    ok(img.style.opacity === '1', 'reduced motion shows the image immediately');
    ok(img.dataset._ssFadeDone === '1', 'reduced motion marks it done');
    ok(!/opacity/.test(img.style.transition),
        'reduced motion attaches no opacity transition');

    const before = img.style.opacity;
    h.pumpFrames(4);
    ok(img.style.opacity === before, 'reduced motion needs no frames to become visible');
}
{
    // The dangerous way to honour the setting is to skip the engine entirely,
    // which leaves CSS opacity:0 in place and the photograph invisible.
    const img = makeImg({ complete: false });
    run({ images: [img], reduceMotion: true });
    ok(img.style.opacity === '1',
        'reduced motion still reveals an image that has NOT loaded yet');
}

// ── 4. The safety nets must not wait for a frame ───────────────────────────
{
    const img = makeImg({ complete: false });
    const h = run({ images: [img] });
    img.complete = true;          // decoded, but the load event never arrived
    h.fireTimers(1000);           // the 1500ms net
    ok(img.style.opacity === '1',
        'the timeout net reveals without needing a frame (background tabs get none)');
}
{
    const img = makeImg({ complete: true });
    const h = run({ images: [img] });
    img.style.opacity = '0';      // pretend the raise never ran
    h.fireDoc('visibilitychange');
    ok(img.style.opacity === '1', 'returning to the foreground reveals immediately');
}
{
    const img = makeImg({ complete: true });
    const h = run({ images: [img] });
    img.style.opacity = '0';
    h.fireWin('pageshow');
    ok(img.style.opacity === '1', 'back/forward restore reveals immediately');
}

// ── 5. A broken image must not stay invisible ──────────────────────────────
{
    const img = makeImg({ complete: false, naturalHeight: 0 });
    run({ images: [img] });
    img._fire('error');
    ok(img.style.opacity === '1', 'a failed image is revealed rather than left blank');
}

// ── 6. Re-scanning is idempotent (the GRAM modal path) ─────────────────────
{
    const img = makeImg({ complete: true });
    const h = run({ images: [img] });
    h.pumpFrames(4);
    ok(img.style.opacity === '1', 'first scan reveals');
    h.fireDoc('snapsmack:modal:opened');
    ok(img.style.opacity === '1', 'a re-scan leaves an already-revealed image visible');
}

// ── 7. No requestAnimationFrame at all: still reveals ──────────────────────
{
    const img = makeImg({ complete: true });
    const h = run({ images: [img], noRaf: true });
    ok(img.style.opacity === '0', 'without rAF it still starts hidden');
    h.fireTimers(0);   // the rAF shim falls back to setTimeout
    h.fireTimers(0);
    ok(img.style.opacity === '1', 'without rAF the setTimeout fallback still reveals it');
}

// ── 8. A skin's own transition is preserved ────────────────────────────────
{
    const img = makeImg({ complete: true });
    run({ images: [img], existingTransition: 'transform 0.3s ease' });
    ok(/transform 0\.3s ease/.test(img.style.transition),
        "a skin's own transition is kept, not replaced");
    ok(/opacity 0\.4s/.test(img.style.transition),
        'the opacity transition is appended alongside it');
}

if (fails) {
    console.error('\n' + fails + ' FAILURE(S): cached photographs blink instead of fading, '
        + 'reduced motion is ignored, or an image can be left invisible.');
    process.exit(1);
}
console.log('\nImage fade-load engine behaviour: PASS');
process.exit(0);

// ===== SNAPSMACK EOF =====
