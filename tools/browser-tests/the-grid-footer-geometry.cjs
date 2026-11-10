// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
// Offline geometry proof; installed-build verification and owner acceptance are separate.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT
  || path.join(root, 'opaudits/evidence/1008/2026-11-10-the-grid-footer');
const cssPath = path.join(root, 'skins/the-grid/style.css');
const candidate = fs.readFileSync(cssPath, 'utf8');
const broken = execFileSync('git', ['show', 'a907ca0b:skins/the-grid/style.css'], {
  cwd: root,
  encoding: 'utf8',
});
const footer = '<footer id="system-footer" class="site-footer"><div id="footer">'
  + '<div class="footer-metadata-bar"><p>&copy; 2026 Example '
  + '<span class="sep">|</span> <a class="footer-link">RSS</a></p></div>'
  + '</div></footer>';
const historicalFooter = footer.replace('id="footer"', 'id="footer" class="inside"');

fs.mkdirSync(output, { recursive: true });

async function render(browser, width, sheet, markup, label) {
  const page = await browser.newPage({ viewport: { width, height: 768 } });
  await page.setContent('<!doctype html><meta charset="utf-8"><body class="the-grid-v2">'
    + '<div class="tg-content-wrap"><main><article class="tg-static-page">'
    + '<h1 class="tg-static-title">About</h1><div class="tg-static-content">'
    + '<p>Static page geometry fixture.</p></div></article></main>' + markup + '</div></body>');
  await page.addStyleTag({ content: sheet });
  const result = await page.evaluate(() => {
    const footerElement = document.querySelector('#system-footer');
    const wrapper = document.querySelector('#footer');
    const footerBox = footerElement.getBoundingClientRect();
    const wrapperBox = wrapper.getBoundingClientRect();
    const style = getComputedStyle(wrapper);
    return {
      footer: { x: footerBox.x, width: footerBox.width, height: footerBox.height },
      wrapper: { x: wrapperBox.x, width: wrapperBox.width, height: wrapperBox.height },
      paddingTop: parseFloat(style.paddingTop),
      paddingBottom: parseFloat(style.paddingBottom),
      overflow: document.documentElement.scrollWidth - innerWidth,
    };
  });
  await page.screenshot({ path: path.join(output, `${width}-${label}.png`), fullPage: true });
  await page.close();
  return result;
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const measurements = [];
  try {
    for (const width of [1920, 1440, 1024]) {
      const reference = await render(browser, width, broken, historicalFooter, 'historical-reference');
      const before = await render(browser, width, broken, footer, 'before');
      const after = await render(browser, width, candidate, footer, 'after');

      assert.equal(reference.paddingTop, 20, 'historical .inside wrapper supplies 20px top padding');
      assert.equal(reference.paddingBottom, 20, 'historical .inside wrapper supplies 20px bottom padding');
      assert.equal(before.paddingTop, 0, 'current markup with old CSS must reproduce missing top padding');
      assert.equal(before.paddingBottom, 0, 'current markup with old CSS must reproduce missing bottom padding');
      assert.equal(after.paddingTop, reference.paddingTop, 'repair restores historical top padding');
      assert.equal(after.paddingBottom, reference.paddingBottom, 'repair restores historical bottom padding');
      assert.equal(after.footer.height, reference.footer.height, 'repair restores historical computed footer height');
      assert.ok(after.footer.height >= before.footer.height + 40, 'repair restores the missing 40px vertical space');
      assert.equal(after.wrapper.width, reference.wrapper.width, 'repair preserves historical content width');
      assert.equal(after.wrapper.x, reference.wrapper.x, 'repair preserves historical centering');
      assert.equal(after.overflow, 0, 'repair must not create horizontal overflow');

      measurements.push({ width, reference, before, after });
    }
    fs.writeFileSync(path.join(output, 'measurements.json'), JSON.stringify({
      note: 'Offline actual THE GRID stylesheet and strict-layout footer markup. Historical reference uses the pre-migration .inside wrapper. Not installed-build or live verification.',
      introducingCommit: '6cab3a9ca7f35d907396e6bc4d23655903f82f17',
      measurements,
    }, null, 2));
    console.log(`PASS: THE GRID footer matches historical height at 1920/1440/1024; old CSS reproduces the 40px loss. ${output}`);
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
// ===== SNAPSMACK EOF =====
