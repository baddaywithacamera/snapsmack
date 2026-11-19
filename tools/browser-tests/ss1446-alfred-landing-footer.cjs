/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 *
 * SS-1446 / SS-1447 — browser evidence for the ALFRED footer and packaged
 * storm fallback at every supported width. The square landing itself remains
 * outside this file until the PM assigns the shared engine CSS edit.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const skinDir = path.join(root, 'skins', 'alfred');
const baseHref = pathToFileURL(skinDir + path.sep).href;
const stormData = 'data:image/jpeg;base64,' + fs.readFileSync(
  path.join(skinDir, 'assets', 'images', 'sean-storm-panorama.jpg')
).toString('base64');
const widths = [1920, 1440, 1024];

const html = `<!doctype html><html><head><base href="${baseHref}"></head>
<body class="alfred-v2"><div id="page" class="alfred">
  <div class="header-image" aria-hidden="true"></div>
  <header id="header" class="site-header header section-inner"><div class="inside">
    <a class="site-title logo-area blog-title" href="#"><span class="site-title-text">Sean McCormick Photography</span></a>
  </div></header>
  <div id="content"><main id="scroll-stage" class="content post-inner reading-column longform">
    <article class="smacktalk-post post-container"><div class="post-content"><p>Fixture content.</p></div></article>
  </main></div>
  <footer id="system-footer" class="site-footer"><div id="footer" class="inside">
    <ul class="social-menu alfred-footer-social" aria-label="Social links">
      <li><a id="bsky" href="https://bsky.app/profile/example.test" target="_blank" rel="me noopener noreferrer"><span class="screen-reader-text">Bluesky</span></a></li>
      <li><a id="linktree" href="https://linktr.ee/example" target="_blank" rel="me noopener noreferrer"><span class="screen-reader-text">Linktree</span></a></li>
    </ul>
    <div class="footer-metadata-bar"><p>EMAIL: <a class="footer-link" href="mailto:sean@mccormickphotography.ca"><span id="email" class="reverse-email">ac.yhpargotohpkcimroccm@naes</span></a></p></div>
  </div></footer>
</div></body></html>`;

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    for (const width of widths) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.setContent(html, { waitUntil: 'load' });
      await page.addStyleTag({ path: path.join(root, 'assets', 'css', 'public-base.css') });
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
        const controls = Array.from(document.querySelectorAll('.alfred-footer-social a')).map((node) => {
          const rect = node.getBoundingClientRect();
          return { width: rect.width, height: rect.height, visible: rect.width > 0 && rect.height > 0 };
        });
        const rect = header.getBoundingClientRect();
        const email = getComputedStyle(document.getElementById('email'));
        return {
          background: headerStyle.backgroundImage,
          loaded,
          header: { left: rect.left, right: rect.right, height: rect.height },
          controls,
          email: { direction: email.direction, unicodeBidi: email.unicodeBidi },
          overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
      }, stormData);

      assert.match(result.background, /sean-storm-panorama\.jpg/, `${width}: Sean's storm file is the fallback`);
      assert.ok(result.loaded.width >= 2000 && result.loaded.height >= 700, `${width}: packaged storm image loads at its 2292x750 native resolution`);
      assert.ok(Math.abs(result.header.left) < 0.5 && Math.abs(result.header.right - width) < 0.5, `${width}: masthead spans the viewport`);
      assert.ok(result.header.height >= 890, `${width}: masthead retains the intended full-height presentation`);
      assert.equal(result.controls.length, 2, `${width}: Bluesky and Linktree are present`);
      for (const control of result.controls) {
        assert.ok(control.visible && control.width >= 44 && control.height >= 44, `${width}: footer social target is at least 44px`);
      }
      assert.equal(result.email.direction, 'rtl', `${width}: reversed email is turned back for readers`);
      assert.match(result.email.unicodeBidi, /bidi-override/, `${width}: email reversal is visual, not a broken address`);
      assert.ok(result.overflow <= 1, `${width}: no horizontal overflow`);
      await page.close();
    }
    console.log('PASS: ALFRED storm fallback, footer identities, email reversal, and overflow at 1920/1440/1024.');
    console.log('NOT CHECKED: square landing geometry; shared square-wall CSS ownership is awaiting PM assignment.');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
// ===== SNAPSMACK EOF =====
