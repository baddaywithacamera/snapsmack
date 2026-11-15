#!/usr/bin/env node
// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const seam = process.env.SNAP_BROWSER_SEAM_ROOT || __dirname;
const { chromium } = require(path.join(seam, 'runtime.cjs'));

const root = path.resolve(__dirname, '..', '..');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT || path.join(root, 'tmp', 'galleria-hip-to-be-square-variant');
const baseCss = fs.readFileSync(path.join(root, 'skins', 'galleria', 'style.css'), 'utf8');
const squareCss = fs.readFileSync(path.join(root, 'skins', 'galleria', 'variant-hip-to-be-square.css'), 'utf8');
const image = 'data:image/svg+xml,' + encodeURIComponent(
  '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="#6a8a96"/><circle cx="600" cy="400" r="220" fill="#ffe033"/></svg>'
);

// Skin customisation is emitted after the variant on the real page. These
// rules deliberately reproduce the original GALLERIA defaults, proving the
// named HIP 2 B SQUARE look remains whole rather than becoming half light and
// half dark when a site already has saved GALLERIA settings.
const savedGalleriaCss = `
:root{--wall-bg:#f5f0eb;--frame-color:#2c2017;--mat-color:#f5f0eb;--text-primary:#333;--text-secondary:#777;--accent-color:#b8965a}
body{background-image:url('skins/galleria/assets/wall-smooth-plaster.jpg');background-repeat:repeat}
.htbs-header{background-color:#fff}
.htbs-header .nav-menu a{color:#333}
.htbs-archive-item .frame-image{aspect-ratio:auto}
footer{background-color:#fff;color:#777}
`;

function documentHtml(variantCss) {
  return `<!doctype html><html><head><meta charset="utf-8"><style>${baseCss}</style>${variantCss ? `<style>${variantCss}</style>` : ''}<style>${savedGalleriaCss}</style></head>
  <body class="galleria-v2"><header class="htbs-header"><div class="inside"><a class="site-title-text">Gallery</a><ul class="nav-menu"><li><a>Archive</a></li></ul></div></header>
  <main id="scroll-stage"><section class="htbs-archive-grid"><a class="htbs-archive-item"><div class="frame-mount"><div class="frame-border"><div class="frame-mat"><div class="frame-bevel"><div class="frame-image"><img src="${image}" alt="Landscape fixture"></div></div></div></div></div><div class="htbs-archive-title">Landscape</div></a></section></main>
  <footer><div class="inside"><a>SnapSmack</a></div></footer></body></html>`;
}

async function measure(page) {
  return page.evaluate(() => {
    const frameBox = document.querySelector('.frame-image').getBoundingClientRect();
    const imageElement = document.querySelector('.frame-image img');
    const imageBox = imageElement.getBoundingClientRect();
    const style = selector => getComputedStyle(document.querySelector(selector));
    return {
      ratio: frameBox.width / frameBox.height,
      imageRatio: imageBox.width / imageBox.height,
      imageWidthDelta: Math.abs(imageBox.width - frameBox.width),
      imageHeightDelta: Math.abs(imageBox.height - frameBox.height),
      imageFit: getComputedStyle(imageElement).objectFit,
      overflow: document.documentElement.scrollWidth - innerWidth,
      bodyBg: style('body').backgroundColor,
      wallImage: style('body').backgroundImage,
      frame: style('.frame-border').backgroundColor,
      mat: style('.frame-mat').backgroundColor,
      header: style('.htbs-header').backgroundColor,
      nav: style('.nav-menu a').color,
      footer: style('footer').backgroundColor,
      footerText: style('footer').color,
      footerLink: style('footer a').color,
    };
  });
}

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    for (const width of [3840, 1920, 1440, 1024]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });

      await page.setContent(documentHtml(''), { waitUntil: 'load' });
      const original = await measure(page);
      assert.ok(Math.abs(original.ratio - 1.5) < 0.03, `${width}: original GALLERIA preserves landscape aspect`);
      assert.equal(original.bodyBg, 'rgb(245, 240, 235)', `${width}: original GALLERIA wall stays light`);
      assert.equal(original.frame, 'rgb(44, 32, 23)', `${width}: original GALLERIA frame stays brown`);
      assert.ok(original.overflow <= 0, `${width}: original GALLERIA has no horizontal overflow`);
      await page.screenshot({ path: path.join(output, `galleria-${width}.png`), fullPage: true });

      await page.setContent(documentHtml(squareCss), { waitUntil: 'load' });
      const square = await measure(page);
      assert.ok(Math.abs(square.ratio - 1) < 0.01, `${width}: HIP 2 B SQUARE archive crop is 1:1`);
      assert.ok(Math.abs(square.imageRatio - 1) < 0.01, `${width}: archive photograph itself renders 1:1`);
      assert.ok(square.imageWidthDelta < 0.5, `${width}: archive photograph fills the square width`);
      assert.ok(square.imageHeightDelta < 0.5, `${width}: archive photograph fills the square height`);
      assert.equal(square.imageFit, 'cover', `${width}: landscape photograph crops to fill the square`);
      assert.equal(square.bodyBg, 'rgb(31, 56, 69)', `${width}: dark teal wall wins over saved light defaults`);
      assert.match(square.wallImage, /wall-concrete\.jpg/, `${width}: concrete wall texture is active`);
      assert.equal(square.frame, 'rgb(255, 224, 51)', `${width}: yellow frame is active`);
      assert.equal(square.mat, 'rgb(255, 224, 51)', `${width}: yellow mat is active`);
      assert.equal(square.header, 'rgb(15, 32, 41)', `${width}: dark header is active`);
      assert.equal(square.nav, 'rgb(208, 221, 227)', `${width}: light navigation is active`);
      assert.equal(square.footer, 'rgb(15, 32, 41)', `${width}: dark footer is active`);
      assert.equal(square.footerText, 'rgb(106, 138, 150)', `${width}: muted footer text is active`);
      assert.equal(square.footerLink, 'rgb(0, 229, 255)', `${width}: cyan footer link is active`);
      assert.ok(square.overflow <= 0, `${width}: HIP 2 B SQUARE has no horizontal overflow`);
      await page.screenshot({ path: path.join(output, `hip-to-be-square-${width}.png`), fullPage: true });

      console.log(`PASS ${width}: original GALLERIA remains original; HIP 2 B SQUARE is teal/yellow/cyan and square`);
      await page.close();
    }
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });

// ===== SNAPSMACK EOF =====
