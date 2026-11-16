/**
 * SNAPSMACK GLIDE: a cloned row is scenery, and scenery holds NO tab stop.
 *
 * glideFill() repeats the track's own children until the wrap point is off
 * screen. Every copy is marked aria-hidden and taken out of the tab order,
 * because otherwise a screen reader reads the same wall several times and
 * every copy is a place the keyboard stops.
 *
 * THAT WAS ENOUGH ONLY WHILE A TILE WAS THE ANCHOR ITSELF. SS-1323 wraps each
 * tile in a positioned frame so the heart can be a sibling of the
 * photograph's link rather than nested inside it -- invalid HTML, and
 * announced inconsistently. After that change a clone is a div: aria-hidden
 * on the outside, with a focusable anchor and a focusable button still inside
 * it. A keyboard reader would tab into scenery no screen reader announces,
 * and press a heart that likes the same photograph a second time. Codex found
 * it in the rendered gate, before a reader did.
 *
 * THIS IS A BEHAVIOUR TEST, NOT A SOURCE CHECK. It runs the real engine in a
 * fake DOM through node:vm, the same way
 * tests/glide-wall-wraps-at-its-real-period.test.js does, builds the wrapper
 * markup Codex is shipping, and asserts no descendant of any clone can take
 * focus -- while the originals still can. The fake querySelectorAll matches
 * selectors for real, so narrowing the engine's focusable list fails here too.
 *
 * Run:  node tests/glide-scenery-has-no-tab-stops.test.js
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

// --- the smallest DOM that can answer the questions glideFill asks ---------
// A real matcher, not a flag: 'button' must match a button because the
// selector says so, so that removing it from the engine's list fails here.
function matches(node, part) {
    const m = /^([a-z]*)((?:\[[^\]]+\])*)$/.exec(part.trim());
    if (!m) return false;
    if (m[1] && node.tag !== m[1]) return false;
    const attrs = m[2].match(/\[[^\]]+\]/g) || [];
    return attrs.every(function (a) {
        return Object.prototype.hasOwnProperty.call(
            node.attrs, a.slice(1, -1).split('=')[0]);
    });
}

class El {
    constructor(tag, attrs) {
        this.tag = tag;
        this.attrs = Object.assign({}, attrs || {});
        this.children = [];
        this.tabIndex = 0;
    }
    appendChild(child) { this.children.push(child); return child; }
    setAttribute(name, value) { this.attrs[name] = String(value); }
    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attrs, name)
            ? this.attrs[name] : null;
    }
    cloneNode() {
        const copy = new El(this.tag, this.attrs);
        copy.tabIndex = this.tabIndex;
        copy.children = this.children.map(function (c) { return c.cloneNode(true); });
        return copy;
    }
    descendants() {
        return this.children.reduce(function (all, c) {
            return all.concat([c], c.descendants());
        }, []);
    }
    querySelectorAll(selector) {
        const parts = String(selector).split(',');
        return this.descendants().filter(function (n) {
            return parts.some(function (p) { return matches(n, p); });
        });
    }
    // Each child is 100 wide. The track grows as copies are appended, which
    // is what stops glideFill's loop.
    get scrollWidth() { return this.children.length * 100; }
    get scrollHeight() { return this.children.length * 100; }
}

function tileFrame(href) {
    // Exactly the shape Codex is shipping: a positioned frame holding the
    // photograph's anchor and the heart as SIBLINGS.
    const frame = new El('div', { class: 'glide-tile-frame ss-tile-like-host' });
    frame.appendChild(new El('a', { href: href, class: 'glide-tile' }));
    frame.appendChild(new El('button', {
        type: 'button', class: 'ss-tile-like', 'data-tile-like': '7' }));
    return frame;
}

function loadEngine() {
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
    vm.runInNewContext(fs.readFileSync(ENGINE, 'utf8'), { window, document, console });
    assert.ok(window.SSGlide && typeof window.SSGlide.fill === 'function',
        'the engine exposes no fill() to test');
    return window.SSGlide;
}

function trackOfFrames() {
    const track = new El('div', { class: 'glide-track' });
    const originals = [tileFrame('/photo/1'), tileFrame('/photo/2')];
    originals.forEach(function (f) { track.appendChild(f); });
    return { track: track, originals: originals };
}

test('a cloned wrapper tile contains no tab stop', () => {
    const glide = loadEngine();
    const built = trackOfFrames();

    const copies = glide.fill(built.track, 100, 250, '0', false);
    assert.ok(copies > 0, 'fill() added no copies, so the test proved nothing');
    assert.ok(built.track.children.length > built.originals.length,
        'the track did not grow');

    const clones = built.track.children.slice(built.originals.length);
    clones.forEach(function (clone) {
        assert.equal(clone.getAttribute('aria-hidden'), 'true',
            'a copy is not hidden from assistive technology');
        assert.equal(clone.tabIndex, -1, 'the copy itself is still a tab stop');
        clone.descendants().forEach(function (inside) {
            assert.equal(inside.tabIndex, -1,
                'a ' + inside.tag + ' inside aria-hidden scenery can still '
                + 'take focus: the keyboard tabs into a copy no screen reader '
                + 'announces, and the heart likes the same photograph twice');
        });
    });
});

test('the originals are left alone', () => {
    const glide = loadEngine();
    const built = trackOfFrames();
    glide.fill(built.track, 100, 250, '0', false);

    built.originals.forEach(function (frame) {
        assert.equal(frame.getAttribute('aria-hidden'), null,
            'an original tile was hidden from assistive technology');
        frame.descendants().forEach(function (inside) {
            assert.equal(inside.tabIndex, 0,
                'the real ' + inside.tag + ' lost its place in the tab order');
        });
    });
});

test('a bare anchor tile, every other conveyor, still works', () => {
    // No frame at all: this is every skin today, and any wall without hearts.
    const glide = loadEngine();
    const track = new El('div', { class: 'glide-track' });
    track.appendChild(new El('a', { href: '/photo/1', class: 'glide-tile' }));
    track.appendChild(new El('a', { href: '/photo/2', class: 'glide-tile' }));
    assert.ok(glide.fill(track, 100, 250, '0', false) > 0, 'nothing was added');
    track.children.slice(2).forEach(function (clone) {
        assert.equal(clone.getAttribute('aria-hidden'), 'true');
        assert.equal(clone.tabIndex, -1);
    });
});
// ===== SNAPSMACK EOF =====
