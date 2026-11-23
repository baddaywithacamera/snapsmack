// SNAPSMACK_EOF_HEADER: end this file with the canonical SNAPSMACK EOF marker.
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'skins/glide/manifest.json'), 'utf8'));
const engine = fs.readFileSync(path.join(root, 'assets/js/ss-engine-glide.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'skins/glide/style.css'), 'utf8');

const option = manifest.options.glide_wall_orientation;
assert.equal(option.type, 'select', 'wall orientation is a declared skin setting');
assert.equal(option.default, 'current', 'existing sites keep the current orientation');
assert.deepEqual(Object.keys(option.options), ['current', 'mirrored', 'upright'],
    'the setting declares current, mirrored, and upright modes');
assert.equal(option.selector, '.glide-page');
assert.equal(option.property, '--glide-wall-orientation');

assert.match(engine, /\['current', 'mirrored', 'upright'\]\.indexOf\(declaredOrientation\)/,
    'the shared engine allowlists declared orientation values');
assert.match(engine, /orientation === 'mirrored' \? -configuredFine : configuredFine/,
    'mirrored mode reverses the configured lean');
assert.match(engine, /orientation === 'upright' \? -fieldAngle \+ 'deg' : '0deg'/,
    'upright mode counter-rotates each tile by the complete field angle');
assert.match(css, /rotate\(var\(--glide-tile-counter-angle, 0deg\)\)/,
    'the skin applies the engine-provided tile counter-rotation');
assert.match(css, /var\(--glide-effective-angle, var\(--glide-angle\)\)/,
    'the pre-engine fallback is the existing configured angle');
assert.match(css, /--glide-wall-orientation-active: var\(--glide-wall-orientation, current\)/,
    'the generated setting reaches a skin-owned consumed variable with a current-mode fallback');
assert.match(engine, /var reduced = window\.matchMedia\('\(prefers-reduced-motion: reduce\)'\)\.matches/,
    'the existing reduced-motion gate remains in the shared engine');

console.log('PASS GLIDE wall orientation is declared, validated, and default-compatible');
// ===== SNAPSMACK EOF =====
