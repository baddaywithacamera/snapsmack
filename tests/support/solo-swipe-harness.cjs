/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 *
 * Runs the REAL ss-engine-comms.js against a stub DOM and drives real touch
 * gestures through it, so the solo-page swipe is executed rather than
 * pattern-matched.
 *
 * Why this exists: the first version of the regression checked the engine's
 * SOURCE for "addEventListener('touchstart'" and for the guard names. Two
 * mutations walked straight past it — a `return;` inserted at the top of the
 * handler, which disables the gesture while leaving every string in place,
 * and the scrollable-element guard's CALL deleted while its definition kept
 * the name in the file. Both are the same mistake: asserting the shape of a
 * contract instead of executing it.
 *
 * Prints one JSON object on stdout: { case: result, ... }.
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ENGINE = path.join(__dirname, '..', '..', 'assets', 'js', 'ss-engine-comms.js');

function makeEnvironment(options) {
    const opts = options || {};
    const listeners = {};
    const navigated = { to: null };

    function element(spec) {
        const el = Object.assign({
            nodeType: 1,
            tagName: 'DIV',
            style: {},
            scrollWidth: 0,
            clientWidth: 0,
            parentElement: null,
            getAttribute() { return null; },
            addEventListener() {},
            click() {},
            classList: { add() {}, remove() {}, contains() { return false; } },
        }, spec || {});
        return el;
    }

    // The page's own prev/next links, which is what the engine reads.
    const relLinks = {
        next: element({ tagName: 'A', getAttribute: () => '/photo/older' }),
        prev: element({ tagName: 'A', getAttribute: () => '/photo/newer' }),
    };

    const photo = element({ tagName: 'IMG' });
    if (opts.insideScroller) {
        const scroller = element({ scrollWidth: 1200, clientWidth: 300 });
        photo.parentElement = scroller;
    }

    const lightbox = element({ style: { display: opts.lightboxOpen ? 'flex' : 'none' } });

    const document = {
        body: element({ tagName: 'BODY' }),
        documentElement: element({ tagName: 'HTML' }),
        readyState: 'complete',
        addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
        removeEventListener() {},
        getElementById(id) {
            if (id === 'ss-lightbox-overlay') return opts.lightboxPresent === false ? null : lightbox;
            return null;
        },
        querySelector(sel) {
            if (sel.indexOf('rel="next"') !== -1) return opts.noNext ? null : relLinks.next;
            if (sel.indexOf('rel="prev"') !== -1) return opts.noPrev ? null : relLinks.prev;
            if (sel === '.ss-slider') return opts.slider ? element({}) : null;
            return null;
        },
        querySelectorAll() { return []; },
        createElement() { return element({ appendChild() {}, remove() {} }); },
    };

    const window = {
        document,
        location: { get href() { return navigated.to; }, set href(v) { navigated.to = v; } },
        addEventListener() {},
        getComputedStyle(el) { return { overflowX: el && el.scrollWidth > el.clientWidth ? 'auto' : 'visible' }; },
        setTimeout: () => 0,
        clearTimeout: () => {},
        console,
    };
    window.window = window;

    return { listeners, navigated, document, window, photo };
}

function run(label, options, gesture) {
    const env = makeEnvironment(options);
    const sandbox = {
        window: env.window,
        document: env.document,
        console,
        setTimeout: () => 0,
        clearTimeout: () => {},
        navigator: { userAgent: 'harness' },
    };
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(ENGINE, 'utf8'), sandbox, { filename: ENGINE });

    const fire = (type, detail) => {
        (env.listeners[type] || []).forEach((fn) => fn(detail));
    };

    const touch = (x, y) => ({ clientX: x, clientY: y, target: env.photo });
    const x0 = 400, y0 = 400;
    const fingers = gesture.fingers === 2
        ? [touch(x0, y0), touch(x0 + 40, y0)]
        : [touch(x0, y0)];

    fire('touchstart', { touches: fingers, changedTouches: [touch(x0, y0)], target: env.photo });
    if (gesture.secondFinger) {
        fire('touchmove', { touches: [touch(x0, y0), touch(x0 + 40, y0)], target: env.photo });
    }
    fire('touchend', {
        touches: [],
        changedTouches: [touch(x0 + gesture.dx, y0 + gesture.dy)],
        target: env.photo,
    });

    return [label, env.navigated.to];
}

const results = Object.fromEntries([
    run('swipe left navigates to the rel=next page', {}, { dx: -120, dy: 8 }),
    run('swipe right navigates to the rel=prev page', {}, { dx: 130, dy: -8 }),
    run('a 40px drag does nothing', {}, { dx: -40, dy: 4 }),
    run('a mostly vertical swipe does nothing', {}, { dx: -120, dy: 300 }),
    run('a pure vertical scroll does nothing', {}, { dx: 0, dy: -250 }),
    run('two fingers at once does nothing', {}, { dx: -120, dy: 6, fingers: 2 }),
    run('a second finger mid-gesture cancels it', {}, { dx: -120, dy: 6, secondFinger: true }),
    run('an open lightbox keeps the gesture', { lightboxOpen: true }, { dx: -120, dy: 6 }),
    run('a sideways-scrollable element keeps the gesture', { insideScroller: true }, { dx: -120, dy: 6 }),
    run('a slider keeps the gesture', { slider: true }, { dx: -120, dy: 6 }),
    run('with no next link a left swipe does nothing', { noNext: true }, { dx: -120, dy: 6 }),
]);

process.stdout.write(JSON.stringify(results, null, 1) + '\n');
// ===== SNAPSMACK EOF =====
