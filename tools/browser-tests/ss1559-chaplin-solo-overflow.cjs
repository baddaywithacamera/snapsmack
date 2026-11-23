/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 *
 * SS-1559 — measure the live CHAPLIN page, then apply the proposed skin CSS
 * locally and prove supported-width solo/archive geometry without site writes.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const cssPath = path.join(root, 'skins', 'chaplin', 'style.css');
const evidenceDir = path.join(__dirname, 'evidence', 'ss-1559-chaplin-solo-overflow');
const widths = [1920, 1440, 1024];
const soloUrl = 'https://acolourlesslife.ca/vulcan-bar-exterior-1794375704';
const archiveUrl = 'https://acolourlesslife.ca/archive';

fs.mkdirSync(evidenceDir, { recursive: true });

async function geometry(page, type) {
  return page.evaluate((pageType) => {
    const documentOverflow = Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth);
    if (pageType === 'solo') {
      const image = document.querySelector('.chap-img-frame > .post-image');
      const frame = image?.parentElement;
      const ir = image?.getBoundingClientRect();
      const fr = frame?.getBoundingClientRect();
      const style = image ? getComputedStyle(image) : null;
      return {
        documentOverflow,
        imageWidth: ir?.width || 0,
        imageRight: ir?.right || 0,
        frameWidth: fr?.width || 0,
        frameRight: fr?.right || 0,
        maxWidth: style?.maxWidth || '',
        naturalRatio: image?.naturalWidth && image?.naturalHeight ? image.naturalWidth / image.naturalHeight : 0,
        renderedRatio: ir?.width && ir?.height ? ir.width / ir.height : 0,
      };
    }
    const items = Array.from(document.querySelectorAll('.fsog-archive-item'));
    return {
      documentOverflow,
      itemCount: items.length,
      brokenImages: Array.from(document.images).filter((image) => image.complete && image.naturalWidth === 0).length,
    };
  }, type);
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const receipt = { generated_at: new Date().toISOString(), soloUrl, archiveUrl, widths, rows: [] };
  try {
    for (const width of widths) {
      const solo = await browser.newPage({ viewport: { width, height: 1080 } });
      await solo.goto(soloUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
      await solo.waitForTimeout(1000);
      const before = await geometry(solo, 'solo');
      await solo.addStyleTag({ path: cssPath });
      await solo.waitForTimeout(150);
      const after = await geometry(solo, 'solo');
      assert.equal(after.documentOverflow, 0, `${width}: solo page still overflows`);
      assert.ok(after.imageWidth <= after.frameWidth + 0.5, `${width}: image remains wider than frame`);
      assert.ok(after.imageRight <= width + 0.5, `${width}: image remains outside viewport`);
      assert.equal(after.maxWidth, '100%', `${width}: repaired selector did not apply`);
      assert.ok(Math.abs(after.naturalRatio - after.renderedRatio) < 0.002, `${width}: image aspect ratio changed`);
      receipt.rows.push({ page: 'solo', width, before, after, result: 'PASS' });
      await solo.screenshot({ path: path.join(evidenceDir, `chaplin-solo-after-${width}.png`), fullPage: false });
      await solo.close();

      const archive = await browser.newPage({ viewport: { width, height: 1080 } });
      await archive.goto(archiveUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
      await archive.waitForTimeout(1000);
      await archive.addStyleTag({ path: cssPath });
      await archive.waitForTimeout(150);
      const archiveAfter = await geometry(archive, 'archive');
      assert.equal(archiveAfter.documentOverflow, 0, `${width}: archive page overflows`);
      assert.ok(archiveAfter.itemCount > 0, `${width}: archive items disappeared`);
      assert.equal(archiveAfter.brokenImages, 0, `${width}: archive has broken images`);
      receipt.rows.push({ page: 'archive', width, after: archiveAfter, result: 'PASS' });
      await archive.close();
    }
  } finally {
    await browser.close();
  }
  fs.writeFileSync(path.join(evidenceDir, 'results.json'), JSON.stringify(receipt, null, 2));
  console.log('SS-1559 PASS: CHAPLIN solo and archive geometry at 1920, 1440 and 1024.');
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
