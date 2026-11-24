// SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
// SS-1519: prove the real compiled skin rule reaches the browser's glyph paint.
'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const evidenceDir = path.join(__dirname, 'evidence', 'ss-1519-font-fallback-browser');
fs.mkdirSync(evidenceDir, { recursive: true });

function compiledStyle(family) {
  const bootstrap = [
    "define('BASE_URL', '/');",
    `require_once ${JSON.stringify(path.join(root, 'core', 'font-stack.php'))};`,
    `require_once ${JSON.stringify(path.join(root, 'core', 'trusted-html.php'))};`,
    `require_once ${JSON.stringify(path.join(root, 'core', 'skin-presentation.php'))};`,
    `echo snapsmack_declared_skin_style('rational-geo', ['body_font' => ${JSON.stringify(family)}]);`,
  ].join('');
  return execFileSync('C:\\php\\php.exe', ['-r', bootstrap], { encoding: 'utf8' });
}

function sha256(bytes) {
  return crypto.createHash('sha256').update(bytes).digest('hex');
}

async function paintCase(browser, width, family, expectedGeneric) {
  const page = await browser.newPage({ viewport: { width, height: 360 } });
  const compiled = compiledStyle(family);
  const missingFamily = `SS1519 Missing ${family}`;
  const expectedTail = `"${family}", ${expectedGeneric}`;
  assert.ok(compiled.includes(expectedTail),
    `${family}: real compiler did not emit ${expectedTail}`);
  // This machine has the shipped webfonts installed. Replace only the named
  // first face after compilation so the browser is forced down the exact tail
  // the real compiler emitted; the selected generic is left untouched.
  const css = compiled.replaceAll(`"${family}"`, `"${missingFamily}"`);
  const otherGeneric = expectedGeneric === 'serif' ? 'sans-serif' : 'serif';
  const text = 'Hamburgefontsiv 0123456789 &?!';

  await page.setContent(`<!doctype html><html><head>${css}<style>
    body { margin: 24px; background: #fff; color: #111; }
    .sample { box-sizing: border-box; width: 760px; height: 72px; overflow: hidden;
      font-size: 48px; font-weight: 400; font-style: normal; line-height: 1;
      letter-spacing: 0; white-space: nowrap; }
    #expected { font-family: ${expectedGeneric}; }
    #other { font-family: ${otherGeneric}; }
  </style></head><body>
    <div id="subject" class="sample rg-description">${text}</div>
    <div id="expected" class="sample">${text}</div>
    <div id="other" class="sample">${text}</div>
  </body></html>`);

  const computed = await page.locator('#subject').evaluate(element => getComputedStyle(element).fontFamily);
  const subject = await page.locator('#subject').screenshot();
  const expected = await page.locator('#expected').screenshot();
  const other = await page.locator('#other').screenshot();
  const hashes = { subject: sha256(subject), expected: sha256(expected), other: sha256(other) };

  assert.equal(hashes.subject, hashes.expected,
    `${width}px ${family}: painted glyphs do not match ${expectedGeneric}`);
  assert.notEqual(hashes.subject, hashes.other,
    `${width}px ${family}: ${expectedGeneric} and ${otherGeneric} paints were indistinguishable`);

  await page.screenshot({
    path: path.join(evidenceDir, `${family.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${width}.png`),
    fullPage: true,
  });
  await page.close();

  return { width, family, missingFamily, expectedGeneric, compiledTail: expectedTail,
    computed, hashes, pass: true };
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    for (const width of [1920, 1440, 1024]) {
      results.push(await paintCase(browser, width, 'Source Serif 4', 'serif'));
      results.push(await paintCase(browser, width, 'DM Sans', 'sans-serif'));
    }
  } finally {
    await browser.close();
  }

  const receipt = {
    ticket: 'SS-1519',
    scope: 'RATIONAL GEO body_font through the real compiler and browser paint',
    note: 'The named face is deliberately unavailable, so the first and second rows must paint identically while the opposite generic must differ.',
    results,
  };
  fs.writeFileSync(path.join(evidenceDir, 'results.json'), `${JSON.stringify(receipt, null, 2)}\n`);
  console.log(`SS-1519 BROWSER PASS: ${results.length}/${results.length} rendered cases; serif and sans fallbacks painted as compiled.`);
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
