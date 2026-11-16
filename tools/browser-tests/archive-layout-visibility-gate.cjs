const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const fixture = path.join(__dirname, 'archive-layout-visibility-fixture.php');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT
  || fs.mkdtempSync(path.join(os.tmpdir(), 'snapsmack-archive-layout-'));

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
  if (!htmlCache.has(key)) htmlCache.set(key, php([skin, layout]));
  return htmlCache.get(key);
}

function localFile(url) {
  const pathname = decodeURIComponent(new URL(url).pathname).replace(/^\/+/, '');
  const candidate = path.resolve(root, pathname);
  return candidate.startsWith(root + path.sep) && fs.existsSync(candidate) && fs.statSync(candidate).isFile()
    ? candidate
    : '';
}

function svg(width, height, colour) {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="100%" height="100%" fill="${colour}"/></svg>`;
}

async function measure(browser, skin, layout, width, source) {
  const context = await browser.newContext({ viewport: { width, height: 900 } });
  if (source === 'cookie') {
    await context.addCookies([{
      name: 'smack_archive_layout', value: layout,
      domain: 'archive-fixture.test', path: '/',
    }]);
  }
  await context.addInitScript(() => {
    window.__archiveGateCls = 0;
    new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        if (!entry.hadRecentInput) window.__archiveGateCls += entry.value;
      }
    }).observe({ type: 'layout-shift', buffered: true });
  });
  const page = await context.newPage();
  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== 'https://archive-fixture.test') {
      return route.fulfill({ status: 204, body: '' });
    }
    if (request.resourceType() === 'document') {
      const queryLayout = url.searchParams.get('layout');
      const selected = queryLayout || (/smack_archive_layout=([^;]+)/.exec(request.headers().cookie || '')?.[1]) || layout;
      return route.fulfill({ contentType: 'text/html; charset=utf-8', body: fixtureHtml(skin, decodeURIComponent(selected)) });
    }
    if (url.pathname.startsWith('/fixture-') && url.pathname.endsWith('.svg')) {
      // Make image loading slower than first paint so a missing reserved box
      // produces a deterministic layout-shift measurement instead of a race
      // between Chromium's decoder and the first frame.
      await new Promise((resolve) => setTimeout(resolve, 500));
      const response = { contentType: 'image/svg+xml', headers: { 'cache-control': 'no-store' } };
      if (url.pathname === '/fixture-photo.svg') return route.fulfill({ ...response, body: svg(1200, 800, '#777') });
      if (url.pathname === '/fixture-portrait.svg') return route.fulfill({ ...response, body: svg(800, 1200, '#555') });
      if (url.pathname === '/fixture-square.svg') return route.fulfill({ ...response, body: svg(800, 800, '#666') });
    }
    const file = localFile(request.url());
    if (file) return route.fulfill({ path: file });
    return route.fulfill({ status: 404, body: '' });
  });

  const suffix = source === 'query' ? `?layout=${encodeURIComponent(layout)}` : '';
  await page.goto(`https://archive-fixture.test/${skin}/archive${suffix}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(250);
  const result = await page.evaluate(({ expected, declaredCount }) => {
    const rootLayout = document.documentElement.getAttribute('data-archive-layout');
    const images = [...document.querySelectorAll('img[src*="fixture-"]')];
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
    const toggles = [...document.querySelectorAll('.archive-layout-toggle a, .archive-layout-toggle button')];
    const selected = toggles.filter((control) => {
      const href = control.getAttribute('href') || '';
      const data = control.getAttribute('data-layout') || '';
      const active = control.classList.contains('alt-btn--active')
        || control.classList.contains('active')
        || control.getAttribute('aria-current') === 'page'
        || control.getAttribute('aria-current') === 'true'
        || control.getAttribute('aria-pressed') === 'true';
      return active && (data === expected || href.includes(`layout=${encodeURIComponent(expected)}`));
    });
    return {
      rootLayout,
      imageCount: images.length,
      visibleCount: visible.length,
      toggleCount: toggles.length,
      selectedCount: selected.length,
      declaredCount,
      cls: Number(window.__archiveGateCls || 0),
      bodyWidth: document.body.scrollWidth,
      viewportWidth: innerWidth,
    };
  }, { expected: layout, declaredCount: inventory.find((row) => row.skin === skin).layouts.length });

  const problems = [];
  // Some strict skins intentionally omit the shared controls and HTML state
  // hook. Where either surface exists it must agree with the resolved source.
  if (result.rootLayout !== null && result.rootLayout !== layout) problems.push(`html=${result.rootLayout}`);
  if (result.imageCount < 8) problems.push(`only ${result.imageCount} archive images rendered`);
  if (result.visibleCount < 8) problems.push(`only ${result.visibleCount}/${result.imageCount} archive images visible`);
  if (result.toggleCount > 0 && result.selectedCount !== 1) problems.push(`selected controls=${result.selectedCount}/${result.toggleCount}`);
  if (result.cls > 0.1) problems.push(`CLS=${result.cls.toFixed(4)}`);

  if (problems.length) {
    fs.mkdirSync(output, { recursive: true });
    await page.screenshot({
      path: path.join(output, `${skin}--${layout}--${width}--${source}.png`),
      fullPage: true,
    });
  }
  await context.close();
  return { skin, layout, width, source, reportOnly: false, ...result, problems };
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const rows = [];
  try {
    assert.ok(inventory.length > 0, 'No strict skins matched the requested inventory filter.');
    for (const entry of inventory) {
      for (const layout of entry.layouts) {
        for (const width of [1920, 1440, 1024]) {
          for (const source of ['query', 'cookie']) {
            const row = await measure(browser, entry.skin, layout, width, source);
            row.reportOnly = Boolean(entry.report_only);
            rows.push(row);
            const label = row.problems.length ? (row.reportOnly ? 'REPORT' : 'FAIL') : 'PASS';
            console.log(`${label} ${entry.skin} ${layout} ${width} ${source}: visible ${row.visibleCount}/${row.imageCount}, CLS ${row.cls.toFixed(4)}${row.problems.length ? `; ${row.problems.join('; ')}` : ''}`);
          }
        }
      }
    }
  } finally {
    await browser.close();
  }

  fs.mkdirSync(output, { recursive: true });
  fs.writeFileSync(path.join(output, 'archive-layout-visibility.json'), JSON.stringify(rows, null, 2));
  const failures = rows.filter((row) => !row.reportOnly && row.problems.length);
  assert.equal(failures.length, 0,
    `${failures.length} strict-skin archive cases failed. Evidence: ${output}`);
  console.log(`PASS: ${rows.length} strict-skin archive cases; Photogram and The Grid are report-only. Evidence: ${output}`);
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
