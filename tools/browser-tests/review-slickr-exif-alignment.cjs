// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const out = process.env.SNAPSMACK_BROWSER_OUTPUT
  || path.join(os.tmpdir(), 'snapsmack-ss-1275-slickr-exif');
const cssPath = path.join(root, 'skins/slickr/style.css');
const candidate = fs.readFileSync(cssPath, 'utf8');
const previous = execFileSync(
  'git', ['show', 'HEAD:skins/slickr/style.css'],
  { cwd: root, encoding: 'utf8' },
);
const target = 'https://foreverphotograph.ing/think-pink-canary-wharf-salmon-arm-bc-2025-06-20-1794196993?codexcheck=1275';

fs.mkdirSync(out, { recursive: true });

async function measure(page) {
  return page.evaluate(() => {
    const textRect = element => {
      const node = [...element.childNodes].find(item => item.nodeType === Node.TEXT_NODE);
      if (!node) return null;
      const range = document.createRange();
      range.selectNodeContents(node);
      const rect = range.getBoundingClientRect();
      return { left: rect.left, right: rect.right, width: rect.width };
    };
    const cells = [...document.querySelectorAll('.sl-metadata-table th')];
    const values = [...document.querySelectorAll('.sl-metadata-table td')];
    return {
      labels: cells.map(cell => {
        const rect = cell.getBoundingClientRect();
        const style = getComputedStyle(cell);
        return {
          label: cell.textContent.trim(),
          cellLeft: rect.left,
          cellRight: rect.right,
          text: textRect(cell),
          align: style.textAlign,
          paddingRight: style.paddingRight,
        };
      }),
      valueLefts: values.map(cell => cell.getBoundingClientRect().left),
      overflow: document.documentElement.scrollWidth > innerWidth,
    };
  });
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    for (const width of [1920, 1440, 1024]) {
      const row = { width };
      for (const [variant, css] of [['previous', previous], ['candidate', candidate]]) {
        const context = await browser.newContext({ viewport: { width, height: 1080 } });
        await context.route('**/skins/slickr/style.css*', route => route.fulfill({
          contentType: 'text/css',
          body: css,
        }));
        const page = await context.newPage();
        await page.goto(target, { waitUntil: 'load' });
        const continueWithout = page.getByRole('button', { name: 'CONTINUE WITHOUT' });
        if (await continueWithout.count()) await continueWithout.click();
        await page.evaluate(() => {
          const overlay = document.querySelector('#snap-consent-banner');
          if (overlay) overlay.style.setProperty('display', 'none', 'important');
          window.scrollTo(0, 0);
        });
        await page.evaluate(() => Promise.race([
          document.fonts.ready,
          new Promise(resolve => setTimeout(resolve, 6000)),
        ]));
        await page.locator('.sl-metadata-table').waitFor({ state: 'visible' });
        row[variant] = await measure(page);
        await page.screenshot({
          path: path.join(out, `slickr-exif-${width}-${variant}.png`),
          fullPage: true,
        });
        await context.close();
      }

      assert.ok(row.previous.labels.length >= 2, `${width}: live page must expose EXIF labels`);
      assert.ok(row.previous.labels.every(label => label.align === 'center'), `${width}: old CSS must reproduce centred labels`);
      assert.ok(row.candidate.labels.every(label => label.align === 'right'), `${width}: candidate labels must be right-aligned`);
      assert.ok(row.candidate.labels.every(label => label.paddingRight === '12px'), `${width}: candidate label/value gap must be 12px`);

      const previousEdges = row.previous.labels.map(label => label.text.right);
      const candidateEdges = row.candidate.labels.map(label => label.text.right);
      assert.ok(Math.max(...previousEdges) - Math.min(...previousEdges) > 5, `${width}: old label text must have visibly ragged right edges`);
      assert.ok(Math.max(...candidateEdges) - Math.min(...candidateEdges) < 1, `${width}: label text must share one right edge`);

      const valueSpread = Math.max(...row.candidate.valueLefts) - Math.min(...row.candidate.valueLefts);
      assert.ok(valueSpread < 1, `${width}: values must share one left edge`);
      assert.equal(row.candidate.overflow, false, `${width}: candidate must not introduce horizontal overflow`);
      results.push(row);
    }

    fs.writeFileSync(
      path.join(out, 'measurements.json'),
      JSON.stringify({
        note: 'Live SLICKR photo markup with the repository candidate stylesheet intercepted locally. This is not deployed verification.',
        target,
        results,
      }, null, 2),
    );
    console.log(`PASS: old CSS reproduces centred EXIF labels; candidate aligns label and value columns at 1920, 1440 and 1024. ${out}`);
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
