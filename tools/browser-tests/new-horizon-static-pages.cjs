// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const out = process.env.SNAPSMACK_BROWSER_OUTPUT || path.join(root, '_continuity/evidence/new-horizon-static-pages-2026-11-09');
const cssPath = path.join(root, 'skins/new-horizon/style.css');
const candidate = fs.readFileSync(cssPath, 'utf8');
const tracked = execFileSync('git', ['show', 'HEAD:skins/new-horizon/style.css'], { cwd: root, encoding: 'utf8' });
const previousRef = tracked === candidate ? 'HEAD^' : 'HEAD';
const previous = execFileSync('git', ['show', `${previousRef}:skins/new-horizon/style.css`], { cwd: root, encoding: 'utf8' });
const baseUrl = process.env.SNAPSMACK_NEW_HORIZON_URL || 'https://curbappeal.photoblogs.fyi';
fs.mkdirSync(out, { recursive: true });

const box = async (page, selector) => page.locator(selector).first().evaluate(element => {
  const rect = element.getBoundingClientRect();
  return { x: rect.x, y: rect.y, width: rect.width, height: rect.height, right: rect.right, bottom: rect.bottom };
});

async function geometry(page, route) {
  const titleSelector = '.static-page-title';
  const title = await box(page, titleSelector);
  const nav = await box(page, '.nav-menu');
  const contentSelector = route === 'about' ? '#photobox.page-hero' : '.blogroll-category-block';
  const content = await box(page, contentSelector);
  const titleStyle = await page.locator(titleSelector).first().evaluate(element => {
    const style = getComputedStyle(element);
    return {
      family: style.fontFamily,
      size: style.fontSize,
      weight: style.fontWeight,
      spacing: style.letterSpacing,
      color: style.color,
      lineHeight: style.lineHeight,
    };
  });
  const result = {
    route,
    title,
    nav,
    content,
    titleStyle,
    navToTitle: title.y - nav.bottom,
    titleToContent: content.y - title.bottom,
    overflow: await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),
  };
  if (route === 'about') {
    result.container = await box(page, '.static-content');
    result.hero = content;
    result.description = await box(page, '.static-content > .description');
    result.image = await box(page, '#photobox.page-hero img');
  } else {
    result.container = await box(page, '.blogroll-page > .blogroll-canvas');
    result.innerCanvas = await box(page, '.blogroll-page > .blogroll-canvas > .blogroll-canvas');
  }
  return result;
}

async function capture(browser, width, route, variant, css, screenshot = false) {
  const context = await browser.newContext({ viewport: { width, height: 1080 } });
  const page = await context.newPage();
  await page.goto(`${baseUrl}/${route}`, { waitUntil: 'load' });
  await page.evaluate(() => Promise.race([document.fonts.ready, new Promise(resolve => setTimeout(resolve, 6000))]));
  await page.evaluate(() => Promise.race([
    Promise.all([...document.images].filter(image => image.getBoundingClientRect().top < innerHeight).map(image => image.decode().catch(() => {}))),
    new Promise(resolve => setTimeout(resolve, 6000)),
  ]));
  // The production renderer may serve a bundled or cache-keyed skin asset.
  // Appending the complete local sheet after all dynamic presentation sheets
  // makes this comparison independent of that delivery detail while leaving
  // the live HTML and data untouched.
  await page.addStyleTag({ content: css });
  const measured = await geometry(page, route);
  if (screenshot) {
    await page.screenshot({ path: path.join(out, `${route}-${width}-${variant}.png`), fullPage: true });
  }
  await context.close();
  return measured;
}

const near = (actual, expected, tolerance = 1) => Math.abs(actual - expected) <= tolerance;

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    for (const width of [1920, 1440, 1024]) {
      for (const route of ['about', 'blogroll']) {
        const screenshot = width === 1920;
        const before = await capture(browser, width, route, 'before', previous, screenshot);
        const after = await capture(browser, width, route, 'after', candidate, screenshot);

        assert.equal(after.titleStyle.size, '24px');
        assert.equal(after.titleStyle.weight, '600');
        assert.equal(after.titleStyle.spacing, '2px');
        assert.match(after.titleStyle.family, /Inter/i);
        const expectedNavToTitle = route === 'about' ? 104.5 : 64.5;
        assert.ok(near(after.navToTitle, expectedNavToTitle, 2), `${route} ${width}: nav/title gap ${after.navToTitle}`);
        if (route === 'blogroll') {
          assert.ok(near(after.titleToContent, 24, 1), `${route} ${width}: title/content gap ${after.titleToContent}`);
        }
        assert.equal(after.overflow, false, `${route} ${width}: horizontal overflow`);

        if (route === 'about') {
          assert.ok(near(after.title.x, after.description.x, 1), `${route} ${width}: heading and copy left edges differ`);
          assert.ok(near(after.title.y, after.hero.y, 1), `${route} ${width}: heading and photograph tops differ`);
          const usableWidth = after.container.width - 40;
          const heroShare = after.hero.width / usableWidth;
          assert.ok(heroShare >= 0.37 && heroShare <= 0.39, `${route} ${width}: hero share ${heroShare}`);
          assert.ok(after.description.y >= after.title.bottom + 23, `${route} ${width}: copy does not follow heading`);
          assert.ok(after.hero.right < after.description.x, `${route} ${width}: columns overlap`);
        } else {
          assert.ok(near(after.title.x, after.content.x, 1), `${route} ${width}: title and content left edges differ`);
          assert.ok(near(after.innerCanvas.x, after.title.x, 1), `${route} ${width}: nested canvas is double-inset`);
          assert.ok(near(after.innerCanvas.width, after.title.width, 1), `${route} ${width}: nested canvas width differs from title`);
        }
        results.push({ width, route, before, after });
      }
    }

    const narrow = await capture(browser, 800, 'about', 'after', candidate, true);
    assert.ok(near(narrow.title.x, narrow.image.x, 1), `narrow: heading and visible image left edges differ`);
    assert.ok(narrow.description.y >= narrow.hero.bottom + 23, `narrow: copy does not stack below image (${narrow.description.y} vs ${narrow.hero.bottom})`);
    assert.equal(narrow.overflow, false, 'narrow: horizontal overflow');
    results.push({ width: 800, route: 'about', after: narrow, note: 'Narrow stacking preview; not a supported skin verification width.' });

    fs.writeFileSync(path.join(out, 'measurements.json'), JSON.stringify({
      note: 'Live curbappeal markup with local stylesheet interception. Candidate is not packaged or deployed. Supported verification widths: 1920, 1440, 1024.',
      baseUrl,
      results,
    }, null, 2));
    console.log(`PASS: shared static title lane and About two-column geometry verified at 1920, 1440 and 1024; narrow stack preview captured at 800. ${out}`);
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
// ===== SNAPSMACK EOF =====
