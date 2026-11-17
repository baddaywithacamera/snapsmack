/**
 * SS-1383 — RATIONAL GEO archive clearance.
 *
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 *
 * The columns engine positions the first tile from the grid's top edge. This
 * check loads that real engine, then compares the rendered archive frame gap
 * with the visible outer frame gap on the About-page fixture at every
 * supported width.
 *
 * Usage:
 *   node rationalgeo-archive-spacing.cjs <geometry-fixture-directory>
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { chromium } = require('playwright');

const fixtureDir = path.resolve(process.argv[2] || '');
const repoRoot = path.resolve(__dirname, '..', '..');
const aboutFile = path.join(fixtureDir, 'rational-geo--page.html');
const archiveFile = path.join(fixtureDir, 'rational-geo--archive.html');
const columnsEngine = path.join(repoRoot, 'assets', 'js', 'ss-engine-columns.js');
const layoutFile = path.join(repoRoot, 'skins', 'rational-geo', 'layout.php');

for (const required of [aboutFile, archiveFile, columnsEngine, layoutFile]) {
  assert.ok(fs.existsSync(required), `missing SS-1383 fixture input: ${required}`);
}

const layout = fs.readFileSync(layoutFile, 'utf8');
assert.match(
  layout,
  /response'\]\['kind'\][^\n]*=== 'search'[\s\S]*?class="rg-search-page search-results"[\s\S]*?<\?php else: \?><section id="justified-grid"/,
  'RATIONAL GEO route inventory changed: search must remain a non-grid branch before the shared archive/hashtag wall'
);

function rounded(value) {
  return Math.round(value * 100) / 100;
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];

  try {
    for (const width of [1920, 1440, 1024]) {
      const about = await browser.newPage({ viewport: { width, height: 1000 } });
      await about.goto(pathToFileURL(aboutFile).href);
      await about.locator('#photobox.page-hero img').waitFor({ state: 'visible' });
      const aboutGap = await about.evaluate(() => {
        const header = document.querySelector('#rg-header').getBoundingClientRect();
        const image = document.querySelector('#photobox.page-hero img');
        const imageBox = image.getBoundingClientRect();
        const outline = parseFloat(getComputedStyle(image).outlineWidth) || 0;
        return imageBox.top - outline - header.bottom;
      });
      await about.close();

      const archive = await browser.newPage({ viewport: { width, height: 1000 } });
      await archive.goto(pathToFileURL(archiveFile).href);
      await archive.addScriptTag({ path: columnsEngine });
      await archive.waitForFunction(() => {
        const tile = document.querySelector('#justified-grid .ss-masonry-item');
        return tile && getComputedStyle(tile).position === 'absolute';
      });
      const archiveGap = await archive.evaluate(() => {
        const header = document.querySelector('#rg-header').getBoundingClientRect();
        const tile = document.querySelector('#justified-grid .ss-masonry-item').getBoundingClientRect();
        return tile.top - header.bottom;
      });
      await archive.close();

      assert.ok(
        Math.abs(archiveGap - aboutGap) <= 0.5,
        `${width}px: archive frame gap ${archiveGap}px does not match About frame gap ${aboutGap}px`
      );
      results.push({
        width,
        aboutFrameGap: rounded(aboutGap),
        archiveFrameGap: rounded(archiveGap),
        hashtagFrameGap: rounded(archiveGap),
        searchGrid: 'NOT APPLICABLE — RATIONAL GEO search renders .rg-search-page, not #justified-grid',
        pass: true,
      });
    }
  } finally {
    await browser.close();
  }

  console.log(JSON.stringify({ pass: true, results }, null, 2));
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
