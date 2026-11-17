// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT
  || path.join(root, '_continuity/evidence/ss-1356-tilez-decorative');

const html = `<!doctype html>
<html><body class="page tilez-page-view tilez-v2"><div id="page">
<main class="content tilez-page"><article class="page-container post-inner">
<div class="page-content post-content entry-content">
<p>Closing copy in the page reading lane.</p>
<div class="snap-inline-frame snap-decorative align-center" style="--snap-image-width:100%">
<div class="ip-ascii-frame-inner"><img class="full snap-decorative-img"
src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%221200%22 height=%22300%22%3E%3Crect width=%221200%22 height=%22300%22 fill=%22%23ddd%22/%3E%3C/svg%3E"
alt="Author signature" width="1200" height="300" loading="lazy"></div></div>
<p>February 01, 2024.</p></div>
<aside class="page-record"><header class="post-header"><h1 class="post-title">THE IDEA</h1></header></aside>
</article></main></div></body></html>`;

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    for (const width of [3840, 1920, 1440, 1024]) {
      const page = await browser.newPage({ viewport: { width, height: 1080 } });
      await page.setContent(html);
      // Reproduce the public load order with shared shortcode rules following
      // the skin. The TILEZ rule must remain authoritative in that order.
      for (const file of ['assets/css/public-base.css', 'skins/tilez/style.css', 'assets/css/shortcodes.css']) {
        await page.addStyleTag({ content: read(file) });
      }
      await page.locator('.snap-decorative-img').evaluate(image => image.decode());
      const measured = await page.evaluate(() => {
        const frame = document.querySelector('.snap-decorative');
        const image = document.querySelector('.snap-decorative-img');
        const lane = document.querySelector('.page-content');
        const frameBox = frame.getBoundingClientRect();
        const imageBox = image.getBoundingClientRect();
        const laneBox = lane.getBoundingClientRect();
        const style = getComputedStyle(image);
        return {
          frame: { x: frameBox.x, width: frameBox.width, right: frameBox.right },
          image: { x: imageBox.x, width: imageBox.width, right: imageBox.right },
          lane: { x: laneBox.x, width: laneBox.width, right: laneBox.right },
          cursor: style.cursor,
          hasLightboxHook: image.hasAttribute('data-lightbox-src'),
          overflow: document.documentElement.scrollWidth > innerWidth,
        };
      });
      assert.ok(measured.frame.width <= 340.5, `${width}: decorative frame is ${measured.frame.width}px wide`);
      assert.ok(measured.frame.width <= measured.lane.width * 0.72 + 1, `${width}: decorative frame exceeds 72% of reading lane`);
      assert.ok(Math.abs(measured.frame.x - measured.lane.x) <= 1, `${width}: decorative mark is not left-aligned to reading lane`);
      assert.ok(Math.abs(measured.image.width - measured.frame.width) <= 1, `${width}: image does not fill decorative frame`);
      assert.equal(measured.hasLightboxHook, false, `${width}: decorative mark entered lightbox sequence`);
      assert.notEqual(measured.cursor, 'zoom-in', `${width}: decorative mark advertises a lightbox`);
      assert.equal(measured.overflow, false, `${width}: horizontal overflow`);
      await page.screenshot({ path: path.join(output, `tilez-decorative-${width}.png`), fullPage: true });
      results.push({ width, ...measured });
      await page.close();
    }
    fs.writeFileSync(path.join(output, 'measurements.json'), JSON.stringify({
      note: 'Local TILEZ fixture using the SS-1356 decorative parser markup. Installed build and owner acceptance are not checked.',
      results,
    }, null, 2));
    console.log(`PASS: TILEZ decorative mark geometry and non-lightbox affordance at 3840/1920/1440/1024. ${output}`);
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
