// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT
  || path.join(root, 'opaudits/evidence/1040/2026-11-10-galleria-static-title');
const currentCss = fs.readFileSync(path.join(root, 'skins/galleria/style.css'), 'utf8');
const previousCss = execFileSync(
  'git',
  ['show', 'ea97f6dd^:skins/galleria/style.css'],
  { cwd: root, encoding: 'utf8' },
);

const fixtures = {
  about: `<!doctype html>
    <html><head><meta charset="utf-8"><title>About</title></head>
    <body class="static-transmission"><div id="scroll-stage"><main>
      <div class="static-content">
        <h1 class="static-page-title">About</h1>
        <div id="photobox" class="page-hero page-hero--full page-hero--center">
          <div class="frame-mount"><div class="frame-border"><div class="frame-mat">
            <div class="frame-bevel"><div class="frame-image">
              <svg class="post-image" viewBox="0 0 1200 675" role="img" aria-label="About fixture"><rect width="1200" height="675" fill="#d8d3cd"/><path d="M0 520 330 230 610 470 830 290 1200 610V675H0Z" fill="#8f8982"/></svg>
            </div></div>
          </div></div></div>
        </div>
        <div class="description entry-content e-content"><p>Source-equivalent static-page content used to verify the GALLERIA title after the Blogroll repair.</p></div>
      </div>
    </main></div></body></html>`,
  blogroll: `<!doctype html>
    <html><head><meta charset="utf-8"><title>Blogroll</title></head>
    <body class="static-transmission"><div id="scroll-stage"><main>
      <section class="blogroll-page">
        <h1 class="blogroll-page-title">Blogroll</h1>
        <div class="blogroll-canvas"><section class="blogroll-category-block">
          <h2 class="blogroll-category-heading">Photographers</h2>
          <div class="blogroll-grid"><article class="blogroll-peer"><a class="blogroll-peer-name" href="#">A careful eye</a><p class="blogroll-peer-description">A source-equivalent entry.</p></article></div>
        </section></div>
      </section>
    </main></div></body></html>`,
};

function roundedBox(rect) {
  if (!rect) return null;
  return Object.fromEntries(['x', 'y', 'width', 'height'].map(key => [key, Math.round(rect[key] * 1000) / 1000]));
}

async function measure(page, route) {
  return page.evaluate((fixtureRoute) => {
    const box = selector => {
      const element = document.querySelector(selector);
      return element ? element.getBoundingClientRect().toJSON() : null;
    };
    const title = document.querySelector('h1');
    const titleStyle = getComputedStyle(title);
    return {
      route: fixtureRoute,
      title: box('h1'),
      content: box(fixtureRoute === 'about' ? '.description' : '.blogroll-category-block'),
      page: box(fixtureRoute === 'about' ? '.static-content' : '.blogroll-page'),
      hero: box('.page-hero'),
      titleStyle: {
        fontFamily: titleStyle.fontFamily,
        fontSize: titleStyle.fontSize,
        fontWeight: titleStyle.fontWeight,
        letterSpacing: titleStyle.letterSpacing,
        borderBottomWidth: titleStyle.borderBottomWidth,
      },
      overflow: document.documentElement.scrollWidth > innerWidth,
    };
  }, route);
}

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    for (const width of [1920, 1440, 1024]) {
      for (const [route, markup] of Object.entries(fixtures)) {
        const variants = {};
        for (const [variant, css] of [['previous', previousCss], ['current', currentCss]]) {
          const context = await browser.newContext({ viewport: { width, height: 1080 } });
          const page = await context.newPage();
          await page.setContent(markup, { waitUntil: 'load' });
          await page.addStyleTag({ content: css });
          await page.evaluate(() => document.fonts.ready);
          variants[variant] = await measure(page, route);
          for (const key of ['title', 'content', 'page', 'hero']) {
            variants[variant][key] = roundedBox(variants[variant][key]);
          }
          if (variant === 'current') {
            await page.screenshot({ path: path.join(output, `${route}-${width}.png`), fullPage: true });
          }
          await context.close();
        }

        assert.equal(variants.current.overflow, false, `${route} must not overflow at ${width}px`);
        assert.equal(variants.current.titleStyle.fontSize, '28px');
        assert.equal(variants.current.titleStyle.letterSpacing, '6px');
        if (route === 'about') {
          assert.deepEqual(
            variants.current.title,
            variants.previous.title,
            `Blogroll repair must not move the About title at ${width}px`,
          );
          assert.equal(variants.current.title.x, variants.current.content.x, `About title/content x mismatch at ${width}px`);
          assert.equal(variants.current.title.width, variants.current.content.width, `About title/content width mismatch at ${width}px`);
        } else {
          assert.equal(variants.current.title.x, variants.current.content.x, `Blogroll title/content x mismatch at ${width}px`);
          assert.equal(variants.current.title.width, variants.current.content.width, `Blogroll title/content width mismatch at ${width}px`);
          assert.ok(
            Math.abs(variants.previous.title.x - variants.previous.content.x) > 100,
            `Pre-repair Blogroll fixture must reproduce the alignment fault at ${width}px`,
          );
        }
        results.push({ width, route, ...variants });
      }
    }
    fs.writeFileSync(
      path.join(output, 'measurements.json'),
      JSON.stringify({
        note: 'Source-equivalent GALLERIA markup with the current stylesheet and the pre-repair stylesheet. This is browser geometry evidence, not installed-build or owner-acceptance evidence.',
        results,
      }, null, 2),
    );
    console.log(`PASS: GALLERIA Blogroll title aligns at 1920/1440/1024; valid About title remains aligned and unchanged. ${output}`);
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
