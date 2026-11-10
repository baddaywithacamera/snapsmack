// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT
  || path.join(os.tmpdir(), 'game-on-carousel-arrow');
const skinCss = fs.readFileSync(path.join(root, 'skins/game-on/style.css'), 'utf8');
const sliderCss = fs.readFileSync(path.join(root, 'assets/css/ss-engine-slider.css'), 'utf8');
const sliderJs = fs.readFileSync(path.join(root, 'assets/js/ss-engine-slider.js'), 'utf8');
const carouselJs = fs.readFileSync(path.join(root, 'assets/js/ss-engine-carousel-view.js'), 'utf8');

fs.mkdirSync(output, { recursive: true });

function svgData(width, height, colour, label) {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><rect width="100%" height="100%" fill="${colour}"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="white" font-family="sans-serif" font-size="72">${label}</text></svg>`;
  return `data:image/svg+xml,${encodeURIComponent(svg)}`;
}

const photos = [
  [1600, 1067, '#223a5e', 'ONE'],
  [1067, 1600, '#6e3847', 'TWO'],
  [1800, 1200, '#2f5d50', 'THREE'],
  [1200, 1200, '#755c2d', 'FOUR'],
  [1920, 1080, '#4b3b66', 'FIVE'],
];

function pageMarkup() {
  const slides = photos.map(([width, height, colour, label], index) => (
    `<div class="slider-slide" data-image-id="${index + 1}">`
    + `<img src="${svgData(width, height, colour, label)}" alt="Photo ${index + 1}">`
    + '</div>'
  )).join('');
  const dots = photos.map((photo, index) => (
    `<button type="button" class="ss-slider-dot${index === 0 ? ' is-active' : ''}" data-slide-index="${index}" aria-label="Photo ${index + 1}"></button>`
  )).join('');
  return '<!doctype html><html><head><meta charset="utf-8">'
    + '<meta name="viewport" content="width=device-width,initial-scale=1">'
    + `<style>${sliderCss}\n${skinCss}</style></head><body class="game-on-v2">`
    + '<div class="go-content-wrap landing-feed"><main>'
    + '<article class="go-post-ig"><div class="go-post-ig-image">'
    + '<div class="go-carousel-wrap"><div id="tg-carousel" class="ss-slider" data-slider-mode="carousel">'
    + `<div class="slider-track">${slides}</div></div></div></div>`
    + '<div class="go-post-ig-info"><div class="go-post-ig-body"><h1>Five-photo post</h1>'
    + '<div class="go-post-caption-block">Complete GAME ON carousel controller fixture.</div>'
    + '<div id="tg-exif-panel" class="go-exif-panel"></div></div></div></article></main>'
    + `<div class="ss-slider-dots" data-slider-indicators>${dots}</div></div>`
    + `<script>${sliderJs}</script><script>${carouselJs}</script></body></html>`;
}

async function measure(page) {
  return page.evaluate(() => {
    const box = (selector) => {
      const rect = document.querySelector(selector).getBoundingClientRect();
      return {
        x: rect.x,
        y: rect.y,
        right: rect.right,
        bottom: rect.bottom,
        width: rect.width,
        height: rect.height,
      };
    };
    return {
      viewport: { width: innerWidth, height: innerHeight },
      post: box('.go-post-ig'),
      imagePanel: box('.go-post-ig-image'),
      slider: box('#tg-carousel'),
      leftArrow: box('.slider-arrow-left'),
      rightArrow: box('.slider-arrow-right'),
      trackTransform: getComputedStyle(document.querySelector('.slider-track')).transform,
      activeDot: [...document.querySelectorAll('.ss-slider-dot')]
        .findIndex((dot) => dot.classList.contains('is-active')),
      overflow: document.documentElement.scrollWidth - innerWidth,
      errors: window.__probeErrors || [],
    };
  });
}

function assertOnscreen(result, width, phase) {
  const tolerance = 0.5;
  assert.ok(result.slider.width > 0, `${width}px ${phase}: slider must have positive width`);
  assert.ok(result.imagePanel.width > 0, `${width}px ${phase}: image panel must have positive width`);
  assert.ok(result.leftArrow.x >= -tolerance, `${width}px ${phase}: left arrow begins outside viewport`);
  assert.ok(result.rightArrow.right <= width + tolerance, `${width}px ${phase}: right arrow exceeds viewport`);
  assert.ok(result.leftArrow.x >= result.slider.x - tolerance, `${width}px ${phase}: left arrow exceeds slider`);
  assert.ok(result.rightArrow.right <= result.slider.right + tolerance, `${width}px ${phase}: right arrow exceeds slider`);
  assert.equal(result.overflow, 0, `${width}px ${phase}: horizontal overflow`);
  assert.deepEqual(result.errors, [], `${width}px ${phase}: browser errors`);
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    for (const [width, height] of [[1920, 1080], [1440, 900], [1024, 768]]) {
      const page = await browser.newPage({ viewport: { width, height } });
      await page.addInitScript(() => {
        window.__probeErrors = [];
        window.addEventListener('error', (event) => window.__probeErrors.push(event.message));
      });
      await page.setContent(pageMarkup(), { waitUntil: 'load' });
      await page.waitForSelector('.slider-arrow-right');
      await page.evaluate(() => Promise.all([...document.images].map((image) => image.decode())));
      await page.waitForTimeout(50);

      const initial = await measure(page);
      assert.equal(initial.activeDot, 0, `${width}px: carousel must initialize at first slide`);
      assertOnscreen(initial, width, 'initial');

      await page.locator('.slider-arrow-right').click();
      await page.waitForTimeout(450);
      const afterMouse = await measure(page);
      assert.equal(afterMouse.activeDot, 1, `${width}px: mouse arrow must advance one slide`);
      assert.notEqual(afterMouse.trackTransform, initial.trackTransform, `${width}px: mouse arrow must move track`);
      assert.equal(afterMouse.imagePanel.width, initial.imagePanel.width, `${width}px: mouse input changed image panel width`);
      assertOnscreen(afterMouse, width, 'after mouse');

      await page.keyboard.press('ArrowRight');
      await page.waitForTimeout(450);
      const afterKeyboard = await measure(page);
      assert.equal(afterKeyboard.activeDot, 2, `${width}px: keyboard arrow must advance one slide`);
      assert.notEqual(afterKeyboard.trackTransform, afterMouse.trackTransform, `${width}px: keyboard arrow must move track`);
      assert.equal(afterKeyboard.imagePanel.width, initial.imagePanel.width, `${width}px: keyboard input changed image panel width`);
      assertOnscreen(afterKeyboard, width, 'after keyboard');

      await page.screenshot({ path: path.join(output, `game-on-carousel-${width}.png`), fullPage: true });
      results.push({ width, initial, afterMouse, afterKeyboard });
      await page.close();
    }
    fs.writeFileSync(path.join(output, 'measurements.json'), `${JSON.stringify({
      scope: 'Source-build GAME ON post markup with registered slider CSS/JS and carousel controller. Not installed-build or live verification.',
      results,
    }, null, 2)}\n`);
    console.log(`PASS: GAME ON carousel arrows stay inside slider and viewport; mouse and keyboard advance with stable panel geometry at 1920/1440/1024. ${output}`);
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
// ===== SNAPSMACK EOF =====
