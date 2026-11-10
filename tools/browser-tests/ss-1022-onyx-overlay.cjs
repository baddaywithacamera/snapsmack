// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const out = process.env.SNAPSMACK_BROWSER_OUTPUT
  || path.join(root, '_continuity/evidence/ss-1022-onyx-overlay');
const css = fs.readFileSync(path.join(root, 'skins/onyx/style.css'), 'utf8');
fs.mkdirSync(out, { recursive: true });

const staticMarkup = `
  <body class="onyx-v2">
    <div id="page-wrapper"><div id="scroll-stage">
      <div id="pfd-header"><div class="pfd-header-inside">
        <div class="logo-area"><a><h1 class="site-title-text">ONYX</h1></a></div>
      </div></div>
      <div class="static-content">
        <h1 class="static-page-title">About</h1>
        <div class="description">
          <p>Static page text must remain above the fixed vignette.</p>
          <p>The title and body stay readable at every supported width.</p>
        </div>
      </div>
    </div></div>
  </body>`;

const archiveMarkup = `
  <body class="onyx-v2">
    <div id="page-wrapper"><div id="scroll-stage">
      <div id="pfd-header"><div class="pfd-header-inside">
        <div class="logo-area"><a><h1 class="site-title-text">ONYX</h1></a></div>
      </div></div>
      <section id="justified-grid" class="ss-scroll-wall">
        <article class="wall-tile"><div class="archive-thumb-placeholder">Archive tile one</div></article>
        <article class="wall-tile"><div class="archive-thumb-placeholder">Archive tile two</div></article>
      </section>
    </div></div>
  </body>`;

const fixtureStyle = `
  :root { --pfd-vignette: 100; --pfd-red: #d40000; }
  .archive-thumb-placeholder {
    width: 360px; height: 260px; padding: 24px; margin: 24px;
    background: #f4f4f4; color: #111; font: 700 24px Arial, sans-serif;
  }
  .simulate-missing-static-lift #page-wrapper,
  .simulate-missing-static-lift #scroll-stage,
  .simulate-missing-static-lift .static-content {
    position: static !important;
    z-index: auto !important;
  }
`;

function rect(r) {
  return { x: r.x, y: r.y, width: r.width, height: r.height };
}

async function render(browser, width, kind, mutation = false) {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  await page.setContent(kind === 'static' ? staticMarkup : archiveMarkup);
  await page.addStyleTag({ content: css });
  await page.addStyleTag({ content: fixtureStyle });
  if (mutation && kind === 'static') {
    await page.locator('body').evaluate(body => body.classList.add('simulate-missing-static-lift'));
  }
  const geometry = await page.evaluate(kindName => {
    const wrapper = document.querySelector('#page-wrapper');
    const stage = document.querySelector('#scroll-stage');
    const content = document.querySelector('.static-content');
    const title = document.querySelector('.static-page-title');
    const bodyText = document.querySelector('.description');
    const overlay = getComputedStyle(document.body, '::before');
    const style = element => element ? getComputedStyle(element) : null;
    const box = element => element ? element.getBoundingClientRect().toJSON() : null;
    return {
      kind: kindName,
      overlay: { position: overlay.position, zIndex: overlay.zIndex, pointerEvents: overlay.pointerEvents },
      wrapper: { position: style(wrapper).position, zIndex: style(wrapper).zIndex },
      stage: { position: style(stage).position, zIndex: style(stage).zIndex },
      content: content ? { position: style(content).position, zIndex: style(content).zIndex, box: box(content) } : null,
      title: title ? { color: style(title).color, box: box(title) } : null,
      bodyText: bodyText ? { color: style(bodyText).color, box: box(bodyText) } : null,
      viewport: { width: innerWidth, height: innerHeight },
      overflow: document.documentElement.scrollWidth > innerWidth,
    };
  }, kind);
  const suffix = mutation ? 'without-lift' : 'current';
  const screenshotPath = path.join(out, `${kind}-${width}-${suffix}.png`);
  const screenshot = await page.screenshot({ path: screenshotPath });
  await page.close();
  return { geometry, screenshot, screenshotPath };
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    for (const width of [1920, 1440, 1024]) {
      const current = await render(browser, width, 'static');
      const withoutLift = await render(browser, width, 'static', true);
      const archiveCurrent = await render(browser, width, 'archive');
      const archiveStaticMutation = await render(browser, width, 'archive', true);

      assert.deepEqual(current.geometry.overlay,
        { position: 'fixed', zIndex: '0', pointerEvents: 'none' },
        `${width}: vignette contract changed`);
      assert.equal(current.geometry.wrapper.position, 'relative', `${width}: wrapper must establish stacking`);
      assert.equal(current.geometry.wrapper.zIndex, '1', `${width}: wrapper must sit above vignette`);
      assert.equal(current.geometry.stage.position, 'relative', `${width}: stage must establish stacking`);
      assert.equal(current.geometry.stage.zIndex, '1', `${width}: stage must sit above vignette`);
      assert.equal(current.geometry.content.position, 'relative', `${width}: static content must establish stacking`);
      assert.equal(current.geometry.content.zIndex, '1', `${width}: static content must sit above vignette`);
      assert.equal(current.geometry.title.color, 'rgb(244, 244, 244)', `${width}: title colour changed`);
      assert.equal(current.geometry.bodyText.color, 'rgb(244, 244, 244)', `${width}: body colour changed`);
      assert.equal(current.geometry.overflow, false, `${width}: static page overflows horizontally`);

      assert.deepEqual(rect(current.geometry.title.box), rect(withoutLift.geometry.title.box),
        `${width}: lift mutation must not change title geometry`);
      assert.deepEqual(rect(current.geometry.bodyText.box), rect(withoutLift.geometry.bodyText.box),
        `${width}: lift mutation must not change body geometry`);
      assert.notEqual(Buffer.compare(current.screenshot, withoutLift.screenshot), 0,
        `${width}: removing every static lift must visibly reproduce the overlay defect`);

      assert.deepEqual(archiveCurrent.geometry, archiveStaticMutation.geometry,
        `${width}: static-page mutation must not change archive geometry`);
      assert.equal(Buffer.compare(archiveCurrent.screenshot, archiveStaticMutation.screenshot), 0,
        `${width}: static-page mutation must not change archive pixels`);
      results.push({ width, current: current.geometry, withoutLift: withoutLift.geometry,
        archive: archiveCurrent.geometry, screenshots: {
          current: current.screenshotPath,
          withoutLift: withoutLift.screenshotPath,
          archive: archiveCurrent.screenshotPath,
        } });
    }
    fs.writeFileSync(path.join(out, 'measurements.json'), JSON.stringify({
      note: 'Rendered from the current ONYX stylesheet. The without-lift case is a deliberate browser-only mutation; no product CSS was changed.',
      results,
    }, null, 2));
    console.log(`PASS SS-1022: current ONYX static title/body are above the vignette at 1920/1440/1024; deliberate no-lift mutation reproduces the visual defect; archive pixels are unchanged. ${out}`);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
// ===== SNAPSMACK EOF =====
