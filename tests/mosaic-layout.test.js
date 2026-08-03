/**
 * SNAPSMACK MOSAIC compositor geometry tests.
 */

/**
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
    const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'ss-engine-mosaic.js'), 'utf8');
    vm.runInNewContext(source, { window, document, console, clearTimeout, setTimeout });
    return window.SnapMosaic;
}

function assertCleanGeometry(layout, containerWidth) {
    const epsilon = 0.02;
    layout.items.forEach((item, index) => {
        assert.ok(item.width > 0 && item.height > 0, `tile ${index} has positive dimensions`);
        assert.ok(item.x >= -epsilon && item.y >= -epsilon, `tile ${index} begins inside the mosaic`);
        assert.ok(item.x + item.width <= containerWidth + epsilon, `tile ${index} stays inside the right edge`);
        assert.ok(item.y + item.height <= layout.height + epsilon, `tile ${index} stays inside the bottom edge`);

        layout.items.forEach((other, otherIndex) => {
            if (index >= otherIndex) return;
            const overlapX = Math.min(item.x + item.width, other.x + other.width) - Math.max(item.x, other.x);
            const overlapY = Math.min(item.y + item.height, other.y + other.height) - Math.max(item.y, other.y);
            assert.ok(overlapX <= epsilon || overlapY <= epsilon, `tiles ${index} and ${otherIndex} do not overlap`);
        });
    });
}

const engine = loadEngine();

test('portrait-led three-image section spans a two-image stack', () => {
    const layout = engine.computeLayout([
        { width: 700, height: 1100 },
        { width: 900, height: 700 },
        { width: 800, height: 900 }
    ], 800, 4);

    assertCleanGeometry(layout, 800);
    assert.equal(layout.items[0].y, 0);
    assert.equal(layout.items[1].y, 0);
    assert.ok(layout.items[2].y > 0);
    assert.ok(Math.abs(layout.items[0].height - layout.height) < 0.02);
});

test('portrait-led four-image section spans a three-image stack', () => {
    const layout = engine.computeLayout([
        { width: 700, height: 1100 },
        { width: 900, height: 700 },
        { width: 800, height: 900 },
        { width: 700, height: 800 }
    ], 800, 6);

    assertCleanGeometry(layout, 800);
    assert.ok(Math.abs(layout.items[0].height - layout.height) < 0.02);
    assert.ok(layout.items[1].y < layout.items[2].y && layout.items[2].y < layout.items[3].y);
});

test('an extreme portrait trio keeps full wall width and never exceeds the crop ceiling', () => {
    const images = [
        { width: 500, height: 1800 },
        { width: 900, height: 700 },
        { width: 1000, height: 700 }
    ];
    const layout = engine.computeLayout(images, 1600, 6);

    assertCleanGeometry(layout, 1600);
    assert.equal(layout.sections[0].width, 1600);
    assert.equal(layout.sections[0].x, 0);
    assert.ok(layout.height <= 900.02);
    assert.equal(layout.items[0].x, 0);
    assert.ok(Math.abs(layout.items[2].x + layout.items[2].width - 1600) < 0.02, 'supporting stack reaches the opposite wall edge');
    assert.ok(Math.abs(layout.items[0].height - layout.height) < 0.02, 'portrait remains the spanning hero');
    layout.items.forEach((item, index) => {
        const sourceAR = images[index].width / images[index].height;
        const cellAR = item.width / item.height;
        const retained = Math.min(cellAR / sourceAR, sourceAR / cellAR);
        assert.ok(retained >= 0.8499, `tile ${index} retains at least 85%`);
    });
});

test('landscape-led block mixes a two-column span with a two-row span', () => {
    const layout = engine.computeLayout([
        { width: 1500, height: 800 },
        { width: 700, height: 1100 },
        { width: 700, height: 900 },
        { width: 1200, height: 800 }
    ], 800, 6);

    assertCleanGeometry(layout, 800);

    const acrossColumns = layout.items[0];
    const acrossRows = layout.items[1];
    const lowerLeft = layout.items[2];
    const lowerRight = layout.items[3];

    assert.equal(acrossColumns.x, 0);
    assert.equal(acrossColumns.y, 0);
    assert.equal(acrossRows.y, 0);
    assert.ok(acrossColumns.width > lowerLeft.width);
    assert.ok(acrossColumns.width > lowerRight.width);
    assert.ok(Math.abs(acrossRows.height - layout.height) < 0.02);
    assert.ok(lowerLeft.y > acrossColumns.y);
    assert.ok(lowerRight.y > acrossColumns.y);
});

test('admin emphasis chooses the eligible hero orientation', () => {
    const images = [
        { id: 1, width: 700, height: 1100 },
        { id: 2, width: 900, height: 700 },
        { id: 4, width: 800, height: 900 },
        { id: 5, width: 700, height: 1100 },
        { id: 6, width: 1500, height: 800 },
        { id: 8, width: 900, height: 700 }
    ];
    const landscape = engine.computeLayout(images, 1000, 6, 'landscape');
    const portrait = engine.computeLayout(images, 1000, 6, 'portrait');

    assert.equal(landscape.items[3].image.id, 6, 'landscape-forward promotes the available landscape');
    assert.ok(landscape.items[5].y > landscape.sections[1].y, 'landscape hero sits beside a stack');
    assert.equal(portrait.items[3].image.id, 5, 'portrait-forward preserves the available portrait hero');
});

test('five-image layout ends with a full-width image', () => {
    const layout = engine.computeLayout([
        { width: 1400, height: 900 },
        { width: 700, height: 1000 },
        { width: 900, height: 700 },
        { width: 800, height: 1000 },
        { width: 1200, height: 900 }
    ], 800, 4);

    assertCleanGeometry(layout, 800);
    assert.equal(layout.sections.length, 2);
    assert.ok(Math.abs(layout.items[4].width - 800) < 0.02);
    assert.equal(layout.items[4].x, 0);
});

test('mobile layout collapses to one image per row', () => {
    const layout = engine.computeLayout([
        { width: 700, height: 1100 },
        { width: 900, height: 700 },
        { width: 800, height: 900 }
    ], 480, 4);

    assertCleanGeometry(layout, 480);
    layout.items.forEach(item => assert.equal(item.width, 480));
});

// ===== SNAPSMACK EOF =====
