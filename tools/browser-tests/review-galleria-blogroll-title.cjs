// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('./runtime.cjs');
const root = path.resolve(__dirname, '../..');
const out = process.env.SNAPSMACK_BROWSER_OUTPUT || path.join(root, 'opaudits/evidence/027/2026-10-29-galleria-blogroll-title');
const cssPath = path.join(root, 'skins/galleria/style.css');
const candidate = fs.readFileSync(cssPath, 'utf8');
const previous = execFileSync('git', ['show', 'HEAD:skins/galleria/style.css'], { cwd: root, encoding: 'utf8' });
fs.mkdirSync(out, { recursive: true });

async function geometry(page) {
  return page.evaluate(() => {
    const box = e => { if (!e) return null; const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height }; };
    const title = document.querySelector('h1');
    const style = title && getComputedStyle(title);
    return {
      title: box(title), canvas: box(document.querySelector('.blogroll-canvas')),
      firstGroup: box(document.querySelector('.blogroll-category-block')),
      titleStyle: style && { font: style.fontFamily, size: style.fontSize, weight: style.fontWeight, spacing: style.letterSpacing, border: style.borderBottomWidth },
      main: box(document.querySelector('main')),
      images: [...document.querySelectorAll('main img')].slice(0, 6).map(box),
      overflow: document.documentElement.scrollWidth > innerWidth,
    };
  });
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    for (const width of [1920, 1440, 1024]) {
      for (const route of ['blogroll', 'about', 'archive-view']) {
        const rows = {};
        for (const [variant, css] of [['previous', previous], ['candidate', candidate]]) {
          const context = await browser.newContext({ viewport: { width, height: 1080 } });
          await context.route('**/skins/galleria/style.css*', r => r.fulfill({ contentType: 'text/css', body: css }));
          const page = await context.newPage();
          await page.goto('https://hekeepsdroningon.ca/' + route, { waitUntil: 'load' });
          await page.evaluate(() => Promise.race([document.fonts.ready, new Promise(r => setTimeout(r, 6000))]));
          await page.evaluate(() => Promise.race([Promise.all([...document.images].filter(i => i.getBoundingClientRect().top < innerHeight).map(i => i.decode().catch(() => {}))), new Promise(r => setTimeout(r, 6000))]));
          rows[variant] = await geometry(page);
          if (route === 'blogroll') await page.screenshot({ path: path.join(out, `${route}-${width}-${variant}.png`) });
          await context.close();
        }
        if (route === 'blogroll') {
          // The old stylesheet is the mutation: it must reproduce the owner defect.
          assert.ok(Math.abs(rows.previous.title.x - rows.previous.firstGroup.x) > 100, 'old CSS must expose the outside-column heading');
          assert.ok(Math.abs(rows.candidate.title.x - rows.candidate.firstGroup.x) < 1, 'heading must align to links');
          assert.ok(Math.abs(rows.candidate.title.width - rows.candidate.firstGroup.width) < 1, 'heading and links must share width');
          assert.equal(rows.candidate.firstGroup.x, rows.previous.firstGroup.x, 'link column x unchanged');
          assert.equal(rows.candidate.firstGroup.width, rows.previous.firstGroup.width, 'link column width unchanged');
          assert.equal(rows.candidate.titleStyle.size, '28px');
          assert.equal(rows.candidate.titleStyle.spacing, '6px');
          assert.ok(!rows.candidate.overflow, 'no horizontal overflow');
        } else {
          assert.deepEqual(rows.candidate, rows.previous, `${route} geometry must remain unchanged`);
        }
        results.push({ width, route, ...rows });
      }
    }
    fs.writeFileSync(path.join(out, 'measurements.json'), JSON.stringify({ note: 'Actual live markup, candidate skin stylesheet intercepted locally. Not deployed verification or admin save-path evidence.', results }, null, 2));
    console.log('PASS: old CSS reproduces title defect at all three widths; candidate aligns heading; About/archive geometry unchanged. ' + out);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
// ===== SNAPSMACK EOF =====
