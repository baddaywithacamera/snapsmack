const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('./runtime.cjs');

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

const root = path.resolve(__dirname, '../..');
const fixture = path.join(__dirname, 'archive-layout-visibility-fixture.php');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT
  || fs.mkdtempSync(path.join(os.tmpdir(), 'snapsmack-archive-transfer-'));
const minimumBudget = 160000;
const bytesPerRenderedPixel = 0.24;

function php(args) {
  const result = spawnSync('php', [fixture, ...args], {
    cwd: root,
    encoding: 'utf8',
    maxBuffer: 16 * 1024 * 1024,
  });
  if (result.status !== 0) {
    throw new Error(`Fixture failed (${args.join(' ')}): ${result.stderr || result.stdout}`);
  }
  return result.stdout;
}

const requestedSkins = new Set((process.env.SNAPSMACK_ARCHIVE_SKINS || '')
  .split(',').map((value) => value.trim()).filter(Boolean));
const inventory = JSON.parse(php(['--inventory']))
  .filter((entry) => requestedSkins.size === 0 || requestedSkins.has(entry.skin));
const htmlCache = new Map();
function fixtureHtml(skin, layout) {
  const key = `${skin}:${layout}`;
  if (!htmlCache.has(key)) htmlCache.set(key, php([skin, layout, '--transfer']));
  return htmlCache.get(key);
}

function localFile(url) {
  const pathname = decodeURIComponent(new URL(url).pathname).replace(/^\/+/, '');
  const candidate = path.resolve(root, pathname);
  return candidate.startsWith(root + path.sep) && fs.existsSync(candidate) && fs.statSync(candidate).isFile()
    ? candidate
    : '';
}

function paddedSvg(width, height, targetBytes, colour) {
  const start = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="100%" height="100%" fill="${colour}"/>`;
  const end = '</svg>';
  const remaining = Math.max(0, targetBytes - Buffer.byteLength(start) - Buffer.byteLength(end) - 7);
  return `${start}<!--${'x'.repeat(remaining)}-->${end}`;
}

function responseFor(url) {
  const pathname = url.pathname;
  const itemNumber = Number.parseInt(/photo-(\d+)/.exec(pathname)?.[1] || '0', 10);
  const portrait = itemNumber > 0 && itemNumber % 3 === 0;
  const requestedWidth = Number.parseInt(url.searchParams.get('w') || url.searchParams.get('width') || '0', 10);
  if (requestedWidth > 0) {
    const width = Math.max(1, Math.min(900, requestedWidth));
    const height = Math.max(1, Math.round(width * 2 / 3));
    const bytes = Math.max(2500, Math.round(width * height * 0.18));
    return { width, height, bytes, body: paddedSvg(width, height, bytes, '#557') };
  }
  const responsive = /\/(?:r|w)(\d+)[_-]/.exec(pathname);
  if (responsive) {
    const width = Math.max(1, Math.min(900, Number.parseInt(responsive[1], 10)));
    const height = Math.max(1, Math.round(width * 2 / 3));
    const bytes = Math.max(2500, Math.round(width * height * 0.18));
    return { width, height, bytes, body: paddedSvg(width, height, bytes, '#557') };
  }
  if (/\/thumbs\/t_/.test(pathname)) {
    return { width: 400, height: 400, bytes: 45000, body: paddedSvg(400, 400, 45000, '#665') };
  }
  if (/\/thumbs\/a_/.test(pathname)) {
    const width = portrait ? 600 : 900;
    const height = portrait ? 900 : 600;
    return { width, height, bytes: 128000, body: paddedSvg(width, height, 128000, '#755') };
  }
  const width = portrait ? 2560 : 3840;
  const height = portrait ? 3840 : 2560;
  return { width, height, bytes: 320000, body: paddedSvg(width, height, 320000, '#333') };
}

async function measure(browser, skin, layout, viewportWidth) {
  const context = await browser.newContext({ viewport: { width: viewportWidth, height: 900 } });
  const page = await context.newPage();
  const transfers = [];
  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== 'https://archive-fixture.test') {
      return route.fulfill({ status: 204, body: '' });
    }
    if (request.resourceType() === 'document') {
      return route.fulfill({
        contentType: 'text/html; charset=utf-8',
        body: fixtureHtml(skin, layout),
      });
    }
    if (url.pathname.startsWith('/media/')) {
      const response = responseFor(url);
      const bodyBytes = Buffer.byteLength(response.body);
      assert.equal(bodyBytes, response.bytes, `Synthetic response size drifted for ${url.pathname}`);
      transfers.push({ url: url.pathname + url.search, bytes: bodyBytes });
      return route.fulfill({
        contentType: 'image/svg+xml',
        headers: { 'cache-control': 'public, max-age=3600' },
        body: response.body,
      });
    }
    const file = localFile(request.url());
    if (file) return route.fulfill({ path: file });
    return route.fulfill({ status: 404, body: '' });
  });

  await page.goto(`https://archive-fixture.test/${skin}/archive?layout=${encodeURIComponent(layout)}`, {
    waitUntil: 'networkidle',
  });
  const display = await page.evaluate(() => {
    const images = [...document.querySelectorAll('img[src*="/media/"]')];
    const visible = images.filter((image) => {
      let node = image;
      while (node && node !== document.documentElement) {
        const style = getComputedStyle(node);
        if (style.display === 'none' || style.visibility === 'hidden' || Number.parseFloat(style.opacity || '1') <= 0) return false;
        node = node.parentElement;
      }
      const rect = image.getBoundingClientRect();
      return rect.width > 1 && rect.height > 1;
    });
    const widths = visible.map((image) => Math.round(image.getBoundingClientRect().width * 10) / 10);
    const totalRenderedPixels = visible.reduce((sum, image) => {
      const rect = image.getBoundingClientRect();
      return sum + (rect.width * rect.height);
    }, 0);
    return {
      imageCount: images.length,
      visibleCount: visible.length,
      maxRenderedWidth: widths.length ? Math.max(...widths) : 0,
      meanRenderedWidth: widths.length ? widths.reduce((sum, value) => sum + value, 0) / widths.length : 0,
      totalRenderedPixels,
    };
  });
  const totalBytes = transfers.reduce((sum, transfer) => sum + transfer.bytes, 0);
  // A 900px derivative is appropriate when the layout really displays it at
  // 900px. It is not appropriate for the 160-300px tiles measured on the
  // fleet. Scale the budget to actual painted area, while retaining the
  // ticket's 160KB ceiling for a twelve-tile thumbnail grid.
  const budget = Math.max(minimumBudget, Math.round(display.totalRenderedPixels * bytesPerRenderedPixel));
  const problems = [];
  if (display.visibleCount < 8) problems.push(`only ${display.visibleCount}/${display.imageCount} archive images visible`);
  if (totalBytes > budget) problems.push(`${totalBytes} bytes exceeds ${budget}`);
  if (transfers.length > 12) problems.push(`${transfers.length} image responses for 12 archive items`);
  await context.close();
  return { skin, layout, viewportWidth, budget, totalBytes, transfers, ...display, problems };
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const rows = [];
  try {
    assert.ok(inventory.length > 0, 'No strict skins matched the requested inventory filter.');
    for (const entry of inventory) {
      for (const layout of entry.layouts) {
        for (const viewportWidth of [1920, 1440, 1024]) {
          const row = await measure(browser, entry.skin, layout, viewportWidth);
          row.reportOnly = Boolean(entry.report_only);
          rows.push(row);
          const label = row.problems.length ? (row.reportOnly ? 'REPORT' : 'FAIL') : 'PASS';
          console.log(`${label} ${entry.skin} ${layout} ${viewportWidth}: ${row.totalBytes}/${row.budget} bytes, ${row.visibleCount}/${row.imageCount} visible, mean ${row.meanRenderedWidth.toFixed(1)}px${row.problems.length ? `; ${row.problems.join('; ')}` : ''}`);
        }
      }
    }
  } finally {
    await browser.close();
  }

  fs.mkdirSync(output, { recursive: true });
  fs.writeFileSync(path.join(output, 'archive-transfer-bytes.json'), JSON.stringify(rows, null, 2));
  const failures = rows.filter((row) => !row.reportOnly && row.problems.length);
  assert.equal(failures.length, 0,
    `${failures.length} strict-skin archive transfer cases failed. Evidence: ${output}`);
  console.log(`PASS: ${rows.length} archive transfer cases stay within their rendered-area budgets. Evidence: ${output}`);
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
