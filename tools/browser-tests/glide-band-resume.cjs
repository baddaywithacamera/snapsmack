/**
 * SS-1217 — every GLIDE band resumes after interaction.
 *
 * Source checks cannot prove that requestAnimationFrame keeps advancing a
 * rendered track.  Exercise the interruption paths that left one live band
 * stopped while its neighbours continued, at every supported GLIDE width.
 */
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');

const ROOT = path.resolve(__dirname, '..', '..');
const GLIDE = fs.readFileSync(path.join(ROOT, 'assets/js/ss-engine-glide.js'), 'utf8');
const LIGHTBOX = fs.readFileSync(path.join(ROOT, 'assets/js/ss-engine-lightbox.js'), 'utf8');
const PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
const WIDTHS = [1920, 1440, 1024];

const rows = Array.from({ length: 5 }, (_, row) => {
  const tiles = Array.from({ length: 7 }, (_, tile) =>
    `<a class="glide-tile" href="#photo-${row}-${tile}">`
      + `<img src="${PIXEL}" data-lightbox-src="${PIXEL}" alt="Photo ${row}-${tile}">`
      + '</a>').join('');
  return `<div data-glide-row><div class="glide-track">${tiles}</div></div>`;
}).join('');

const PAGE = `<!doctype html><meta charset="utf-8">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: #000; }
  [data-glide-wall] { width: 100vw; height: 100vh; overflow: hidden; }
  [data-glide-row] { height: 18vh; overflow: hidden; }
  .glide-track { display: flex; gap: 12px; width: max-content; }
  .glide-tile { display: block; flex: 0 0 220px; height: 16vh; background: #333; }
  .glide-tile img { display: block; width: 100%; height: 100%; object-fit: cover; }
</style>
<body>
  <div data-glide-wall data-travel="0.9" data-idle-motion="alternating" data-idle-speed="30">
    ${rows}
  </div>
</body>`;

function transformX(value) {
  const match = /translate3d\(([-0-9.]+)px/.exec(value || '');
  return match ? Number(match[1]) : NaN;
}

async function freshPage(browser, width) {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  await page.setContent(PAGE);
  await page.addScriptTag({ content: LIGHTBOX });
  await page.addScriptTag({ content: GLIDE });
  await page.waitForTimeout(1150);
  return page;
}

async function movement(page, rowIndex = 1) {
  const track = page.locator('[data-glide-row] .glide-track').nth(rowIndex);
  const before = transformX(await track.evaluate((node) => node.style.transform));
  await page.waitForTimeout(350);
  const after = transformX(await track.evaluate((node) => node.style.transform));
  return { before, after, delta: Math.abs(after - before) };
}

async function assertResumed(page, label, width) {
  // A completed fling must settle before the engine's intentional 700ms idle
  // delay begins.  Give both phases room, then measure fresh drift.
  await page.waitForTimeout(1800);
  const result = await movement(page);
  if (!Number.isFinite(result.delta) || result.delta < 3) {
    throw new Error(`${width}px ${label}: band moved only ${result.delta}px`);
  }
  console.log(`PASS ${width}px ${label}: band moved ${result.delta.toFixed(1)}px after release`);
}

async function rowPoint(page) {
  const box = await page.locator('[data-glide-row]').nth(1).boundingBox();
  if (!box) throw new Error('second GLIDE band has no rendered box');
  return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
}

(async () => {
  const browser = await chromium.launch();
  try {
    for (const width of WIDTHS) {
      // A pointer click opens the viewer. Escape closes it and restores focus
      // to the tile; that pointer-origin focus must not become a permanent
      // accessibility pause.
      {
        const page = await freshPage(browser, width);
        await page.locator('[data-glide-row]').nth(1).locator('.glide-tile').first().click({ force: true });
        await page.waitForSelector('#ss-lightbox-overlay');
        await page.keyboard.press('Escape');
        await assertResumed(page, 'lightbox close', width);
        await page.close();
      }

      // A completed drag may fling, settle, and then return to idle drift.
      {
        const page = await freshPage(browser, width);
        const p = await rowPoint(page);
        await page.mouse.move(p.x, p.y);
        await page.mouse.down();
        await page.mouse.move(p.x - 80, p.y, { steps: 8 });
        await page.mouse.up();
        await assertResumed(page, 'drag release', width);
        await page.close();
      }

      // Browser cancellation must clear the drag state and restart idle drift.
      {
        const page = await freshPage(browser, width);
        await page.locator('[data-glide-row]').nth(1).evaluate((row) => {
          const box = row.getBoundingClientRect();
          const init = (x) => ({
            bubbles: true, cancelable: true, clientX: x,
            clientY: box.top + box.height / 2, pointerId: 77,
            pointerType: 'mouse', button: 0, isPrimary: true,
          });
          row.dispatchEvent(new PointerEvent('pointerdown', init(box.left + 200)));
          row.dispatchEvent(new PointerEvent('pointermove', init(box.left + 120)));
          row.dispatchEvent(new PointerEvent('pointercancel', init(box.left + 120)));
        });
        await assertResumed(page, 'pointer cancellation', width);
        await page.close();
      }

      // Losing the window while dragging follows the same cancellation path.
      {
        const page = await freshPage(browser, width);
        await page.locator('[data-glide-row]').nth(1).evaluate((row) => {
          const box = row.getBoundingClientRect();
          const init = (x) => ({
            bubbles: true, cancelable: true, clientX: x,
            clientY: box.top + box.height / 2, pointerId: 88,
            pointerType: 'mouse', button: 0, isPrimary: true,
          });
          row.dispatchEvent(new PointerEvent('pointerdown', init(box.left + 200)));
          row.dispatchEvent(new PointerEvent('pointermove', init(box.left + 120)));
          window.dispatchEvent(new Event('blur'));
        });
        await assertResumed(page, 'window focus loss', width);
        await page.close();
      }
    }
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(`FAIL ${error.message}`);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
