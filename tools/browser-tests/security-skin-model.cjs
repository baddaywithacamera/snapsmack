// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const siteRoot = path.join(root, 'projects/snapsmack-ca');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT
  || path.join(os.tmpdir(), 'snapsmack-ss-1272-security-model');
const port = Number(process.env.SNAPSMACK_BROWSER_PORT || 9862);
const pageUrl = `http://127.0.0.1:${port}/security.php#skin-security-model`;
const generatedVersion = path.join(siteRoot, 'includes/site-version.php');
let removeGeneratedVersion = false;

function waitForServer(server) {
  return new Promise((resolve, reject) => {
    let settled = false;
    const timeout = setTimeout(() => {
      if (!settled) reject(new Error('Local review server did not start.'));
    }, 10000);
    server.on('exit', code => {
      if (!settled) reject(new Error(`Local review server exited with ${code}.`));
    });
    (async () => {
      for (let attempt = 0; attempt < 50; attempt++) {
        try {
          const response = await fetch(pageUrl);
          const body = await response.text();
          if (response.ok && body.includes('skin-security-model')) {
            settled = true;
            clearTimeout(timeout);
            resolve();
            return;
          }
        } catch (_) {}
        await new Promise(done => setTimeout(done, 150));
      }
    })().catch(reject);
  });
}

(async () => {
  fs.mkdirSync(output, { recursive: true });
  if (!fs.existsSync(generatedVersion)) {
    fs.writeFileSync(generatedVersion, "<?php\ndefine('SS_PROMO_VERSION', 'review');\ndefine('SS_PROMO_DEV_VERSION', 'review');\ndefine('SS_PROMO_CODENAME', '');\n// ===== SNAPSMACK EOF =====\n");
    removeGeneratedVersion = true;
  }
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', siteRoot], {
    cwd: root,
    stdio: 'ignore',
    windowsHide: true,
  });
  let browser;
  const results = [];
  try {
    await waitForServer(server);
    browser = await chromium.launch({ headless: true, channel: 'msedge' });
    for (const width of [1920, 1440, 1024]) {
      const context = await browser.newContext({ viewport: { width, height: 1080 } });
      const page = await context.newPage();
      await page.goto(pageUrl, { waitUntil: 'load' });
      const section = page.locator('#skin-security-model');
      await section.scrollIntoViewIfNeeded();
      const measurement = await page.evaluate(() => {
        const box = element => {
          const rect = element.getBoundingClientRect();
          return { x: rect.x, y: rect.y, width: rect.width, height: rect.height };
        };
        const model = document.querySelector('#skin-security-model');
        const wrap = model.querySelector('.wrap');
        const cards = [...model.querySelectorAll('.skin-model-card')];
        const status = model.querySelector('.skin-model-status');
        return {
          model: box(model),
          wrap: box(wrap),
          cards: cards.map(box),
          status: box(status),
          heading: model.querySelector('h2').textContent.trim(),
          text: model.textContent.replace(/\s+/g, ' ').trim(),
          overflow: document.documentElement.scrollWidth > innerWidth,
          inlineStyles: model.querySelectorAll('[style]').length,
        };
      });
      assert.equal(measurement.heading, 'Why SnapSmack has no plugins.');
      assert.equal(measurement.cards.length, 4);
      assert.equal(measurement.inlineStyles, 0, 'skin security section must not use static style attributes');
      assert.equal(measurement.overflow, false, `security page must not overflow at ${width}px`);
      assert.ok(measurement.wrap.x >= 0 && measurement.wrap.x + measurement.wrap.width <= width,
        `skin security content must remain inside the ${width}px viewport`);
      assert.ok(Math.abs(measurement.cards[0].width - measurement.cards[1].width) < 2,
        `first card row must keep equal columns at ${width}px`);
      assert.ok(Math.abs(measurement.cards[2].width - measurement.cards[3].width) < 2,
        `second card row must keep equal columns at ${width}px`);
      for (const required of [
        'eight approved presentation helpers',
        'cannot ship JavaScript or arbitrary PHP behaviour',
        'a skin cannot supply it or turn it on',
        'same strict package gate',
        '30 installable skin packages',
        'SS-1264 remains open',
      ]) assert.ok(measurement.text.includes(required), `missing measured boundary: ${required}`);
      for (const overclaim of [
        'skins are data, not code',
        'refuses function calls',
        'leaves nothing behind',
      ]) assert.ok(!measurement.text.toLowerCase().includes(overclaim), `overclaim returned: ${overclaim}`);
      await section.screenshot({ path: path.join(output, `skin-security-model-${width}.png`) });
      results.push({ width, ...measurement });
      await context.close();
    }
    fs.writeFileSync(path.join(output, 'measurements.json'), JSON.stringify({
      note: 'Local staged security.php render. Not published or deployed verification.',
      results,
    }, null, 2));
    console.log(`PASS: staged skin-security model measured at 1920, 1440, and 1024. ${output}`);
  } finally {
    if (browser) await browser.close();
    server.kill();
    if (removeGeneratedVersion && fs.existsSync(generatedVersion)) fs.unlinkSync(generatedVersion);
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
// ===== SNAPSMACK EOF =====
