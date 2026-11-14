// SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
// RATIONAL GEO's INFO / COMMENTS tray opens below the nav, never over the photo.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');
const root = path.resolve(__dirname, '../..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    for (const width of [1920, 1440, 1024]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.setContent('<body><header id="rg-header"></header><main id="scroll-stage">'
        + '<article id="rg-photobox" class="rg-single single-image-page">'
        + '<div class="rg-photo-wrap"><img class="rg-image post-image" alt="Tray fixture" '
        + 'src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%221200%22 height=%22800%22/%3E"></div>'
        + '<div id="infobox"><div class="nav-links">INFO</div></div>'
        + '<div id="footer"><section id="pane-info"><h1>Title</h1><p>Long information tray.</p></section></div>'
        + '</article></main><footer id="system-footer"></footer></body>');
      for (const file of ['assets/css/public-base.css', 'skins/rational-geo/style.css']) {
        await page.addStyleTag({ content: read(file) });
      }
      await page.evaluate(() => document.querySelector('img').decode());

      const closed = await page.evaluate(() => {
        const photo = document.querySelector('.rg-photo-wrap').getBoundingClientRect();
        const footer = document.querySelector('#footer');
        footer.style.display = 'block';
        const nav = document.querySelector('#infobox').getBoundingClientRect();
        const openPhoto = document.querySelector('.rg-photo-wrap').getBoundingClientRect();
        const tray = footer.getBoundingClientRect();
        const stage = document.querySelector('.rg-single');
        return {
          photoHeight: photo.height,
          openPhotoHeight: openPhoto.height,
          navBottom: nav.bottom,
          trayTop: tray.top,
          scrollHeight: stage.scrollHeight,
          clientHeight: stage.clientHeight,
          trayPosition: getComputedStyle(footer).position,
        };
      });

      assert.equal(closed.trayPosition, 'static', `${width}px tray escaped document flow`);
      assert.ok(closed.trayTop >= closed.navBottom - 1,
        `${width}px tray opened over the photograph/navigation instead of below it`);
      assert.ok(Math.abs(closed.openPhotoHeight - closed.photoHeight) <= 1,
        `${width}px opening the tray shrank the photograph lane`);
      assert.ok(closed.scrollHeight > closed.clientHeight,
        `${width}px open tray did not extend the solo page downward`);
      await page.close();
      console.log(`PASS ${width}px: tray opens below nav; photo height is preserved`);
    }
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });

// ===== SNAPSMACK EOF =====
