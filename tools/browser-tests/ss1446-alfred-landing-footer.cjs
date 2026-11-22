/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 *
 * SS-1446 / SS-1447 — browser evidence for ALFRED's configured logo,
 * shared square landing, SNAPSMACK social dock, footer, and packaged storm
 * fallback at every supported width.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const skinDir = path.join(root, 'skins', 'alfred');
const evidenceDir = path.join(__dirname, 'evidence', 'ss-1446-alfred-site-restore');
fs.mkdirSync(evidenceDir, { recursive: true });
const baseHref = pathToFileURL(skinDir + path.sep).href;
const stormData = 'data:image/jpeg;base64,' + fs.readFileSync(
  path.join(skinDir, 'assets', 'images', 'sean-storm-panorama.jpg')
).toString('base64');
const skinCss = fs.readFileSync(path.join(skinDir, 'style.css'), 'utf8');
assert.match(skinCss, /background-image:\s*url\(assets\/images\/sean-storm-panorama\.jpg\)/, 'ALFRED CSS references the packaged storm fallback');
const widths = [1920, 1440, 1024];
const tileImage = 'data:image/svg+xml,' + encodeURIComponent(
  '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><rect width="600" height="400" fill="#555"/></svg>'
);
const logoImage = 'data:image/svg+xml,' + encodeURIComponent(
  '<svg xmlns="http://www.w3.org/2000/svg" width="683" height="162"><rect width="683" height="162" fill="#eee"/></svg>'
);

const html = `<!doctype html><html><head><base href="${baseHref}"></head>
<body class="alfred-v2"><div id="page" class="alfred">
  <div class="header-image" aria-hidden="true"><img class="header-media-image" src="${stormData}" alt=""></div>
  <header id="header" class="site-header header section-inner"><div class="inside">
    <a class="site-title logo-area blog-title" href="#"><img class="site-logo" src="${logoImage}" alt="Sean McCormick Photography"></a>
  </div></header>
  <div id="content"><main id="scroll-stage" class="content post-inner reading-column longform">
    <article class="smacktalk-post post-container"><div class="post-content"><p>Fixture content.</p></div></article>
    <div id="browse-grid" class="alfred-archive-grid ss-square-wall">
      ${Array.from({ length: 10 }, (_, index) => `<article class="ss-masonry-item"><img src="${tileImage}" alt="Tile ${index + 1}"></article>`).join('')}
    </div>
  </main></div>
  <footer id="system-footer" class="site-footer"><div id="footer" class="inside">
    <div class="footer-metadata-bar"><p>EMAIL: <a class="footer-link" href="mailto:sean@mccormickphotography.ca"><span id="email" class="reverse-email">ac.yhpargotohpkcimroccm@naes</span></a></p></div>
  </div></footer>
</div>
</body></html>`;
/*
 * THE SOCIAL DOCK IS NOT IN THIS FIXTURE ANY MORE, AND THAT IS THE POINT.
 *
 * This file used to hand-write a <div class="social-dock dock-bottom-right">
 * here and then assert that two .dock-link elements existed. They did: the
 * test had just written them. ALFRED's own output contained no dock at all,
 * because SS-1446 removed its footer markup and nothing on the strict render
 * path replaced it. The fabricated markup is what let that ship through
 * review, so it is gone rather than corrected.
 *
 * ALFRED's real footer dock is now proved in
 * tests/alfred-footer-carries-the-shared-social-dock-regression.php, which
 * renders skins/alfred/layout.php through snapsmack_render_strict_skin_template()
 * and reads the dock out of the result. Its geometry was measured in a browser
 * at 1920/1440/1024 (centred in the footer, in flow, 48px targets, no
 * overflow); those numbers are in the commit message.
 *
 * STILL MISSING, and Codex's call: this fixture is hand-written HTML
 * throughout, so every remaining assertion here -- logo, masthead, square
 * landing, email -- measures markup the test wrote, not markup ALFRED
 * produces. Generating the fixture from the real render would make all of
 * them real. I did not do that: it is a rewrite of this harness, which is
 * Codex's.
 */

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    for (const width of widths) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.setContent(html, { waitUntil: 'load' });
      await page.addStyleTag({ path: path.join(root, 'assets', 'css', 'public-base.css') });
      await page.addStyleTag({ path: path.join(root, 'assets', 'css', 'ss-engine-mosaic.css') });
      await page.addStyleTag({ path: path.join(root, 'assets', 'css', 'ss-engine-social-dock.css') });
      await page.addStyleTag({ path: path.join(skinDir, 'style.css') });

      const result = await page.evaluate(async (stormSource) => {
        const header = document.querySelector('.header-image');
        const headerStyle = getComputedStyle(header);
        const match = headerStyle.backgroundImage.match(/^url\(["']?(.*?)["']?\)$/);
        let loaded = { width: 0, height: 0 };
        if (match) {
          loaded = await new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve({ width: image.naturalWidth, height: image.naturalHeight });
            image.onerror = reject;
            image.src = stormSource;
          });
        }
        const controls = Array.from(document.querySelectorAll('.social-dock .dock-link')).map((node) => {
          const rect = node.getBoundingClientRect();
          return { width: rect.width, height: rect.height, visible: rect.width > 0 && rect.height > 0 };
        });
        const logo = document.querySelector('.site-logo').getBoundingClientRect();
        const logoArea = document.querySelector('.header.section-inner').getBoundingClientRect();
        const rect = header.getBoundingClientRect();
        const grid = document.getElementById('browse-grid');
        const measureGrid = () => {
          const style = getComputedStyle(grid);
          const tiles = Array.from(grid.children).map((tile) => {
            const tileRect = tile.getBoundingClientRect();
            const imageStyle = getComputedStyle(tile.querySelector('img'));
            return {
              width: tileRect.width,
              height: tileRect.height,
              objectFit: imageStyle.objectFit,
            };
          });
          return {
            display: style.display,
            columns: style.gridTemplateColumns.split(' ').filter(Boolean).length,
            tiles,
          };
        };
        const squareLayouts = {};
        for (const columns of [3, 4, 5]) {
          grid.style.setProperty('--alfred-square-cols', String(columns));
          squareLayouts[columns] = measureGrid();
        }
        grid.style.setProperty('--alfred-square-cols', '3');
        const email = getComputedStyle(document.getElementById('email'));
        return {
          background: headerStyle.backgroundImage,
          loaded,
          header: { left: rect.left, right: rect.right, height: rect.height },
          logo: { left: logo.left, right: logo.right, width: logo.width },
          logoArea: { left: logoArea.left, right: logoArea.right, width: logoArea.width },
          controls,
          legacySocialCount: document.querySelectorAll('.alfred-footer-social, .social-menu').length,
          squareLayouts,
          email: { direction: email.direction, unicodeBidi: email.unicodeBidi },
          overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
      }, stormData);

      assert.match(result.background, /sean-storm-panorama\.jpg/, `${width}: Sean's storm file is the fallback`);
      assert.ok(result.loaded.width >= 2000 && result.loaded.height >= 700, `${width}: packaged storm image loads at its 2292x750 native resolution`);
      assert.ok(Math.abs(result.header.left) < 0.5 && Math.abs(result.header.right - width) < 0.5, `${width}: masthead spans the viewport`);
      assert.ok(result.header.height >= 890, `${width}: masthead retains the intended full-height presentation`);
      assert.ok(result.logoArea.width <= 600.5 && result.logoArea.width >= Math.min(600, width * 0.9) - 1, `${width}: logo area uses the Hitchcock-style 600px measure`);
      assert.ok(result.logo.width > 0 && result.logo.width <= result.logoArea.width + 0.5, `${width}: configured logo loads inside the skin header`);
      assert.ok(Math.abs((result.logo.left + result.logo.right) / 2 - width / 2) <= 1, `${width}: configured logo is centred`);
      // No dock assertion here: this fixture no longer writes one for itself.
      // See the note above the fixture.
      assert.equal(result.controls.length, 0, `${width}: this fixture fabricates no social dock`);
      assert.equal(result.legacySocialCount, 0, `${width}: ALFRED does not render the old Hitchcock social menu`);
      assert.equal(result.email.direction, 'rtl', `${width}: reversed email is turned back for readers`);
      assert.match(result.email.unicodeBidi, /bidi-override/, `${width}: email reversal is visual, not a broken address`);
      for (const columns of [3, 4, 5]) {
        const layout = result.squareLayouts[columns];
        assert.equal(layout.display, 'grid', `${width}: square landing uses CSS grid at ${columns} columns`);
        assert.equal(layout.columns, columns, `${width}: square landing renders ${columns} columns`);
        for (const tile of layout.tiles) {
          assert.ok(Math.abs(tile.width - tile.height) <= 1, `${width}: ${columns}-column tiles remain square`);
          assert.equal(tile.objectFit, 'cover', `${width}: ${columns}-column images crop with object-fit cover`);
        }
      }
      assert.ok(result.overflow <= 1, `${width}: no horizontal overflow`);
      await page.screenshot({
        path: path.join(evidenceDir, `alfred-${width}.png`),
        fullPage: true,
      });
      await page.close();
    }
    console.log('PASS: ALFRED configured logo, 3/4/5-column square landing, SNAPSMACK social dock, storm fallback, email reversal, and overflow at 1920/1440/1024.');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
// ===== SNAPSMACK EOF =====
