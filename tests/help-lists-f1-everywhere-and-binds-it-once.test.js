/**
 * SNAPSMACK: F1 IS LISTED ON EVERY PAGE AND BOUND EXACTLY ONCE.
 *
 * SS-1381, the PM's ruling of 2026-11-15: "add F1 help to both, without a
 * duplicate listener". Two separate claims, and each one has a way of going
 * wrong that nothing else would catch.
 *
 * LISTED: GRAMOFSMACK used to be excluded. The toast was suppressed there and
 * the F1 row was pushed inside an `if (!gram)` block, so the carousel had no
 * help and nothing told the viewer so. The owner reversed that. ALFRED's
 * archive lightbox was never listed at all: it binds ESC and the arrows in
 * ss-engine-alfred-archive.js, and a key that works but appears in no help
 * panel is a key nobody finds.
 *
 * BOUND ONCE: the obvious way to give ALFRED its F1 is to add a listener in
 * its own engine. That would be wrong, and quietly so -- ss-engine-comms.js
 * already handles F1 at the document, so the panel would open and shut on one
 * press and F1 would read as broken. The keys are listed in comms, where the
 * help is assembled, and bound in alfred, where the lightbox is.
 *
 * DIRECTION IS NOT TOUCHED by SS-1381 and is asserted here so a later change
 * cannot drift it in silence: on an archive set RIGHT is index +1, which is
 * the older photograph, and that already matches the owner's rule.
 *
 * Runs the real engine sources in a fake DOM through node:vm, the way
 * tests/glide-wall-wraps-at-its-real-period.test.js does.
 *
 * Run:  node tests/help-lists-f1-everywhere-and-binds-it-once.test.js
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

const JS = path.join(__dirname, '..', 'assets', 'js');
const COMMS = path.join(JS, 'ss-engine-comms.js');
const ALFRED = path.join(JS, 'ss-engine-alfred-archive.js');

// --- a fake page -----------------------------------------------------------
// `present` is the set of selectors and ids this page is pretending to have.
// Everything else answers null, which is what the engine's probes expect.
//
// IT HAS TO BE RICH ENOUGH FOR ALFRED TO ACTUALLY START. The first version of
// this file was not: alfred-archive bails out when it finds no tiles, so its
// listener was never registered and the duplicate-F1 check below passed
// against a page where alfred was not running at all. A mutation -- adding an
// F1 listener to alfred -- survived, which is the only reason I know. So the
// alfred page here has a lightbox box, a grid, two tiles and an image, and
// the box is left NOT hidden so its handler acts rather than returning.
function node(extra) {
    return Object.assign({
        hidden: false,
        disabled: false,
        offsetWidth: 1,
        textContent: '',
        classList: { add() {}, remove() {}, toggle() {} },
        addEventListener() {},
        setAttribute() {},
        getAttribute() { return ''; },
        querySelector() { return null; },
        querySelectorAll() { return []; }
    }, extra || {});
}

function fakePage(present, siteMode) {
    const has = (sel) => present.indexOf(sel) !== -1;
    const keydowns = [];
    const tile = () => node({ getAttribute: () => 'photo.jpg' });
    const lightbox = node({
        querySelector(sel) {
            // The image must exist or alfred returns before binding anything.
            return sel === '.alfred-lb-img' ? node() : node();
        }
    });
    const grid = node({ querySelectorAll: () => [tile(), tile()] });
    const document = {
        readyState: 'complete',
        body: { classList: { add() {}, remove() {} } },
        addEventListener(type, fn) { if (type === 'keydown') keydowns.push(fn); },
        removeEventListener() {},
        querySelector(sel) {
            if (!has(sel)) return null;
            return sel === '.alfred-archive-grid' ? grid : node();
        },
        querySelectorAll() { return []; },
        getElementById(id) {
            if (!has('#' + id)) return null;
            return id === 'alfred-archive-lightbox' ? lightbox : node();
        },
        createElement() {
            return { style: {}, classList: { add() {}, remove() {} },
                setAttribute() {}, appendChild() {}, addEventListener() {} };
        },
        documentElement: {}
    };
    const window = {
        SNAP_SITE_MODE: siteMode,
        addEventListener() {},
        getComputedStyle() { return { getPropertyValue() { return ''; } }; },
        matchMedia() { return { matches: false, addEventListener() {} }; },
        setTimeout() {}, clearTimeout() {},
        innerWidth: 1920
    };
    window.window = window;
    window.document = document;
    return { window, document, keydowns };
}

function load(page, files) {
    const sandbox = Object.assign({ console, setTimeout() {}, clearTimeout() {} }, {
        window: page.window, document: page.document
    });
    files.forEach(function (file) {
        vm.runInNewContext(fs.readFileSync(file, 'utf8'), sandbox);
    });
    return sandbox;
}

function keysOf(rows) { return rows.map(function (r) { return r[0]; }); }

test('F1 is listed on GRAMOFSMACK, which used to be excluded', () => {
    const page = fakePage([], 'carousel');
    load(page, [COMMS]);
    const rows = page.window.snapKeyboard.shortcuts();
    assert.ok(keysOf(rows).indexOf('F1') !== -1,
        'the carousel lists no F1: gram has help now, and a help key nobody '
        + 'is told about is a help key nobody presses');
    // The solo-only panels are still not listed there, because gram has none.
    ['1', '2', 'D'].forEach(function (key) {
        assert.ok(keysOf(rows).indexOf(key) === -1,
            'gram lists ' + key + ', a key for a panel it does not have');
    });
});

test('F1 is listed on a solo page too', () => {
    const page = fakePage(['#show-details'], 'photoblog');
    load(page, [COMMS]);
    const rows = page.window.snapKeyboard.shortcuts();
    assert.ok(keysOf(rows).indexOf('F1') !== -1, 'a solo page lists no F1');
    assert.ok(keysOf(rows).indexOf('1') !== -1, 'the info panel is not listed');
});

test("ALFRED's archive lightbox keys appear in the help", () => {
    const page = fakePage(['.alfred-archive-grid', '.alfred-lb-img'], 'photoblog');
    load(page, [COMMS]);
    const keys = keysOf(page.window.snapKeyboard.shortcuts());
    ['LEFT', 'RIGHT', 'F1', 'ESC'].forEach(function (key) {
        assert.ok(keys.indexOf(key) !== -1,
            'the ALFRED archive lightbox does not list ' + key + ': the key '
            + 'works and no help panel mentions it');
    });
});

test('the grid alone is not enough to list the arrows', () => {
    // Tiles without the overlay: the arrows would go nowhere.
    const page = fakePage(['.alfred-archive-grid'], 'photoblog');
    load(page, [COMMS]);
    const keys = keysOf(page.window.snapKeyboard.shortcuts());
    assert.ok(keys.indexOf('LEFT') === -1 && keys.indexOf('RIGHT') === -1,
        'arrows are listed for a page with no lightbox to move through');
});

// How many registered keydown listeners act on a key, measured by pressing it
// and counting the ones that call preventDefault.
function listenersActingOn(page, key) {
    let acted = 0;
    page.keydowns.forEach(function (fn) {
        let prevented = false;
        try {
            fn({ key: key, target: { tagName: 'BODY' },
                 preventDefault() { prevented = true; }, stopPropagation() {} });
        } catch (err) { /* needs more page than this; not a handler for this key */ }
        if (prevented) acted++;
    });
    return acted;
}

test('F1 is bound exactly once across both engines', () => {
    const page = fakePage(
        ['#alfred-archive-lightbox', '.alfred-archive-grid', '.alfred-lb-img'],
        'photoblog');
    load(page, [COMMS, ALFRED]);

    // Guard the guard: if alfred never registered a listener, this test
    // cannot see a duplicate and would pass for the wrong reason.
    assert.ok(page.keydowns.length >= 2,
        'only ' + page.keydowns.length + ' keydown listener(s) registered, so '
        + 'alfred did not start and this test proves nothing');

    const acted = listenersActingOn(page, 'F1');
    assert.equal(acted, 1,
        'F1 is handled by ' + acted + ' listeners. Two of them toggle the '
        + 'help panel open and shut on a single press, which reads as F1 '
        + 'doing nothing at all.');
});

test('the F1 handler itself runs on GRAMOFSMACK', () => {
    // The list and the handler are two different claims. Listing F1 on gram
    // while the handler still returns early there would show a help row for a
    // key that does nothing -- worse than not listing it.
    const page = fakePage([], 'carousel');
    load(page, [COMMS]);
    assert.equal(listenersActingOn(page, 'F1'), 1,
        'F1 is listed on gram but no handler acts on it there');
});

test('SS-1381 did not change arrow direction', () => {
    // The source is the record here: the navigation reads rel="prev" and
    // rel="next" and this asserts which arrow uses which, without a page.
    const comms = fs.readFileSync(COMMS, 'utf8');
    assert.match(comms, /e\.key === 'ArrowLeft' && prevUrl/,
        'LEFT no longer goes to rel="prev". SS-1381 was ruled F1 only: on an '
        + 'archive set RIGHT is index +1, which is older, and that already '
        + 'matched the owner\'s rule.');
    assert.match(comms, /e\.key === 'ArrowRight' && nextUrl/,
        'RIGHT no longer goes to rel="next"');
});
// ===== SNAPSMACK EOF =====
