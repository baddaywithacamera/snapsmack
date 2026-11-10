// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const out = process.env.SNAPSMACK_BROWSER_OUTPUT
  || path.join(os.tmpdir(), 'chaplin-archive-row-geometry');
const liveUrl = process.env.SNAPSMACK_CHAPLIN_ARCHIVE_URL
  || 'https://acolourlesslife.ca/archive-view';

fs.mkdirSync(out, { recursive: true });

function summarize(items, columns) {
  const rows = [];
  for (let start = 0; start < Math.min(items.length, columns * 4); start += columns) {
    const rowItems = items.slice(start, start + columns);
    const tops = rowItems.map((item) => item.top);
    const bottoms = rowItems.map((item) => item.bottom);
    rows.push({
      row: rows.length + 1,
      firstIndex: start,
      lastIndex: start + rowItems.length - 1,
      items: rowItems,
      minTop: Math.min(...tops),
      maxTop: Math.max(...tops),
      topSpread: Math.max(...tops) - Math.min(...tops),
      minBottom: Math.min(...bottoms),
      maxBottom: Math.max(...bottoms),
      bottomSpread: Math.max(...bottoms) - Math.min(...bottoms),
    });
  }
  for (let index = 1; index < rows.length; index += 1) {
    rows[index].gapFromPreviousVisualBottom = rows[index].minTop - rows[index - 1].maxBottom;
    rows[index].trackStep = rows[index].minTop - rows[index - 1].minTop;
  }
  return rows;
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    for (const width of [1920, 1440, 1024]) {
      const context = await browser.newContext({ viewport: { width, height: 1080 } });
      await context.route('**/skins/chaplin/style.css*', (route) => route.fulfill({
        contentType: 'text/css',
        path: path.join(root, 'skins/chaplin/style.css'),
      }));
      const page = await context.newPage();
      await page.goto(liveUrl, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#browse-grid > .fsog-archive-item');
      await page.evaluate(() => Promise.race([
        new Promise((resolve) => setTimeout(resolve, 10000)),
        Promise.all([...document.images].map((image) => image.decode().catch(() => {}))),
      ]));

      const measurement = await page.evaluate(() => {
        const grid = document.querySelector('#browse-grid');
        const style = getComputedStyle(grid);
        const columns = style.gridTemplateColumns.split(' ').filter(Boolean).length;
        const items = [...grid.querySelectorAll(':scope > .fsog-archive-item')].map((item, index) => {
          const rect = item.getBoundingClientRect();
          const thumb = item.querySelector('.fsog-thumb');
          return {
            index,
            orientation: thumb?.classList.contains('fsog-thumb-portrait') ? 'portrait'
              : thumb?.classList.contains('fsog-thumb-landscape') ? 'landscape' : 'square',
            left: rect.left,
            top: rect.top,
            right: rect.right,
            bottom: rect.bottom,
            width: rect.width,
            height: rect.height,
          };
        });
        return {
          columns,
          display: style.display,
          alignItems: style.alignItems,
          rowGap: parseFloat(style.rowGap),
          items,
        };
      });

      assert.equal(measurement.display, 'grid');
      assert.ok(measurement.columns >= 2);
      const rows = summarize(measurement.items, measurement.columns);
      assert.equal(rows.length, 4, `${width}px must expose four rows for measurement`);
      for (const row of rows) {
        if (row.row > 1) {
          assert.ok(
            Math.abs(row.gapFromPreviousVisualBottom - measurement.rowGap) < 1,
            `${width}px row ${row.row} gap differs from declared ${measurement.rowGap}px`,
          );
        }
      }
      results.push({
        width,
        display: measurement.display,
        columns: measurement.columns,
        alignItems: measurement.alignItems,
        declaredRowGap: measurement.rowGap,
        rows,
      });
      await page.screenshot({
        path: path.join(out, `chaplin-archive-current-${width}.png`),
        fullPage: true,
      });
      await context.close();
    }
    fs.writeFileSync(path.join(out, 'measurements.json'), `${JSON.stringify(results, null, 2)}\n`);
    console.log(`PASS: CHAPLIN archive row gaps match the declared grid gap at 1920/1440/1024: ${out}`);
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
// ===== SNAPSMACK EOF =====
