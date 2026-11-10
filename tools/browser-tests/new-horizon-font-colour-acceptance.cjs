// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { chromium } = require('./runtime.cjs');

const fixtureDir = process.argv[2];
const evidenceDir = process.argv[3];
if (!fixtureDir || !evidenceDir) {
  throw new Error('Pass the fixture directory and evidence directory.');
}
fs.mkdirSync(evidenceDir, { recursive: true });

const changed = {
  title: 'rgb(193, 59, 74)',
  tagline: 'rgb(212, 154, 42)',
  heading: 'rgb(47, 163, 107)',
  body: 'rgb(57, 119, 197)',
  description: 'rgb(57, 119, 197)',
  footer: 'rgb(138, 90, 194)',
};
const defaults = {
  dark: {
    title: 'rgb(245, 245, 245)',
    tagline: 'rgb(255, 255, 255)',
    heading: 'rgb(255, 255, 255)',
    body: 'rgb(204, 204, 204)',
    description: 'rgb(170, 170, 170)',
    footer: 'rgb(204, 204, 204)',
  },
  light: {
    title: 'rgb(245, 245, 245)',
    tagline: 'rgb(255, 255, 255)',
    heading: 'rgb(17, 17, 17)',
    body: 'rgb(51, 51, 51)',
    description: 'rgb(85, 85, 85)',
    footer: 'rgb(51, 51, 51)',
  },
};
const selectors = {
  title: '.site-title-text',
  tagline: '.site-tagline-text',
  heading: '.static-page-title',
  body: '#body-copy',
  description: '.description',
  footer: '#sig-text',
};

(async () => {
  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const width of [1920, 1024]) {
      for (const variant of ['dark', 'light']) {
        for (const state of ['default', 'changed', 'reset']) {
          const page = await browser.newPage({ viewport: { width, height: 900 } });
          await page.goto(pathToFileURL(path.resolve(fixtureDir, `${variant}-${state}.html`)).href);
          const actual = await page.evaluate((map) => Object.fromEntries(
            Object.entries(map).map(([role, selector]) => {
              const element = document.querySelector(selector);
              if (!element) throw new Error(`Missing ${selector}`);
              return [role, getComputedStyle(element).color];
            })
          ), selectors);
          const expected = state === 'changed' ? changed : defaults[variant];
          assert.deepEqual(actual, expected, `${variant} ${state} at ${width}px`);
          const overflow = await page.evaluate(() => ({
            documentWidth: document.documentElement.scrollWidth,
            viewportWidth: document.documentElement.clientWidth,
          }));
          assert.ok(
            overflow.documentWidth <= overflow.viewportWidth,
            `${variant} ${state} overflows at ${width}px`
          );
          const screenshot = `${variant}-${state}-${width}.png`;
          await page.screenshot({ path: path.join(evidenceDir, screenshot), fullPage: true });
          results.push({ width, variant, state, actual, expected, overflow, screenshot, pass: true });
          await page.close();
        }
      }
    }
  } finally {
    await browser.close();
  }
  fs.writeFileSync(
    path.join(evidenceDir, 'measurements.json'),
    `${JSON.stringify(results, null, 2)}\n`
  );
  console.log(`PASS: ${results.length} NEW HORIZON colour render states at 1920px and 1024px.`);
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
