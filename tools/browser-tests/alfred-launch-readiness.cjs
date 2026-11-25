// SNAPSMACK_EOF_HEADER
// Capture the public HITCHCOCK reference pages used for the ALFRED launch review.
const { chromium } = require('./runtime.cjs');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const output = path.join(root, '_review', 'alfred-launch-readiness', 'old-hitchcock');
const pages = {
  home: 'https://old.mccormickphotography.com/',
  archive: 'https://old.mccormickphotography.com/category/blog/',
  solo: 'https://old.mccormickphotography.com/2023/11/16/an-uphill-battle/',
};
const widths = [1920, 1440, 1024];
const referenceWidths = [1920, 1024];
const alfredOutput = path.join(root, '_review', 'alfred-launch-readiness', 'current-alfred');
const tileImage = 'data:image/svg+xml,' + encodeURIComponent(
  '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"><rect width="600" height="600" fill="#456"/></svg>'
);

(async () => {
  fs.rmSync(output, { recursive: true, force: true });
  fs.mkdirSync(output, { recursive: true });
  fs.rmSync(alfredOutput, { recursive: true, force: true });
  fs.mkdirSync(alfredOutput, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const [kind, url] of Object.entries(pages)) {
      for (const width of referenceWidths) {
        const page = await browser.newPage({ viewport: { width, height: 1080 } });
        const errors = [];
        page.on('pageerror', (error) => errors.push(String(error)));
        const response = await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
        const metrics = await page.evaluate(() => {
          const rect = (selector) => {
            const element = document.querySelector(selector);
            if (!element) return null;
            const box = element.getBoundingClientRect();
            return { x: box.x, y: box.y, width: box.width, height: box.height };
          };
          return {
            title: document.title,
            url: location.href,
            documentWidth: document.documentElement.scrollWidth,
            viewportWidth: innerWidth,
            bodyClass: document.body.className,
            header: rect('header, #header'),
            navigation: rect('nav, #navigation, .navigation'),
            main: rect('main, #main, #content, .content'),
            footer: rect('footer, #footer'),
            imageCount: document.images.length,
            visibleImages: [...document.images].filter((image) => {
              const box = image.getBoundingClientRect();
              return box.width > 0 && box.height > 0;
            }).length,
          };
        });
        const screenshot = `${kind}-${width}.jpg`;
        await page.screenshot({ path: path.join(output, screenshot), type: 'jpeg', quality: 85, fullPage: true });
        results.push({ kind, width, status: response ? response.status() : null, screenshot, errors, ...metrics });
        console.log(`CAPTURED ${kind} ${width}px HTTP ${response ? response.status() : 'none'}`);
        await page.close();
      }
    }
    for (const width of widths) {
      const page = await browser.newPage({ viewport: { width, height: 1080 } });
      await page.setContent(`<!doctype html><html><body class="alfred-v2 archive-page"><div id="page" class="alfred">
        <div id="content"><main id="scroll-stage" class="content post-inner reading-column longform">
          <section class="section-inner smacktalk-archive"><h1>Blog</h1>
            <div class="alfred-archive-grid">${Array.from({ length: 12 }, (_, index) => `<a class="alfred-archive-tile" href="#${index}"><img src="${tileImage}" alt="Tile ${index + 1}"></a>`).join('')}</div>
          </section>
        </main></div></div></body></html>`, { waitUntil: 'load' });
      await page.addStyleTag({ path: path.join(root, 'assets', 'css', 'public-base.css') });
      await page.addStyleTag({ path: path.join(root, 'skins', 'alfred', 'style.css') });
      const measured = await page.evaluate(() => {
        const grid = document.querySelector('.alfred-archive-grid').getBoundingClientRect();
        const tiles = [...document.querySelectorAll('.alfred-archive-tile')].map((tile) => tile.getBoundingClientRect().width);
        return {
          grid: { left: grid.left, right: grid.right, width: grid.width },
          tiles,
          overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
      });
      const expected = width >= 1156 ? 1040 : width * 0.9;
      if (Math.abs(measured.grid.width - expected) > 1) {
        throw new Error(`ALFRED archive ${width}px: ${measured.grid.width}px grid, expected ${expected}px HITCHCOCK lane`);
      }
      if (measured.overflow > 1) throw new Error(`ALFRED archive ${width}px: horizontal overflow ${measured.overflow}px`);
      const screenshot = `archive-${width}.png`;
      await page.screenshot({ path: path.join(alfredOutput, screenshot), fullPage: true });
      results.push({ kind: 'alfred-archive', width, screenshot, ...measured });
      console.log(`PASS ALFRED archive ${width}px: ${measured.grid.width}px HITCHCOCK lane`);
      await page.close();
    }
  } finally {
    await browser.close();
  }
  fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ generatedAt: new Date().toISOString(), results }, null, 2));
  console.log(`PASS ${results.length} HITCHCOCK/ALFRED launch checks; evidence ${path.dirname(output)}`);
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
// ===== SNAPSMACK EOF =====
