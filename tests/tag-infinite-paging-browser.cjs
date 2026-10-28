const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('../tools/browser-tests/runtime.cjs');
const engine = fs.readFileSync('assets/js/ss-engine-tag-infinite.js', 'utf8');
const trueGritCss = fs.readFileSync('skins/true-grit/style.css', 'utf8');
const baseCss = fs.readFileSync('assets/css/public-base.css', 'utf8');
(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    for (const mode of ['true-grit', 'feed', 'tag', 'justified', 'short', 'broken']) {
      const page = await browser.newPage({ viewport: { width: 1000, height: 700 } });
      const nested = mode === 'true-grit' || mode === 'broken';
      const prefix = mode === 'justified' ? 'justified' : 'tg';
      const requests = [];
      const batchSize = mode === 'short' ? 1 : 24;
      const total = batchSize * 4 + 7;
      function markup(n) {
        const first = (n - 1) * batchSize + 1;
        const last = Math.min(n * batchSize, total);
        let tiles = '';
        for (let id = first; id <= last; id++) tiles += `<article class="tg-tile justified-row" data-id="${id}">Photo ${id}</article>`;
        const sentinel = last < total ? `<div id="${prefix}-sentinel" class="ss-feed-sentinel" data-${mode === 'tag' ? 'tag="example"' : 'feed'} data-next="${n + 1}" data-base="https://paging.test/archive"></div>` : '';
        const grid = `<section id="${prefix === 'justified' ? 'justified-grid' : 'browse-grid'}" class="tg-grid square-grid">${tiles}${nested ? sentinel : ''}</section>${nested ? '' : sentinel}`;
        return nested ? `<div id="page-wrapper"><header id="tg-header">Header</header><main id="scroll-stage">${grid}</main><footer>Footer</footer></div>` : grid;
      }
      await page.route('https://paging.test/**', async route => {
        const n = Number(new URL(route.request().url()).searchParams.get('p') || 1);
        requests.push(n);
        await route.fulfill({ contentType: 'text/html', body: `<!doctype html><style>${baseCss}${nested ? trueGritCss : ''}
          .tg-grid {display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.tg-tile{height:${mode === 'short' ? 10 : 180}px}
          </style><body class="archive-page">${markup(n)}</body>` });
      });
      await page.goto('https://paging.test/archive');
      const code = mode === 'broken' ? engine.replace('grid.insertBefore(t, sentinel)', 'grid.appendChild(t)').replace('obs.unobserve(sentinel);', '').replace('obs.observe(sentinel);', '') : engine;
      await page.addScriptTag({ content: code });
      for (let step = 0; step < 70; step++) {
        await page.evaluate(nested => {
          const scroller = nested ? document.getElementById('scroll-stage') : document.scrollingElement;
          scroller.style.scrollBehavior = 'auto';
          scroller.scrollTop += 350;
        }, nested);
        await page.waitForTimeout(35);
        if (await page.locator('[data-id]').count() === total) break;
      }
      const ids = await page.locator('[data-id]').evaluateAll(nodes => nodes.map(n => Number(n.dataset.id)));
      if (mode === 'broken') {
        assert.ok(ids.length < total, 'old misplaced sentinel must reproduce incomplete archive');
      } else {
        assert.deepEqual(ids, Array.from({ length: total }, (_, i) => i + 1), mode + ' must reach every photo exactly once');
        assert.equal(await page.locator('[id$="-sentinel"]').count(), 0);
        assert.equal(new Set(requests).size, requests.length, 'no duplicate requests');
      }
      console.log(`PASS ${mode}: ${ids.length}/${total} photos${mode === 'broken' ? ' (expected regression)' : ''}`);
      await page.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
