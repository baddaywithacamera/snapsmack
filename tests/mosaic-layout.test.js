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
        assert.ok(item.width <= 900.02 && item.height <= 900.02, `tile ${index} stays within the 900px derivative ceiling`);
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

test('an extreme portrait trio splits rather than violating hard size limits', () => {
    const images = [
        { width: 500, height: 1800 },
        { width: 900, height: 700 },
        { width: 1000, height: 700 }
    ];
    const layout = engine.computeLayout(images, 1600, 6);

    assertCleanGeometry(layout, 1600);
    assert.ok(layout.sections.length >= 2, 'an impossible full-width group is split');
    layout.sections.forEach((section, sectionIndex) => {
        assert.ok(section.height <= 900.02, `section ${sectionIndex} stays within the hard ceiling`);
    });
    layout.items.forEach((item, index) => {
        assert.ok(item.width <= 900.02, `tile ${index} width stays within the large thumbnail`);
        assert.ok(item.height <= 900.02, `tile ${index} height stays within the large thumbnail`);
        const sourceAR = images[index].width / images[index].height;
        const cellAR = item.width / item.height;
        const retained = Math.min(cellAR / sourceAR, sourceAR / cellAR);
        assert.ok(retained >= 0.8499, `tile ${index} retains at least 85%`);
    });
});

test('FullHD wall rejects both giant heroes and postage-stamp supporting tiles', () => {
    const layout = engine.computeLayout([
        { width: 675, height: 1200 },
        { width: 1600, height: 900 },
        { width: 1500, height: 900 },
        { width: 700, height: 1100 },
        { width: 1400, height: 800 },
        { width: 1200, height: 900 }
    ], 1728, 20, 'portrait');

    assertCleanGeometry(layout, 1728);
    assert.equal(layout.items.length, 6, 'every photograph survives regrouping');
    layout.sections.forEach((section, sectionIndex) => {
        assert.ok(section.height <= 900.02, `section ${sectionIndex} fits the derivative and viewport ceiling`);
    });
    layout.items.forEach((item, index) => {
        const section = layout.sections.find(candidate =>
            item.y >= candidate.y - 0.02 && item.y < candidate.y + candidate.height + 0.02);
        const peers = layout.items.filter(other =>
            other.y >= section.y - 0.02 && other.y < section.y + section.height + 0.02);
        if (peers.length > 1) {
            assert.ok(item.width >= 259.98, `multi-tile item ${index} has useful width`);
            assert.ok(item.height >= 199.98, `multi-tile item ${index} has useful height`);
            assert.ok(item.width * item.height >= 69990, `multi-tile item ${index} has useful area`);
        }
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

test('wide-wall landscapes never render wider than the 900px derivative', () => {
    const layout = engine.computeLayout([
        { id: 11, width: 1800, height: 800 },
        { id: 12, width: 1600, height: 900 },
        { id: 13, width: 1400, height: 800 }
    ], 1600, 6, 'natural');

    assertCleanGeometry(layout, 1600);
    assert.ok(Math.abs(layout.items[2].x + layout.items[2].width - 1600) < 0.02, 'row still anchors both wall edges');
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

    const largestArea = layout => layout.items.reduce((best, item) =>
        item.width * item.height > best.width * best.height ? item : best);
    assert.ok(largestArea(landscape).image.width / largestArea(landscape).image.height >= 1.15,
        'landscape-forward gives the largest cell to a landscape');
    assert.ok(largestArea(portrait).image.width / largestArea(portrait).image.height < 1.15,
        'portrait-forward gives the largest cell to a portrait');
});

test('six-photo desktop blocks do not collapse into postage-stamp cells', () => {
    const layout = engine.computeLayout([
        { width: 1000, height: 1000 },
        { width: 900, height: 700 },
        { width: 1500, height: 800 },
        { width: 1100, height: 800 },
        { width: 1400, height: 800 },
        { width: 1500, height: 900 }
    ], 1688, 20, 'portrait');

    assertCleanGeometry(layout, 1688);
    const areas = layout.items.map(item => item.width * item.height);
    layout.items.forEach((item, index) => {
        assert.ok(item.width >= 259.98, `tile ${index} is wide enough to read as a photograph`);
        assert.ok(item.height >= 199.98, `tile ${index} is tall enough to read as a photograph`);
        assert.ok(item.width * item.height >= 69990, `tile ${index} has useful visible area`);
    });
    assert.ok(Math.max(...areas) / Math.min(...areas) <= 6.001,
        'supporting photographs remain substantial beside the hero');
});

test('an unsuitable six-photo group splits instead of bypassing useful-size rules', () => {
    const layout = engine.computeLayout([
        { width: 900, height: 1200 },
        { width: 900, height: 1200 },
        { width: 900, height: 1200 },
        { width: 900, height: 1200 },
        { width: 900, height: 1200 },
        { width: 900, height: 1200 }
    ], 1688, 20, 'landscape');

    assertCleanGeometry(layout, 1688);
    assert.ok(layout.sections.length >= 2, 'the incompatible group becomes smaller mosaics');
    assert.equal(layout.items.length, 6, 'splitting never drops a photograph');
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
