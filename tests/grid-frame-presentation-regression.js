'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function frame(dataset) {
    const values = {};
    return {
        nodeType: 1,
        dataset,
        style: { setProperty(name, value) { values[name] = value; } },
        matches(selector) { return selector === '[data-frame-size]'; },
        querySelectorAll() { return []; },
        values
    };
}

const initial = frame({
    frameSize: '76%', frameBorderWidth: '2px', frameBorderColor: '#ff0000',
    frameBackground: '#ffffff', frameShadow: '2'
});
let mutationCallback = null;
const document = {
    nodeType: 9,
    readyState: 'complete',
    body: {},
    querySelectorAll(selector) { return selector === '[data-frame-size]' ? [initial] : []; },
    querySelector() { return null; }
};
class MutationObserver {
    constructor(callback) { mutationCallback = callback; }
    observe() {}
}

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'ss-engine-grid-nav.js'), 'utf8');
vm.runInNewContext(source, { document, window: { MutationObserver }, MutationObserver, console });

assert.equal(initial.values['--tile-img-size'], '76%');
assert.equal(initial.values['--tile-border-w'], '2px');
assert.equal(initial.values['--tile-border-c'], '#ff0000');
assert.equal(initial.values['--tile-bg'], '#ffffff');
assert.equal(initial.values['--tile-shadow'], '6px 6px 18px rgba(0,0,0,.40)');
assert.equal(initial.values['--slide-img-size'], '76%');

const appended = frame({
    frameSize: '82%', frameBorderWidth: '6px', frameBorderColor: '#123456',
    frameBackground: '#abcdef', frameShadow: '3'
});
mutationCallback([{ addedNodes: [appended] }]);
assert.equal(appended.values['--tile-img-size'], '82%');
assert.equal(appended.values['--tile-border-w'], '6px');
assert.equal(appended.values['--tile-border-c'], '#123456');
assert.equal(appended.values['--tile-bg'], '#abcdef');
assert.equal(appended.values['--tile-shadow'], '12px 12px 32px rgba(0,0,0,.60)');

const hostile = frame({
    frameSize: '9999%', frameBorderWidth: '9999px', frameBorderColor: 'url(bad)',
    frameBackground: 'expression(bad)', frameShadow: '99'
});
mutationCallback([{ addedNodes: [hostile] }]);
assert.equal(hostile.values['--tile-img-size'], '100%');
assert.equal(hostile.values['--tile-border-w'], '0px');
assert.equal(hostile.values['--tile-border-c'], '#000000');
assert.equal(hostile.values['--tile-bg'], '#ffffff');
assert.equal(hostile.values['--tile-shadow'], 'none');

console.log('GRID frame presentation regression passed.');
