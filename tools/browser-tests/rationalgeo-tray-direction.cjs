// SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
// RATIONAL GEO's real strict-render page keeps the photograph visible and
// opens INFO below the navigation. This deliberately does not build synthetic
// markup: SS-1322 passed such a fixture while a live post went blank and would
// not scroll.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const evidenceArg = process.argv.indexOf('--evidence-dir');
const evidenceDir = evidenceArg >= 0 ? path.resolve(process.argv[evidenceArg + 1] || '') : '';
const viewports = [
  { label: 'tablet-portrait', width: 1024, height: 1366 },
  { label: 'tablet-landscape', width: 1366, height: 1024 },
  { label: 'desktop-1440', width: 1440, height: 900 },
  { label: 'desktop-1920', width: 1920, height: 1080 },
];

function reservePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const port = server.address().port;
      server.close(error => error ? reject(error) : resolve(port));
    });
  });
}

async function waitForFixture(url, child) {
  let lastError = null;
  for (let attempt = 0; attempt < 50; attempt += 1) {
    if (child.exitCode !== null) throw new Error(`fixture server exited with ${child.exitCode}`);
    try {
      const response = await fetch(url);
      if (response.ok) return;
      lastError = new Error(`fixture returned HTTP ${response.status}`);
    } catch (error) {
      lastError = error;
    }
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw lastError || new Error('fixture server did not start');
}

async function measure(page) {
  return page.evaluate(() => {
    const rect = selector => {
      const box = document.querySelector(selector).getBoundingClientRect();
      return { top: box.top, bottom: box.bottom, width: box.width, height: box.height };
    };
    const stage = document.querySelector('#scroll-stage');
    const footer = document.querySelector('.rg-single > #footer');
    const image = document.querySelector('.rg-image.post-image');
    return {
      stage: {
        clientHeight: stage.clientHeight,
        scrollHeight: stage.scrollHeight,
        scrollTop: stage.scrollTop,
        overflowY: getComputedStyle(stage).overflowY,
      },
      photo: rect('.rg-photo-wrap'),
      image: { ...rect('.rg-image.post-image'), naturalWidth: image.naturalWidth },
      nav: rect('.rg-single > #infobox'),
      footer: { ...rect('.rg-single > #footer'), position: getComputedStyle(footer).position },
    };
  });
}

(async () => {
  if (evidenceArg >= 0) {
    assert.ok(evidenceDir, '--evidence-dir requires a directory');
    fs.mkdirSync(evidenceDir, { recursive: true });
  }

  const port = await reservePort();
  const router = path.join(root, 'tools', '_build', 'skin-visual-fixture-router.php');
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, router], {
    cwd: root,
    stdio: ['ignore', 'pipe', 'pipe'],
    windowsHide: true,
  });
  let serverLog = '';
  php.stdout.on('data', chunk => { serverLog += chunk; });
  php.stderr.on('data', chunk => { serverLog += chunk; });
  const url = `http://127.0.0.1:${port}/?skin=rational-geo&kind=photo`;

  let browser;
  try {
    await waitForFixture(url, php);
    browser = await chromium.launch({ headless: true, channel: 'msedge' });
    for (const viewport of viewports) {
      const page = await browser.newPage({ viewport });
      await page.goto(url, { waitUntil: 'load' });
      await page.locator('.rg-image.post-image').waitFor({ state: 'visible' });
      await page.evaluate(() => document.querySelector('.rg-image.post-image').decode());

      const closed = await measure(page);
      assert.ok(closed.image.naturalWidth > 0, `${viewport.label}: real fixture image did not load`);
      assert.ok(closed.photo.height > 200 && closed.image.height > 100,
        `${viewport.label}: photograph lane was blank or collapsed before INFO opened`);

      // Exercise the long-caption path that triggered the live SS-1345 report.
      // The DOM and all layout CSS still come from the real strict renderer.
      await page.evaluate(() => {
        const description = document.querySelector('.rg-description');
        const sentence = 'A long fixture paragraph proves that the outer page scrolls without moving the tray over the photograph.';
        for (let index = 0; index < 16; index += 1) {
          const paragraph = document.createElement('p');
          paragraph.textContent = `${sentence} Paragraph ${index + 1}.`;
          description.appendChild(paragraph);
        }
      });
      await page.locator('#show-details').click();
      await page.locator('.rg-single > #footer').waitFor({ state: 'visible' });
      const open = await measure(page);

      assert.equal(open.footer.position, 'static',
        `${viewport.label}: rejected overlay tray returned`);
      assert.ok(open.footer.top >= open.nav.bottom - 1,
        `${viewport.label}: INFO opened over the photograph or navigation`);
      assert.ok(Math.abs(open.photo.height - closed.photo.height) <= 1,
        `${viewport.label}: opening INFO changed the photograph lane height`);
      assert.ok(open.image.width > 100 && open.image.height > 100,
        `${viewport.label}: opening INFO blanked or collapsed the photograph`);
      assert.ok(['auto', 'scroll'].includes(open.stage.overflowY),
        `${viewport.label}: the real page has no vertical scroll container`);
      assert.ok(open.stage.scrollHeight > open.stage.clientHeight + 1,
        `${viewport.label}: INFO did not extend the real page downward`);

      await page.evaluate(() => {
        const stage = document.querySelector('#scroll-stage');
        stage.style.scrollBehavior = 'auto';
        stage.scrollTop = stage.scrollHeight;
      });
      const scrolled = await measure(page);
      assert.ok(scrolled.stage.scrollTop > 0,
        `${viewport.label}: the open real page would not scroll (${JSON.stringify(scrolled.stage)})`);

      if (evidenceDir) {
        await page.screenshot({
          path: path.join(evidenceDir, `rational-geo-${viewport.label}-open.png`),
          fullPage: true,
        });
      }
      await page.close();
      console.log(`PASS ${viewport.label}: image visible; INFO below nav; page scrolls`);
    }
  } finally {
    if (browser) await browser.close();
    php.kill();
    await new Promise(resolve => php.once('exit', resolve));
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
