/**
 * SNAPSMACK fixed-column geometry boundary tests.
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
        querySelector() { return null; },
        querySelectorAll() { return []; }
    };
    const window = {
        addEventListener() {},
        getComputedStyle() { return {}; }
    };
    const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'ss-engine-columns.js'), 'utf8');
    vm.runInNewContext(source, { window, document, console, fetch, IntersectionObserver: undefined });
    return window.SSColumns;
}

const engine = loadEngine();

test('extreme portraits never render beyond the 900px derivative', () => {
    const twoAcrossSlot = (1728 - 20) / 2;
    const threeAcrossSlot = (1728 - 40) / 3;
    [twoAcrossSlot, threeAcrossSlot].forEach(slot => {
        const tile = engine.boundedTileSize(slot, 0.5, 0);
        assert.ok(tile.width <= 900.01);
        assert.ok(tile.height <= 900.01);
        assert.ok(Math.abs(tile.width / tile.height - 0.5) < 0.0001, 'native aspect is preserved');
    });
});

test('ordinary tiles retain their full column width', () => {
    const tile = engine.boundedTileSize(562.66, 1.5, 2);
    assert.ok(Math.abs(tile.width - 562.66) < 0.01);
    assert.ok(tile.height < 900);
    assert.ok(Math.abs((tile.width - 4) / (tile.height - 4) - 1.5) < 0.0001, 'border stays inside native geometry');
});

// ===== SNAPSMACK EOF =====
