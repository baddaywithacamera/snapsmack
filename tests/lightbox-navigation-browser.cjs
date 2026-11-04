// SNAPSMACK_EOF_HEADER
//
// Browser regression: an open lightbox owns the arrow keys and the spacebar.
// SS-1202.
//
// THE FAULT. ss-engine-comms.js (the hotkey engine) sets window.location.href
// on ArrowLeft / ArrowRight / Space. The lightbox engines bind the SAME keys
// and move within the open viewer. Nothing told the hotkey engine to stand
// down, so one key press did BOTH: the viewer advanced, and the page navigated
// to the previous or next photograph. The page load tore the viewer down,
// which is exactly the reported symptom -- the lightbox "drops back to the
// single photograph on every move".
//
// WHY THIS IS A BROWSER TEST AND NOT A UNIT TEST. The bug is two independent
// listeners reacting to one real key event, and the damage is a real document
// navigation. Nothing short of a browser pressing a real key can show it: a
// stubbed navigation would have hidden the very thing that was wrong.
//
// WHAT IT PINS, in both directions:
//   1. with NO viewer open, the keys still navigate the page (the behaviour
//      the owner says works today and must keep working)
//   2. with a viewer open, the keys do NOT navigate, and the viewer survives
//   3. after the viewer closes, the keys navigate again -- the guard must not
//      deaden them permanently
//
// Run: node tests/lightbox-navigation-browser.cjs
const { chromium } = require('../tools/browser-tests/runtime.cjs');
const fs = require('node:fs');
const path = require('node:path');

const engine = name => fs.readFileSync(
    path.join(__dirname, '../assets/js', name), 'utf8');

// A solo photograph page as the CMS renders one: rel prev/next links and the
// #snap-nav-data element, which is what ssNavUrl actually reads.
const PAGE = `<!doctype html><html><head><meta charset="utf-8"><title>solo</title>
<link rel="prev" href="/photo-prev"><link rel="next" href="/photo-next">
<style>.post-image{width:200px;height:150px;background:#888;display:block}</style>
</head><body>
<a rel="prev" href="/photo-prev">prev</a><a rel="next" href="/photo-next">next</a>
<div id="snap-nav-data" data-prev="/photo-prev" data-next="/photo-next"></div>
<img class="post-image" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==">
<img class="post-image" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==">
<img class="post-image" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==">
</body></html>`;

let failures = 0;
const cx = (ok, what) => {
    if (!ok) failures++;
    console.log((ok ? '  ok    ' : '  FAIL  ') + what);
};

// Wait for the navigation a key press STARTED, not for a load state that can
// resolve on the page we are still standing on. Written after the first
// version of this test reported three false failures for exactly that reason.
async function wentTo(page, suffix) {
    try { await page.waitForURL('**' + suffix, { timeout: 4000 }); return true; }
    catch (e) { return false; }
}

async function freshPage(ctx) {
    const page = await ctx.newPage();
    await page.route('**/*', route => {
        const url = route.request().url();
        if (url.includes('/photo-prev')) {
            return route.fulfill({ contentType: 'text/html', body: '<title>PREV PAGE</title>' });
        }
        if (url.includes('/photo-next')) {
            return route.fulfill({ contentType: 'text/html', body: '<title>NEXT PAGE</title>' });
        }
        return route.fulfill({ contentType: 'text/html', body: PAGE });
    });
    await page.goto('http://solo.test/photo');
    await page.addScriptTag({ content: engine('ss-engine-lightbox.js') });
    await page.addScriptTag({ content: engine('ss-engine-comms.js') });
    return page;
}

(async () => {
    const browser = await chromium.launch({ headless: true });
    const ctx = await browser.newContext();

    console.log('NO VIEWER OPEN -- the keys that work today must keep working');
    for (const [key, target, label] of [
        ['ArrowRight', '/photo-next', 'ArrowRight'],
        ['ArrowLeft',  '/photo-prev', 'ArrowLeft'],
        [' ',          '/photo-prev', 'Space'],
    ]) {
        const page = await freshPage(ctx);
        await page.keyboard.press(key);
        cx(await wentTo(page, target), label + ' still navigates the page');
        await page.close();
    }

    console.log('\nVIEWER OPEN -- it must move and must NOT be torn down');
    const page = await freshPage(ctx);
    await page.click('.post-image >> nth=0');
    await page.waitForTimeout(150);
    cx(await page.isVisible('#ss-lightbox-overlay'), 'the lightbox opened');

    let navigated = false;
    page.on('framenavigated', () => { navigated = true; });

    for (const key of ['ArrowRight', 'ArrowLeft', ' ']) {
        await page.keyboard.press(key);
        await page.waitForTimeout(250);
        cx(!navigated, (key === ' ' ? 'Space' : key) + ' did NOT navigate the page away');
        cx(await page.isVisible('#ss-lightbox-overlay'), '  and the lightbox is still open');
    }
    cx((await page.title()) === 'solo', 'still on the same photograph page throughout');

    console.log('\nAFTER CLOSING -- the keys must come back, not stay dead');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    cx(!(await page.isVisible('#ss-lightbox-overlay')), 'Escape closed the lightbox');
    await page.keyboard.press('ArrowRight');
    cx(await wentTo(page, '/photo-next'),
       'ArrowRight navigates again once the viewer is closed');
    await page.close();

    await browser.close();
    console.log(failures === 0
        ? '\nPASS - the viewer owns the keys while it is open, and only while it is open'
        : `\n${failures} CHECK(S) FAILED`);
    process.exitCode = failures ? 1 : 0;
})().catch(e => { console.error(e); process.exitCode = 1; });
// ===== SNAPSMACK EOF =====
