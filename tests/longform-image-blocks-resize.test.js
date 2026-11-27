'use strict';
// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(
    path.resolve(__dirname, '..', 'assets', 'js', 'smack-longform-image-blocks.js'),
    'utf8'
);

test('smack-longform-image-blocks defines switchable SNAP_WIDTH_STOPS constant', () => {
    assert.match(src, /var SNAP_WIDTH_STOPS\s*=/);
    assert.match(src, /function snapWidth\(value\)/);
});

test('smack-longform-image-blocks preserves decorative flag and center-align drag math', () => {
    assert.match(src, /\(\?:\\s\*\\\|\\s\*\(decorative\)\)\?/);
    assert.match(src, /block\.decorative\s*\?\s*'\|decorative'\s*:\s*''/);
    assert.match(src, /block\.align === 'center'/);
});

test('round-trip shortcode serialization preserves gallery, size, align, width, decorative', () => {
    const rx = /\[img:\s*(g)?\s*(\d+)(?:\s*\|\s*(small|wall|full))?(?:\s*\|\s*(left|center|right))?(?:\s*\|\s*(\d{1,3})(?:%)?)?(?:\s*\|\s*(decorative))?\s*\]/gi;
    const input = 'Intro\n\n[img:g42|small|left|42%|decorative]\n\nOutro';
    const match = rx.exec(input);
    assert.ok(match);
    const block = {
        gallery: !!match[1],
        id: parseInt(match[2], 10),
        size: match[3] || 'full',
        align: match[4] || 'center',
        width: match[5] ? Math.max(20, Math.min(100, Math.round(parseInt(match[5], 10)))) : 100,
        decorative: !!match[6],
    };
    assert.equal(block.width, 42);
    const out = '[img:' + (block.gallery ? 'g' : '') + block.id + '|' + block.size + '|' + block.align + '|' + block.width + (block.decorative ? '|decorative' : '') + ']';
    assert.equal(out, '[img:g42|small|left|42|decorative]');
});

test('smack-longform-image-blocks resolves library assets and multi-editor targets', () => {
    assert.match(src, /smack-media\.php\?ajax=1&ids=/);
    assert.match(src, /panel\.getAttribute\('data-target'\)/);
});

test('smack-longform-image-blocks keeps handle inside stage (SS-1625) and re-derives ordinal bounds (SS-1626)', () => {
    assert.match(src, /right:0;top:0;width:16px/);
    assert.doesNotMatch(src, /right:-8px/);
    assert.match(src, /ordinal:\s*blocks\.length/);
    assert.match(src, /function currentBounds\(block\)/);
    assert.match(src, /live\.id !== block\.id \|\| live\.gallery !== block\.gallery/);
});

// ===== SNAPSMACK EOF =====
