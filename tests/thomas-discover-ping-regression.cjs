// THE THOMAS DISCOVER PING EMITS EXACTLY ONE REQUEST WITH ONLY THE DOCUMENTED
// FIELDS. (Sean chose to restore the ping, 08:16 2026-11-08.)
//
// Loads the real assets/js/ss-engine-thomas.js in a sandbox, puts the anonymous
// install id on the hidden #ss-thomas-uid data attribute the CMS prints
// (core/thomas-uid.php), and dispatches the real Ctrl+Shift+Y / Ctrl+Shift+Z
// key events. It asserts:
//   - a discovery emits exactly ONE request, to the documented receiver,
//   - carrying ONLY uid, t and s -- the exact payload tnb.php describes,
//   - the uid is the one from the data attribute (never a URL or site name),
//   - a second Y press does NOT re-ping (one discovery, one count),
//   - with no id present, nothing is sent at all.
const fs = require('fs'), vm = require('vm'), assert = require('assert');

const UID = 'abcdef0123456789abcdef0123456789'; // 32 hex, as the CMS emits

function run(uidValue) {
    const sent = [];
    const stubEl = () => ({
        style: {}, classList: { add() {}, remove() {} }, dataset: {},
        setAttribute() {}, appendChild() {}, removeChild() {}, remove() {},
        addEventListener() {}, querySelector: () => null, querySelectorAll: () => [],
        innerHTML: '', id: '', className: '', textContent: '',
        getAttribute(n) { return n === 'data-uid' ? uidValue : null; },
    });
    const uidEl = uidValue === null ? null : stubEl();
    const docEvents = {};
    const document = {
        getElementById: (id) => (id === 'ss-thomas-uid' ? uidEl : null),
        createElement: () => stubEl(),
        querySelectorAll: () => [],
        getElementsByTagName: () => [],
        body: stubEl(),
        documentElement: stubEl(),
        addEventListener: (n, f) => { docEvents[n] = f; },
    };
    const store = {};
    const sandbox = {
        window: { addEventListener() {} },
        document,
        navigator: {},
        sessionStorage: { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); } },
        getComputedStyle: () => new Proxy({ getPropertyValue: () => '' }, { get: (t, p) => (p in t ? t[p] : '') }),
        setTimeout: () => 0,
        Image: class { set src(v) { sent.push(v); } },
        encodeURIComponent, Math, Date, JSON, parseInt,
    };
    vm.runInNewContext(fs.readFileSync('assets/js/ss-engine-thomas.js', 'utf8'), sandbox);
    const press = (key) => docEvents.keydown({ ctrlKey: true, shiftKey: true, key, preventDefault() {} });
    return { sent, press };
}

function parsePing(url) {
    const base = 'https://snapsmack.ca/releases/thomas-ping.php?';
    assert(url.startsWith(base), `ping must hit the documented receiver, got: ${url}`);
    const params = new URLSearchParams(url.slice(base.length));
    const keys = [...params.keys()].sort();
    assert.deepEqual(keys, ['s', 't', 'uid'], `ping carries ONLY uid,t,s -- got: ${keys.join(',')}`);
    return params;
}

// 1. A discovery (Ctrl+Shift+Y) emits exactly one ping with the documented fields.
let { sent, press } = run(UID);
press('y');
assert.equal(sent.length, 1, `one discovery emits exactly one request, got ${sent.length}`);
let p = parsePing(sent[0]);
assert.equal(p.get('uid'), UID, 'ping uid is the anonymous install id from the data attribute');
assert.equal(p.get('t'), 'y', 'type marks which part was triggered (y = bear)');
assert(['0', '1'].includes(p.get('s')), 'first-in-session flag is 0 or 1');
assert.equal(p.get('s'), '1', 'the first discovery in a session is flagged s=1');

// 2. A SECOND Y press does not re-ping: one discovery, one count.
press('y');
assert.equal(sent.length, 1, `a second Y press must not emit another ping, got ${sent.length}`);

// 3. The Noah modal (Ctrl+Shift+Z) pings with t=z and nothing else.
({ sent, press } = run(UID));
press('z');
assert.equal(sent.length, 1, 'the Noah modal emits one ping');
assert.equal(parsePing(sent[0]).get('t'), 'z', 'type z = Noah modal opened');

// 4. With no id present, nothing is sent at all.
({ sent, press } = run(null));
press('y');
assert.equal(sent.length, 0, 'with no install id, the ping does not fire');

// 5. The engine references nothing that identifies the install.
const src = fs.readFileSync('assets/js/ss-engine-thomas.js', 'utf8');
for (const ident of ['location.href', 'location.hostname', 'document.domain', 'document.referrer', 'site_url', 'site_name']) {
    assert(!src.includes(ident), `engine must not reference identifying data: ${ident}`);
}

console.log('PASS Thomas discover ping: one request, only uid/t/s, no identifying data, no id => silent');
