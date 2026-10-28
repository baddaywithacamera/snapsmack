// SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
// The visible outline must fit the reading lane, not merely the img box.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');
const root = path.resolve(__dirname, '../..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    for (const width of [1920, 390]) {
      const page = await browser.newPage({ viewport: { width, height: 1080 } });
      await page.setContent('<body class="static-transmission"><main id="scroll-stage">'
        + '<div id="photobox" class="page-hero"><div class="main-photo">'
        + '<img class="post-image" alt="Geometry fixture" width="1200" height="800" '
        + 'src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%221200%22 height=%22800%22/%3E">'
        + '</div></div><div class="static-content"><h1 class="static-page-title">About</h1>'
        + '<div class="description">Reading column</div></div></main></body>');
      // Reproduce the real load order: shared static defaults follow the skin.
      for (const file of ['assets/css/public-base.css', 'skins/rational-geo/style.css', 'assets/css/page-static.css']) {
        await page.addStyleTag({ content: read(file) });
      }
      await page.evaluate(() => document.querySelector('img').decode());
      const edges = () => page.evaluate(() => {
        const image = document.querySelector('img.post-image');
        const box = image.getBoundingClientRect();
        const text = document.querySelector('.description').getBoundingClientRect();
        const outline = parseFloat(getComputedStyle(image).outlineWidth);
        return { left: box.left - outline, right: box.right + outline,
          textLeft: text.left, textRight: text.right,
          overflow: document.documentElement.scrollWidth > innerWidth };
      });
      const aligned = value => Math.abs(value.left - value.textLeft) <= 1
        && Math.abs(value.right - value.textRight) <= 1 && !value.overflow;
      for (const border of [0, 10, 25, 50]) {
        const setting = await page.addStyleTag({ content: `body.static-transmission { --rg-hero-border: ${border}px; }` });
        assert.ok(aligned(await edges()), `${width}px / ${border}px visible frame misaligned`);
        await setting.evaluate(element => element.remove());
      }
      // Prove the old padding:0 defect cannot pass this acceptance check.
      await page.addStyleTag({ content: 'body.static-transmission { --rg-hero-border:25px } .static-transmission .main-photo { padding:0!important }' });
      assert.equal(aligned(await edges()), false, 'old outline overflow escaped detection');
      await page.close();
      console.log(`PASS ${width}px: visible frame edges, four border settings; old defect rejected`);
    }
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });

// ===== SNAPSMACK EOF =====
