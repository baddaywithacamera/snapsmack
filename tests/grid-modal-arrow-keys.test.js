/**
 * SNAPSMACK grid modal: the arrow keys move to the next photograph.
 *
 * SS-1095. The owner reported, with screenshots of two of his own sites, that
 * the arrow keys and the spacebar do nothing in the post overlay. He was
 * right, and the trace said why: ssNavUrl() in ss-engine-comms.js reads
 * #snap-nav-data or a rel="prev"/"next" link, the overlay is a FRAGMENT that
 * emits neither, and every source is read from the document as it was LOADED
 * -- which belongs to the grid, not to the photograph now showing.
 *
 * Option C, approved 31 October 2026: the grid on the page IS the list. The
 * engine already knows every tile, because it intercepts their clicks, so the
 * neighbour of the open photograph is the tile beside it in DOM order. One
 * engine file, nine skins (aurora, game-on, heuristic, instant-camera,
 * jive-turkey, parade, sliders, sudden-impact, the-grid), no skin changes and
 * no server changes.
 *
 * It also navigates in the order the reader is LOOKING AT. The two rejected
 * options both carried archive order, which on a filtered or searched grid is
 * a different sequence from the one on screen.
 *
 * WHAT THIS TEST CAN AND CANNOT DO, said rather than discovered later. It runs
 * the real engine source in a fake DOM through node:vm, the same way
 * tests/columns-layout.test.js and
 * tests/glide-wall-wraps-at-its-real-period.test.js run theirs, and it
 * exercises the PURE choice: given the tile hrefs and the open one, which
 * comes next. It does not press a key in a browser and it cannot prove a
 * keypress moved anything. That is Codex's rendered real-key-event harness on
 * the overlay path, and that harness is the acceptance for this change -- not
 * this file.
 *
 * Run:  node tests/grid-modal-arrow-keys.test.js
 *
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line of this file MUST be the SNAPSMACK EOF marker.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const ENGINE = path.join(__dirname, '..', 'assets', 'js', 'ss-engine-grid-modal.js');
const SOURCE = fs.readFileSync(ENGINE, 'utf8');

/** The engine's source with its comments stripped, so a check cannot match the
 *  prose that explains it. Crude but sufficient: the engine has no regex
 *  literals or strings containing comment markers. */
function engineCode() {
    return SOURCE
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .split('\n')
        .map((line) => line.replace(/\/\/.*$/, ''))
        .join('\n');
}

function loadEngine() {
    // No overlay in this document, so the engine exposes its pure parts and
    // then returns without touching anything -- the same deliberate shape as
    // ss-engine-glide.js. The pure choice has to be reachable without a page.
    const document = {
        readyState: 'complete',
        addEventListener() {},
        querySelector() { return null; },
        querySelectorAll() { return []; },
        getElementById() { return null; },
        body: { classList: { add() {}, remove() {} } }
    };
    const window = { addEventListener() {}, location: { href: '' } };
    vm.runInNewContext(SOURCE, { window, document, console, fetch() {}, history: {} });
    assert.ok(window.SSGridModal,
        'the engine exposes nothing to test; SSGridModal is missing');
    return window.SSGridModal;
}

const GRID = [
    'https://example.test/photo/one',
    'https://example.test/photo/two',
    'https://example.test/photo/three',
    'https://example.test/photo/four'
];

test('the next tile in DOM order is the next photograph', () => {
    const { neighbourHref } = loadEngine();
    assert.equal(neighbourHref(GRID, GRID[1], 1), GRID[2]);
    assert.equal(neighbourHref(GRID, GRID[0], 1), GRID[1]);
    assert.equal(neighbourHref(GRID, GRID[2], -1), GRID[1]);
    assert.equal(neighbourHref(GRID, GRID[3], -1), GRID[2]);
});

test('the last loaded tile does nothing rather than guessing', () => {
    // Endless scroll means the grid is a window onto the archive, not the
    // whole of it. Wrapping round to the first photograph would be a lie about
    // where the reader is, and loading the next page from a keypress is more
    // than this change is allowed to do.
    const { neighbourHref } = loadEngine();
    assert.equal(neighbourHref(GRID, GRID[3], 1), '');
    assert.equal(neighbourHref(GRID, GRID[0], -1), '');
});

test('a photograph that is not in the loaded grid does nothing', () => {
    // A deep link opens the overlay with no tile having been clicked, and the
    // photograph may not be in the loaded page at all.
    const { neighbourHref } = loadEngine();
    assert.equal(neighbourHref(GRID, 'https://example.test/photo/ninety', 1), '');
    assert.equal(neighbourHref(GRID, 'https://example.test/photo/ninety', -1), '');
});

test('a grid of one, an empty grid and a missing current all do nothing', () => {
    const { neighbourHref } = loadEngine();
    assert.equal(neighbourHref([GRID[0]], GRID[0], 1), '');
    assert.equal(neighbourHref([GRID[0]], GRID[0], -1), '');
    assert.equal(neighbourHref([], GRID[0], 1), '');
    assert.equal(neighbourHref(GRID, '', 1), '');
    assert.equal(neighbourHref(null, GRID[0], 1), '');
    assert.equal(neighbourHref(GRID, GRID[0], 0), '');
});

test('the same photograph twice in the grid resolves from its first place', () => {
    // A grid can repeat a tile -- the glide wall clones its track, and a
    // filtered grid can surface one photograph twice. Documented rather than
    // left to chance: the first occurrence wins, so the keys stay predictable.
    const { neighbourHref } = loadEngine();
    const repeated = [GRID[0], GRID[1], GRID[0], GRID[2]];
    assert.equal(neighbourHref(repeated, GRID[0], 1), GRID[1]);
});

test('typing is never navigation', () => {
    // The overlay carries a comment box. A space typed into it must reach the
    // box, not move to the previous photograph.
    const { isTypingTarget } = loadEngine();
    for (const tag of ['INPUT', 'TEXTAREA', 'SELECT']) {
        assert.equal(isTypingTarget({ tagName: tag }), true, tag + ' is a typing target');
    }
    assert.equal(isTypingTarget({ tagName: 'DIV', isContentEditable: true }), true,
        'a contenteditable element is a typing target');
    assert.equal(isTypingTarget({ tagName: 'A' }), false);
    assert.equal(isTypingTarget({ tagName: 'DIV' }), false);
    assert.equal(isTypingTarget(null), false);
});

// ── And the handler really uses them ───────────────────────────────────────
//
// Everything above tests pure functions. The engine could expose them and
// never call them, which leaves every assertion green and the keys still dead
// -- the exact shape of fault this ticket is about.
//
// EVERY ASSERTION BELOW IS SCOPED TO THE KEYDOWN HANDLER, and that is the
// whole point. The first version searched the WHOLE file and five of eleven
// mutations walked straight past:
//
//   * removing the overlay-is-open guard -- the popstate handler also calls
//     hasAttribute('hidden'), so the search matched that;
//   * removing preventDefault from the key handler -- both click handlers call
//     it, so the search matched those;
//   * hardcoding the tile class -- the click handler also builds
//     '.' + P + '-tile a';
//   * breaking Escape -- closeModal() is also called by the back button and
//     the backdrop;
//   * dropping the neighbourHref CALL -- the search matched its DEFINITION.
//
// All five are one mistake: a search that can be satisfied by a different
// occurrence than the one it names. Reading only the handler removes the whole
// class from this file rather than patching five patterns one at a time.

/** The keydown handler's body, comments stripped. Asserts it found something,
 *  so a failed extraction cannot quietly pass every check below. */
function keydownHandler() {
    const code = engineCode();
    const at = code.indexOf("addEventListener('keydown'");
    assert.ok(at > -1, 'there is no keydown handler in the engine at all');
    const close = code.indexOf('\n    });', at);
    assert.ok(close > at, 'could not find the end of the keydown handler');
    const body = code.slice(at, close);
    assert.ok(body.includes('Escape'),
        'the extracted handler does not even mention Escape, so the extraction '
        + 'is wrong and every check below would be meaningless');
    return body;
}

test('the keydown handler maps the three keys', () => {
    const body = keydownHandler();
    assert.match(body, /ArrowRight/, 'ArrowRight is not handled');
    assert.match(body, /ArrowLeft/, 'ArrowLeft is not handled');
    assert.match(body, /===\s*' '/, 'the spacebar is not handled');
});

test('the handler asks neighbourHref which tile is next', () => {
    const body = keydownHandler();
    assert.match(body, /neighbourHref\s*\(/,
        'the handler never asks neighbourHref which tile is next');
    assert.match(body, /location\.href/,
        'the handler no longer identifies the open photograph by the address '
        + 'bar, which is what makes a deep-linked overlay work with the same code');
});

test('the handler does nothing unless the overlay is open', () => {
    const body = keydownHandler();
    assert.match(body, /hasAttribute\(\s*'hidden'\s*\)/,
        'the key handler no longer checks whether the overlay is open, so arrows '
        + 'and space would act on the grid page as well');
});

test('Escape still closes, and that behaviour is unchanged', () => {
    const body = keydownHandler();
    assert.match(body, /Escape'\s*\)\s*\{\s*closeModal\s*\(\s*\)/,
        'Escape no longer closes the overlay from the key handler');
});

test('the spacebar cannot also scroll the page', () => {
    const body = keydownHandler();
    assert.match(body, /preventDefault/,
        'nothing calls preventDefault in the KEY handler, so space would move to '
        + 'the previous photograph AND scroll the grid behind the overlay');
});

test('the typing guard is consulted by the handler, not merely exported', () => {
    const body = keydownHandler();
    assert.match(body, /isTypingTarget\s*\(/,
        'isTypingTarget is defined but not called from the key handler, so a '
        + 'space typed into the comment box would navigate');
});

test('space goes the same way as ArrowLeft, matching the solo-page engine', () => {
    // The one preference in this change rather than a fact. ss-engine-comms.js
    // already sends space backward on a solo page, and one product should not
    // disagree with itself. Pinned so that if the owner wants space to advance
    // instead, it is a deliberate change with a failing test, not a drift.
    const body = keydownHandler();
    assert.match(body, /ArrowLeft'\s*\|\|\s*e\.key\s*===\s*' '/,
        'space no longer shares the ArrowLeft branch, so the overlay and the '
        + 'solo page now move opposite ways on the same key');
});

test('the tile selector is derived from the overlay prefix, not hardcoded', () => {
    // One engine serves nine skins and each has its own prefix. A hardcoded
    // class would work on whichever skin it was written against and silently
    // do nothing on the other eight.
    const body = keydownHandler();
    assert.match(body, /'\.'\s*\+\s*P\s*\+\s*'-tile a/,
        'the tile lookup in the key handler no longer derives its class from '
        + 'the overlay prefix');
});
// ===== SNAPSMACK EOF =====
