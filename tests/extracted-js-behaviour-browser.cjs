// Behaviour check for the JavaScript extracted out of PHP (SS-1041).
//
// Served over http, not file://, because document.cookie does nothing on a
// file:// origin -- the first run of this reported the archive cookies as
// missing when the harness was what could not write them. And the assertions
// run after load, because the first run also fired them at parse time, before
// the deferred script had wired anything, and reported the installer helpers
// as dead when they were merely not awake yet.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
// Codex owns tools/browser-tests. runtime.cjs is the seam he published for
// exactly this: resolve the browser from there, never reach into node_modules.
const { chromium } = require(path.join(__dirname, '..', 'tools', 'browser-tests', 'runtime.cjs'));

const ROOT = path.resolve(__dirname, '..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };

const server = http.createServer((req, res) => {
  let rel = decodeURIComponent(req.url.split('?')[0]);
  if (rel === '/') rel = '/tests/fixtures/extracted-js-harness.html';
  const file = path.join(ROOT, rel);
  if (!file.startsWith(path.join(ROOT))) { res.writeHead(403).end(); return; }
  fs.readFile(file, (err, buf) => {
    if (err) { res.writeHead(404).end('not found: ' + rel); return; }
    res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' });
    res.end(buf);
  });
});

server.listen(0, '127.0.0.1', async () => {
  const port = server.address().port;
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const page = await browser.newPage();
  const errs = [];
  page.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
  page.on('pageerror', e => errs.push('pageerror: ' + e.message));

  await page.goto(`http://127.0.0.1:${port}/tests/fixtures/extracted-js-harness.html`,
    { waitUntil: 'load' });

  const results = await page.evaluate(() => {
    const out = [];
    const t = (name, ok, got) => out.push({ name, ok: !!ok, got: String(got) });
    const root = document.documentElement;

    // ss-archive-state.js -- three server values arriving as data attributes
    t('archive: <html> data-archive-layout comes from data-layout', root.getAttribute('data-archive-layout') === 'masonry', root.getAttribute('data-archive-layout'));
    t('archive: data-archive-calendar reflects the open state', root.getAttribute('data-archive-calendar') === 'open', root.getAttribute('data-archive-calendar'));
    t('archive: the layout cookie is written', /smack_archive_layout=masonry/.test(document.cookie), document.cookie || '(none)');
    t('archive: the calendar cookie is written', /smack_archive_calendar=open/.test(document.cookie), document.cookie || '(none)');
    t('archive: localStorage mirrors the layout', localStorage.getItem('smack_archive_layout') === 'masonry', localStorage.getItem('smack_archive_layout'));
    t('archive: localStorage mirrors the calendar', localStorage.getItem('smack_archive_calendar') === 'open', localStorage.getItem('smack_archive_calendar'));

    // ss-config-bootstrap.js -- the two globals meta.php used to set inline
    t('config: window.SNAP_BASE_URL is set', window.SNAP_BASE_URL === 'https://example.test/', window.SNAP_BASE_URL);
    t('config: window.SNAP_SITE_MODE is set', window.SNAP_SITE_MODE === 'carousel', window.SNAP_SITE_MODE);

    // ss-install-forms.js -- the SHOW/HIDE toggle, now wired by data attribute
    const p1 = document.getElementById('admin_pass');
    const p2 = document.getElementById('admin_pass2');
    const toggle = document.querySelector('[data-toggle-pass]');
    toggle.click();
    t('install: SHOW reveals the password', p1.type === 'text' && toggle.textContent === 'HIDE', p1.type + ' / ' + toggle.textContent);
    toggle.click();
    t('install: HIDE conceals it again', p1.type === 'password' && toggle.textContent === 'SHOW', p1.type + ' / ' + toggle.textContent);

    // ss-install-forms.js -- the match check, now classed not styled inline
    const msg = document.getElementById('pass-match-msg');
    const btn = document.getElementById('btn-finish');
    p1.value = 'abc'; p2.value = 'abd'; p2.dispatchEvent(new Event('input'));
    t('install: a mismatch is reported', !msg.hidden && msg.className.includes('hint--bad'), msg.className + ' hidden=' + msg.hidden);
    t('install: and coloured from the stylesheet, not element.style', getComputedStyle(msg).color === 'rgb(176, 0, 32)', getComputedStyle(msg).color);
    t('install: a mismatch blocks the submit', btn.disabled === true, btn.disabled);
    p2.value = 'abc'; p2.dispatchEvent(new Event('input'));
    t('install: a match is reported', !msg.hidden && msg.className.includes('hint--ok'), msg.className);
    t('install: a match frees the submit', btn.disabled === false, btn.disabled);
    p2.value = ''; p2.dispatchEvent(new Event('input'));
    t('install: an empty confirmation says nothing', msg.hidden === true, msg.hidden);

    // ss-install-forms.js -- the Break-Glass gate, now a class not element.style
    const link = document.getElementById('install-login-link');
    const saved = document.getElementById('break-glass-saved');
    t('install: Log In starts dead', getComputedStyle(link).pointerEvents === 'none', getComputedStyle(link).pointerEvents);
    t('install: the acknowledgement starts disabled', saved.disabled === true, saved.disabled);
    document.getElementById('break-glass-download').click();
    t('install: downloading the card frees the acknowledgement', saved.disabled === false, saved.disabled);
    saved.checked = true; saved.dispatchEvent(new Event('change'));
    t('install: acknowledging it arms Log In', getComputedStyle(link).pointerEvents === 'auto', getComputedStyle(link).pointerEvents);
    saved.checked = false; saved.dispatchEvent(new Event('change'));
    t('install: un-ticking kills it again', getComputedStyle(link).pointerEvents === 'none', getComputedStyle(link).pointerEvents);
    return out;
  });

  for (const r of results) {
    console.log((r.ok ? '  ok    ' : '  FAIL  ') + r.name + '   [' + r.got + ']');
  }
  const ok = results.filter(r => r.ok).length;
  console.log('\n  ' + ok + ' / ' + results.length + ' passed');
  if (errs.length) { console.log('\n  console errors:'); errs.forEach(e => console.log('    ' + e)); }
  await browser.close();
  server.close();
  process.exit(ok === results.length ? 0 : 1);
});
