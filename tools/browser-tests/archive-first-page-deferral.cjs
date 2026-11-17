const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
let chromium;
try {
  ({ chromium } = require('./runtime.cjs'));
} catch (error) {
  if (error.code !== 'MODULE_NOT_FOUND') throw error;
  ({ chromium } = require('playwright'));
}

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

const repoRoot = path.resolve(__dirname, '../..');
const productRoot = path.resolve(process.env.SNAPSMACK_BROWSER_PRODUCT_ROOT || repoRoot);
const expectDeferral = process.env.SNAPSMACK_EXPECT_PAGE2_DEFERRAL !== '0';
const origin = 'https://archive-deferral.test';

function wait(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function svg(label) {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="900" height="600"><rect width="900" height="600" fill="#334"/><text x="30" y="60" fill="white">${label}</text></svg>`;
}

function fixtureHtml(engine, scenario) {
  const wallClass = engine === 'columns' ? 'ss-masonry' : 'ss-scroll-wall';
  const tileCount = scenario === 'no-images' ? 0 : 12;
  const tiles = Array.from({ length: tileCount }, (_, index) => {
    const number = index + 1;
    return `<a class="ss-masonry-item" data-w="900" data-h="600"><img src="/media/page-1-${number}.svg" data-w="900" data-h="600" alt="Page one ${number}"></a>`;
  }).join('');
  return `<!doctype html>
<html><head><meta charset="utf-8"><style>
html,body{margin:0;padding:0}#wall{width:1000px;--ss-cols:4;--ss-gap:6px}.ss-masonry-item img{display:block;width:100%;height:100%}.scroll-wall-sentinel{height:1px}
</style><script>
document.addEventListener('DOMContentLoaded', function () {
  var imgs = document.querySelectorAll('#wall img');
  var remaining = imgs.length;
  if (!remaining) window.__firstScreenSettledAt = Date.now();
  function settled() {
    remaining -= 1;
    if (remaining <= 0 && !window.__firstScreenSettledAt) window.__firstScreenSettledAt = Date.now();
  }
  for (var i = 0; i < imgs.length; i += 1) {
    if (imgs[i].complete) settled();
    else {
      imgs[i].addEventListener('load', settled, { once: true });
      imgs[i].addEventListener('error', settled, { once: true });
    }
  }
});
</script></head><body>
<main id="wall" class="${wallClass}">${tiles}</main>
<div class="scroll-wall-sentinel" data-next="2" data-cutoff="fixture" data-has-more="1"></div>
<script src="/assets/js/ss-engine-${engine}.js"></script>
</body></html>`;
}

function nextPageHtml() {
  return Array.from({ length: 12 }, (_, index) => {
    const number = index + 1;
    return `<a class="ss-masonry-item" data-w="900" data-h="600"><img src="/media/page-2-${number}.svg" data-w="900" data-h="600" alt="Page two ${number}"></a>`;
  }).join('');
}

function nextPageDocument(engine) {
  const wallClass = engine === 'columns' ? 'ss-masonry' : 'ss-scroll-wall';
  return `<!doctype html><html><body><main id="wall" class="${wallClass}">${nextPageHtml()}</main></body></html>`;
}

async function waitForRequest(readTimestamp, timeout = 4000) {
  const started = Date.now();
  while (readTimestamp() === null && Date.now() - started < timeout) await wait(20);
  return readTimestamp();
}

async function runScenario(browser, engine, scenario) {
  const context = await browser.newContext({ viewport: { width: 1920, height: 900 } });
  const page = await context.newPage();
  let pageTwoRequestedAt = null;
  const pageOneDelay = scenario === 'scroll' ? 3000 : 700;

  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== origin) return route.fulfill({ status: 204, body: '' });
    if (request.resourceType() === 'document') {
      return route.fulfill({ contentType: 'text/html; charset=utf-8', body: fixtureHtml(engine, scenario) });
    }
    if (url.pathname === `/assets/js/ss-engine-${engine}.js`) {
      return route.fulfill({
        contentType: 'application/javascript; charset=utf-8',
        body: fs.readFileSync(path.join(productRoot, 'assets/js', `ss-engine-${engine}.js`), 'utf8'),
      });
    }
    if ((url.searchParams.get('format') === 'json' && url.searchParams.get('pg') === 'wall')
        || (request.resourceType() === 'fetch' && url.searchParams.has('page'))) {
      if (pageTwoRequestedAt === null) pageTwoRequestedAt = Date.now();
      if (engine === 'columns') {
        return route.fulfill({
          contentType: 'text/html; charset=utf-8',
          body: nextPageDocument(engine),
        });
      }
      return route.fulfill({
        contentType: 'application/json; charset=utf-8',
        body: JSON.stringify({ html: nextPageHtml(), next: 3, has_more: false }),
      });
    }
    if (url.pathname.startsWith('/media/page-1-')) {
      if (scenario === 'timeout') return new Promise(() => {});
      if (scenario === 'error') {
        await wait(180);
        return route.fulfill({ status: 404, body: '' });
      }
      await wait(pageOneDelay);
      return route.fulfill({ contentType: 'image/svg+xml', body: svg(url.pathname) });
    }
    if (url.pathname.startsWith('/media/page-2-')) {
      return route.fulfill({ contentType: 'image/svg+xml', body: svg(url.pathname) });
    }
    return route.fulfill({ status: 404, body: '' });
  });

  await page.goto(`${origin}/archive?layout=wall`, { waitUntil: 'domcontentloaded' });
  if (scenario === 'scroll') {
    await wait(120);
    assert.equal(pageTwoRequestedAt, null,
      `${engine}: page two was requested before page one painted or the reader scrolled`);
    await page.evaluate(() => window.dispatchEvent(new Event('scroll')));
    const requestAt = await waitForRequest(() => pageTwoRequestedAt, 1000);
    assert.ok(requestAt !== null, `${engine}: scroll did not release the held page-two request`);
    const settledAt = await page.evaluate(() => window.__firstScreenSettledAt || null);
    assert.equal(settledAt, null, `${engine}: scroll evidence arrived after page one had already settled`);
    await context.close();
    return { engine, scenario, pageTwoRequestedAt: requestAt, firstScreenSettledAt: settledAt };
  }

  if (scenario === 'timeout') {
    await wait(300);
    assert.equal(pageTwoRequestedAt, null,
      `${engine}: timeout case requested page two before the 2.5s fail-open timer`);
    const requestAt = await waitForRequest(() => pageTwoRequestedAt, 3000);
    assert.ok(requestAt !== null, `${engine}: timeout did not release the held page-two request`);
    const settledAt = await page.evaluate(() => window.__firstScreenSettledAt || null);
    assert.equal(settledAt, null, `${engine}: timeout evidence unexpectedly settled the blocked images`);
    await context.close();
    return { engine, scenario, pageTwoRequestedAt: requestAt, firstScreenSettledAt: settledAt };
  }

  await page.waitForFunction(() => window.__firstScreenSettledAt !== undefined, null, { timeout: 2500 });
  const settledAt = await page.evaluate(() => window.__firstScreenSettledAt);
  const requestAt = await waitForRequest(() => pageTwoRequestedAt, 1000);
  const state = await page.evaluate(() => ({
    engineReady: Boolean(window.SSRows || window.SSColumns),
    wallHeight: document.querySelector('#wall')?.getBoundingClientRect().height || 0,
    sentinelTop: document.querySelector('.scroll-wall-sentinel')?.getBoundingClientRect().top || 0,
    hasMore: document.querySelector('.scroll-wall-sentinel')?.getAttribute('data-has-more') || '',
  }));
  assert.ok(requestAt !== null,
    `${engine}: page two was never requested after page one settled; state=${JSON.stringify(state)}`);
  const wasDeferred = requestAt >= settledAt;
  assert.equal(wasDeferred, expectDeferral,
    `${engine}: page-two request ${requestAt} and first-screen settlement ${settledAt} did not match expected deferral=${expectDeferral}`);
  await context.close();
  return { engine, scenario, pageTwoRequestedAt: requestAt, firstScreenSettledAt: settledAt, wasDeferred };
}

(async () => {
  const launchOptions = { headless: true };
  if (process.env.SNAPSMACK_BROWSER_EXECUTABLE) {
    launchOptions.executablePath = process.env.SNAPSMACK_BROWSER_EXECUTABLE;
  }
  const browser = await chromium.launch(launchOptions);
  const rows = [];
  try {
    for (const engine of ['rows', 'columns']) {
      rows.push(await runScenario(browser, engine, 'paint'));
      if (expectDeferral) {
        rows.push(await runScenario(browser, engine, 'scroll'));
        rows.push(await runScenario(browser, engine, 'timeout'));
        rows.push(await runScenario(browser, engine, 'no-images'));
        rows.push(await runScenario(browser, engine, 'error'));
      }
    }
  } finally {
    await browser.close();
  }
  for (const row of rows) {
    console.log(`PASS ${row.engine} ${row.scenario}: page2=${row.pageTwoRequestedAt}, first-screen-settled=${row.firstScreenSettledAt}`);
  }
  console.log(`PASS: archive page-two fetch ordering matches expected deferral=${expectDeferral} in ${productRoot}`);
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
