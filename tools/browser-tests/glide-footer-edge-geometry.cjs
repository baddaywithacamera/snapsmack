// SNAPSMACK_EOF_HEADER: end this file with the canonical SNAPSMACK EOF marker.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const seam = process.env.SNAP_BROWSER_SEAM_ROOT || path.resolve(__dirname, '../..');
const { chromium } = require(path.join(seam, 'tools/browser-tests/runtime.cjs'));
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const html = `<!doctype html><meta charset="utf-8"><body>
<main class="glide-page" data-glide-wall>
  <div class="glide-viewport" style="background:linear-gradient(90deg,#f6f6f6 0 18%,#181818 18% 36%,#ddd 36% 54%,#111 54% 72%,#fafafa 72%)"><div class="glide-field">
    <div class="glide-row"><div class="glide-track">
      <i class="glide-tile" style="--glide-aspect:3/2;background:#fff"></i>
      <i class="glide-tile" style="--glide-aspect:2/3;background:#050505"></i>
      <i class="glide-tile" style="--glide-aspect:3/2;background:#ddd"></i>
      <i class="glide-tile" style="--glide-aspect:3/2;background:#111"></i>
    </div></div>
  </div></div>
</main>
<footer id="system-footer" class="site-footer"><div id="footer" class="inside">
  <div class="footer-metadata-bar"><p id="footer-line"><span id="copyright">© 2026 GLIDE</span>
    <span class="sep">|</span> EMAIL: <a href="mailto:test@example.test">TEST@EXAMPLE.TEST</a>
    <span class="sep">|</span> THEME: GLIDE <span class="sep">|</span>
    POWERED BY <a href="https://snapsmack.ca">SNAPSMACK</a> 0.7.TEST
    <span class="sep">|</span> <a href="/rss">RSS</a></p></div>
</div></footer></body>`;

async function measure(page) {
    return page.evaluate(() => {
        const first = document.getElementById('copyright');
        const line = document.getElementById('footer-line');
        const firstRange = document.createRange();
        firstRange.selectNodeContents(first);
        const firstRect = firstRange.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(line);
        const visibleText = range.getBoundingClientRect();
        const footer = document.getElementById('system-footer');
        const css = getComputedStyle(footer);
        return {
            left: firstRect.left,
            bottom: innerHeight - firstRect.bottom,
            boxBottom: innerHeight - footer.getBoundingClientRect().bottom,
            opacity: Number(css.opacity),
            textShadow: css.textShadow,
            background: css.backgroundColor,
            right: visibleText.right,
            viewport: innerWidth
        };
    });
}

(async () => {
    const browser = await chromium.launch({ channel: 'chrome' });
    try {
        for (const [width, height] of [[1920, 1080], [1440, 900], [1024, 768], [1024, 520]]) {
            const page = await browser.newPage({ viewport: { width, height } });
            await page.setContent(html);
            await page.addStyleTag({ content: read('assets/css/public-base.css') });
            await page.addStyleTag({ content: read('skins/glide/style.css') });
            await page.mouse.move(width - 2, 2);
            await page.waitForTimeout(250);
            const result = await measure(page);
            const delta = Math.abs(result.left - result.bottom);
            assert.ok(delta <= 1,
                `${width}x${height}: visible left/bottom inset differs by ${delta.toFixed(2)}px`);
            assert.ok(result.right < result.viewport - 90,
                `${width}x${height}: footer stays clear of the existing bottom-right controls`);
            assert.notEqual(result.textShadow, 'none',
                `${width}x${height}: footer keeps its shadow over busy photographs`);
            assert.equal(result.opacity, 0.55,
                `${width}x${height}: the low-contrast resting opacity remains an explicit design choice`);
            assert.equal(result.background, 'rgba(0, 0, 0, 0)',
                `${width}x${height}: no unapproved footer backing strip was introduced`);
            console.log(`PASS ${width}x${height}: left ${result.left.toFixed(2)}px, bottom ${result.bottom.toFixed(2)}px, delta ${delta.toFixed(2)}px`);
            if (process.env.SNAP_GLIDE_FOOTER_CAPTURE && width === 1920) {
                await page.screenshot({ path: process.env.SNAP_GLIDE_FOOTER_CAPTURE });
            }
            await page.close();
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
// ===== SNAPSMACK EOF =====
