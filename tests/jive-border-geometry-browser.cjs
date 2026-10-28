const { chromium } = require('../tools/browser-tests/runtime.cjs');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs'), assert = require('node:assert/strict');
const cases = JSON.parse(execFileSync('php', ['tests/jive-border-presentation-regression.php', '--json'], { encoding: 'utf8' }));
const css = fs.readFileSync('skins/jive-turkey/style.css', 'utf8');
const engine = fs.readFileSync('assets/js/ss-engine-jive-border.js', 'utf8');
(async () => {
  const browser = await chromium.launch();
  try {
    for (const viewport of [{ width: 1280, height: 800 }, { width: 390, height: 844 }]) {
      for (const item of cases) {
        const page = await browser.newPage({ viewport, reducedMotion: 'reduce' });
        const html = style => `<style>${css}</style>${style}<div class="jt-jive-turkey-bg" data-jt-border-enabled="1" data-jt-border-width="${item.jive.border_width}" data-jt-border-speed="${item.jive.border_speed}"></div><div class="jt-grid"><article class="jt-tile"><a href="#"><img alt="fixture"></a><span class="jt-ring"></span></article></div>`;
        const measure = () => {
          const image = document.querySelector('.jt-tile a');
          const ring = document.querySelector('.jt-ring');
          return { imageInset: parseFloat(getComputedStyle(image).left), ringEdge: parseFloat(ring.style.inset) + parseFloat(ring.style.padding) };
        };
        await page.setContent(html(''));
        await page.addScriptTag({ content: engine });
        const broken = await page.evaluate(measure);
        assert.notEqual(broken.imageInset, broken.ringEdge, 'Missing presentation must reproduce misalignment');
        // Fresh document/global state for the engine's once-only guard.
        await page.goto('about:blank');
        await page.setContent(html(item.style));
        await page.addScriptTag({ content: engine });
        const fixed = await page.evaluate(measure);
        assert.equal(fixed.imageInset, item.width);
        assert.equal(fixed.imageInset, fixed.ringEdge, 'Photo and band must meet at the same edge');
        await page.close();
      }
    }
    console.log('PASS: JIVE photo/ring seam at four selected widths, desktop/mobile; missing-variable mutation fails.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
