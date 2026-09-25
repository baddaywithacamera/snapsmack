/**
 * 50 SHADES 1.6.0 — ASYMMETRIC (MOSAIC) archive wall, measured against the real
 * engine.
 *
 * The skin renders a finite archive as a run of .snap-mosaic blocks instead of
 * streaming them the way skins/scroll/wall.php does. This checks the thing that
 * change could break: that every block the skin produces lays out cleanly at
 * real viewport widths, for archives from 1 photograph to 400, with the mixed
 * portrait/landscape shapes a real photoblog holds.
 *
 * The block-splitting rule itself is checked in PHP against the shipping file by
 * tests/fsog-mosaic-archive-regression.php.
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

function loadEngine() {
    const document = {
        readyState: 'loading',
        addEventListener() {},
        querySelectorAll() { return []; }
    };
    const window = { addEventListener() {} };
    const source = fs.readFileSync(
        path.join(__dirname, '..', 'assets', 'js', 'ss-engine-mosaic.js'), 'utf8');
    vm.runInNewContext(source, { window, document, console, clearTimeout, setTimeout });
    return window.SnapMosaic;
}

const engine = loadEngine();

/** The rule in skins/50-shades-of-noah-grey/archive-layout.php, mirrored here. */
function blockSizes(total) {
    if (total <= 0) return [];
    if (total <= 6) return [total];
    const full = Math.floor(total / 6);
    const remainder = total % 6;
    const sizes = new Array(full).fill(6);
    if (remainder === 1) { sizes[sizes.length - 1] = 5; sizes.push(2); }
    else if (remainder > 0) { sizes.push(remainder); }
    return sizes;
}

/** A believable photoblog: mostly portrait, some landscape, the odd panorama. */
function archive(n) {
    const shapes = [
        { width: 1200, height: 1800 },   // portrait
        { width: 1800, height: 1200 },   // landscape
        { width: 1400, height: 1400 },   // square
        { width: 2400, height: 1000 },   // panorama
        { width: 1000, height: 1500 },   // portrait
        { width: 1600, height: 1067 }    // landscape
    ];
    return Array.from({ length: n }, (_, i) => shapes[i % shapes.length]);
}

function assertCleanGeometry(layout, containerWidth, label) {
    const epsilon = 0.02;
    layout.items.forEach((item, index) => {
        assert.ok(item.width > 0 && item.height > 0,
            `${label}: tile ${index} has positive dimensions`);
        assert.ok(item.x >= -epsilon && item.y >= -epsilon,
            `${label}: tile ${index} begins inside the mosaic`);
        assert.ok(item.x + item.width <= containerWidth + epsilon,
            `${label}: tile ${index} stays inside the right edge`);
        assert.ok(item.y + item.height <= layout.height + epsilon,
            `${label}: tile ${index} stays inside the bottom edge`);

        layout.items.forEach((other, otherIndex) => {
            if (index >= otherIndex) return;
            const overlapX = Math.min(item.x + item.width, other.x + other.width)
                           - Math.max(item.x, other.x);
            const overlapY = Math.min(item.y + item.height, other.y + other.height)
                           - Math.max(item.y, other.y);
            assert.ok(overlapX <= epsilon || overlapY <= epsilon,
                `${label}: tiles ${index} and ${otherIndex} overlap`);
        });
    });
}

// The widths the wall is actually seen at, plus the gap values the control allows.
const WIDTHS = [1920, 1440, 1200, 768, 390];
const GAPS = [0, 4, 25];
// Every value MOSAIC Hero Emphasis offers. The engine falls back to 'natural'
// for anything it does not know, so an unsupported value would pass silently -
// these are checked against the manifest below.
const EMPHASES = ['natural', 'balanced', 'landscape', 'portrait'];

test('every block shape lays out cleanly at every width, gap and emphasis', () => {
    // A block always holds 1-6 photographs, so exhausting those six sizes covers
    // every block any archive can produce. Each size is tried at several offsets
    // into the shape cycle so the mix of portrait/landscape/panorama varies.
    let checked = 0;
    for (const size of [1, 2, 3, 4, 5, 6]) {
        for (let offset = 0; offset < 6; offset++) {
            const block = archive(size + offset).slice(offset, offset + size);
            for (const width of WIDTHS) {
                for (const gap of GAPS) {
                    for (const emphasis of EMPHASES) {
                        const layout = engine.computeLayout(block, width, gap, emphasis);
                        const label = `block of ${size} (offset ${offset}), ${width}px, `
                                    + `gap ${gap}, emphasis ${emphasis}`;
                        assert.equal(layout.items.length, block.length,
                            `${label}: every photograph is placed`);
                        assertCleanGeometry(layout, width, label);
                        assert.ok(layout.height > 0, `${label}: block has height`);
                        checked++;
                    }
                }
            }
        }
    }
    assert.equal(checked, 6 * 6 * WIDTHS.length * GAPS.length * EMPHASES.length);
});

test('an archive of any size splits into blocks that use every photograph once', () => {
    // The splitting itself is checked against the shipping PHP by
    // tests/fsog-mosaic-archive-regression.php; this confirms the JS mirror of the
    // rule agrees, and that no block is ever outside the 1-6 range the layout
    // sweep above covers.
    for (let total = 0; total <= 400; total++) {
        const sizes = blockSizes(total);
        assert.equal(sizes.reduce((a, b) => a + b, 0), total,
            `archive ${total}: blocks hold every photograph`);
        for (const size of sizes) {
            assert.ok(size >= 1 && size <= 6, `archive ${total}: block of ${size} is in range`);
        }
        if (total > 6) {
            assert.notEqual(sizes[sizes.length - 1], 1,
                `archive ${total}: no orphan final photograph`);
        }
    }
});

test('a full block of six fills the wall width at every viewport', () => {
    // Every block except possibly the last holds six photographs, so this is the
    // normal case and it must reach the right edge exactly.
    for (const width of WIDTHS) {
        const layout = engine.computeLayout(archive(6), width, 4);
        const right = Math.max(...layout.items.map(i => i.x + i.width));
        assert.ok(right >= width - 1.5,
            `block of 6 at ${width}px reaches the right edge (got ${right.toFixed(2)})`);
    }
});

test('a short last block is narrower but never overflows', () => {
    // MEASURED, not assumed: the engine caps a tile at 900px, so a final block of
    // 2-5 photographs cannot span a very wide viewport. At 1920px it reaches
    // 1410px; at 1200px and below it fills. This is the engine's own ceiling and
    // SCROLL has shipped the same behaviour since 0.7.470 - it is recorded here so
    // a future change to the ceiling shows up as a failing test rather than a
    // surprise on someone's archive.
    const reach = {};
    for (const width of WIDTHS) {
        for (const size of [1, 2, 3, 4, 5]) {
            const layout = engine.computeLayout(archive(size), width, 4);
            const right = Math.max(...layout.items.map(i => i.x + i.width));
            assert.ok(right <= width + 0.02,
                `block of ${size} at ${width}px must not overflow (got ${right.toFixed(2)})`);
            reach[`${size}@${width}`] = Math.round(right);
        }
    }
    // The widths that matter most: a phone always fills, and a laptop fills from
    // two photographs up.
    assert.equal(reach['2@390'], 390, 'a phone fills on a two-photograph block');
    assert.equal(reach['2@1200'], 1200, 'a 1200px window fills on a two-photograph block');
    assert.equal(reach['3@1440'], 1440, 'a 1440px window fills from three photographs up');
});

test('a one-photograph archive still renders', () => {
    const layout = engine.computeLayout(archive(1), 1200, 4);
    assert.equal(layout.items.length, 1);
    assertCleanGeometry(layout, 1200, 'single photograph');
});

test('every emphasis the skin offers is one the engine understands', () => {
    // The engine silently falls back to 'natural' for an unknown value, so a typo
    // in the manifest would look like it worked. Compare the two lists directly.
    const manifest = JSON.parse(fs.readFileSync(
        path.join(__dirname, '..', 'skins', '50-shades-of-noah-grey', 'manifest.json'), 'utf8'));
    const offered = Object.keys(manifest.options.masonry_mosaic_emphasis.options);
    assert.deepEqual(offered.slice().sort(), EMPHASES.slice().sort(),
        'manifest offers exactly the four values the engine implements');

    const engineSource = fs.readFileSync(
        path.join(__dirname, '..', 'assets', 'js', 'ss-engine-mosaic.js'), 'utf8');
    for (const value of offered) {
        assert.ok(engineSource.includes(`'${value}'`),
            `ss-engine-mosaic.js handles '${value}'`);
    }

    // And the default must be one of them.
    assert.ok(offered.includes(manifest.options.masonry_mosaic_emphasis.default),
        'the default emphasis is one of the offered values');
});

test('favouring an orientation actually changes the quilt', () => {
    // If emphasis were not reaching the engine, these would be identical and the
    // control would be decorative.
    const block = [
        { width: 1200, height: 1800 }, { width: 1800, height: 1200 },
        { width: 1000, height: 1500 }, { width: 1600, height: 1067 },
        { width: 1400, height: 1400 }, { width: 2400, height: 1000 }
    ];
    const shape = e => JSON.stringify(engine.computeLayout(block, 1440, 4, e)
        .items.map(i => [Math.round(i.x), Math.round(i.y), Math.round(i.width)]));

    assert.notEqual(shape('portrait'), shape('landscape'),
        'Favor Portraits and Favor Landscapes produce different quilts');
});

test('a phone gets one photograph per row', () => {
    const layout = engine.computeLayout(archive(6), 390, 4);
    const xs = new Set(layout.items.map(i => Math.round(i.x)));
    assert.equal(xs.size, 1, 'every tile starts at the same x on a phone');
});

// ===== SNAPSMACK EOF =====
