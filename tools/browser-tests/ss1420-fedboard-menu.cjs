// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('@playwright/test');

const root = path.resolve(__dirname, '../..');
const output = path.resolve(process.argv[2] || path.join(root, '_continuity/ss1420-fedboard-menu'));
const css = fs.readFileSync(path.join(root, 'assets/css/ss-pixel.css'), 'utf8');
fs.mkdirSync(output, { recursive: true });

const sites = Array.from({ length: 18 }, (_, index) => {
  const current = index === 0 ? ' is-current' : '';
  const disabled = index === 17 ? ' is-disabled' : '';
  const label = index === 0 ? 'Current site' : index === 17 ? 'Unavailable site' : `Fleet site ${index + 1}`;
  return `<a class="fb-item${current}${disabled}" href="#"><span>${label}<small>site-${index + 1}.example.test</small></span></a>`;
}).join('');

const html = `<!doctype html>
<html data-theme="dark">
<head><meta charset="utf-8"><style>${css}</style></head>
<body class="sx-reader-mastodon">
  <div class="sx-top-right">
    <div class="fb-switcher">
      <button class="fb-toggle" type="button" aria-expanded="true">
        <span class="fb-toggle-name">Current site</span><span class="fb-cursor">_</span>
      </button>
      <nav class="fb-menu" aria-label="Choose a site">${sites}</nav>
    </div>
  </div>
</body>
</html>`;

(async () => {
  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const width of [1920, 1440, 1024]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.setContent(html);
      const geometry = await page.evaluate(() => {
        const menu = document.querySelector('.fb-menu');
        const toggle = document.querySelector('.fb-toggle');
        const current = document.querySelector('.fb-item.is-current');
        const disabled = document.querySelector('.fb-item.is-disabled');
        const menuRect = menu.getBoundingClientRect();
        const toggleRect = toggle.getBoundingClientRect();
        return {
          menu: { top: menuRect.top, right: menuRect.right, bottom: menuRect.bottom, left: menuRect.left, height: menuRect.height },
          toggle: { top: toggleRect.top, bottom: toggleRect.bottom },
          viewport: { width: innerWidth, height: innerHeight },
          currentColor: getComputedStyle(current).color,
          disabledColor: getComputedStyle(disabled).color,
          disabledOpacity: Number(getComputedStyle(disabled).opacity),
          scrollable: menu.scrollHeight > menu.clientHeight,
        };
      });

      assert(geometry.menu.top >= 0, `${width}px: menu starts inside the viewport`);
      assert(geometry.menu.bottom <= geometry.viewport.height, `${width}px: menu ends inside the viewport`);
      assert(geometry.menu.right <= geometry.viewport.width, `${width}px: menu has no horizontal overflow`);
      assert(geometry.menu.bottom < geometry.toggle.top, `${width}px: menu opens above the bottom-docked toggle`);
      assert(geometry.scrollable, `${width}px: a full fleet remains scrollable within the viewport`);
      assert.notEqual(geometry.currentColor, geometry.disabledColor, `${width}px: current site remains visibly marked`);
      assert(geometry.disabledOpacity < 1, `${width}px: unavailable sites retain disabled treatment`);

      await page.screenshot({ path: path.join(output, `ss1420-mastodon-fleet-menu-${width}.png`), fullPage: true });
      results.push({ width, status: 'PASS', geometry });
      await page.close();
    }
  } finally {
    await browser.close();
  }
  fs.writeFileSync(path.join(output, 'results.json'), `${JSON.stringify(results, null, 2)}\n`);
  console.log(JSON.stringify(results));
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
// ===== SNAPSMACK EOF =====
