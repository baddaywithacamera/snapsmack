/**
 * SS-1381 — rendered keyboard-help gate.
 *
 * Runs the real communications, public-help, and ALFRED archive engines in a
 * browser. The three page shapes named by the PM are checked at every
 * supported width: solo, GRAMOFSMACK, and the ALFRED archive lightbox.
 *
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const seam = process.env.SNAP_BROWSER_SEAM_ROOT || path.resolve(__dirname, '..', '..');
const { chromium } = require(path.join(seam, 'tools', 'browser-tests', 'runtime.cjs'));
const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const engines = {
  comms: read('assets/js/ss-engine-comms.js'),
  help: read('assets/js/ss-engine-public-help.js'),
  alfred: read('assets/js/ss-engine-alfred-archive.js'),
};

const photo = (colour, label) => 'data:image/svg+xml;base64,' + Buffer.from(
  `<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600">`
    + `<rect width="800" height="600" fill="${colour}"/>`
    + `<text x="40" y="80" fill="white" font-size="42">${label}</text></svg>`
).toString('base64');

const firstPhoto = photo('#693927', 'FIRST');
const secondPhoto = photo('#274769', 'SECOND');

const baseCss = `
  :root { --bg-primary: #111; --text-primary: #eee; --accent: #39ff14; }
  body { margin: 0; min-height: 100vh; background: #111; color: #eee; font: 18px Arial, sans-serif; }
  main { width: min(1100px, calc(100% - 48px)); margin: 40px auto; }
  .alfred-archive-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; }
  .alfred-archive-tile img { display: block; width: 100%; height: auto; }
  #alfred-archive-lightbox { position: fixed; inset: 0; z-index: 50; background: rgba(0,0,0,.9); }
  #alfred-archive-lightbox[hidden] { display: none; }
  .alfred-lb-img { display: block; width: min(70vw, 900px); max-height: 75vh; object-fit: contain; margin: 8vh auto 0; }
  .alfred-lb-prev, .alfred-lb-next, .alfred-lb-close { position: fixed; z-index: 51; top: 50%; }
  .alfred-lb-prev { left: 24px; }
  .alfred-lb-next { right: 24px; }
  .alfred-lb-close { top: 24px; right: 24px; }
`;

async function install(page, mode, markup, withAlfred = false) {
  await page.setContent(`<main>${markup}</main>`);
  await page.addStyleTag({ content: baseCss });
  await page.evaluate((siteMode) => {
    window.SNAP_SITE_MODE = siteMode;
    window.HIDE_SNAP_HELP = true;
  }, mode);
  await page.addScriptTag({ content: engines.help });
  await page.addScriptTag({ content: engines.comms });
  if (withAlfred) await page.addScriptTag({ content: engines.alfred });
}

async function pressF1(page) {
  await page.keyboard.press('F1');
  const help = page.locator('#snap-public-help-backdrop');
  await help.waitFor({ state: 'visible' });
  assert.equal(await help.evaluate((el) => el.matches('dialog:modal')), true,
    'F1 did not leave the public help dialog open');
  return help;
}

async function helpRows(help) {
  return help.locator('#snap-public-help-rows').evaluate((grid) => {
    const values = Array.from(grid.children).map((node) => node.textContent.trim());
    const rows = [];
    for (let i = 0; i < values.length; i += 2) rows.push([values[i], values[i + 1]]);
    return rows;
  });
}

function keys(rows) {
  return rows.map((row) => row[0]);
}

async function checkSolo(browser, width) {
  const page = await browser.newPage({ viewport: { width, height: 1000 } });
  try {
    await install(page, 'photoblog', `
      <h1>Solo photograph</h1>
      <a rel="prev" href="#newer">Newer</a>
      <a rel="next" href="#older">Older</a>
      <button id="show-details">Info</button>
      <button id="show-comments">Comments</button>
      <a class="snap-download-btn" href="#download">Download</a>
    `);
    const help = await pressF1(page);
    const rows = await helpRows(help);
    const listed = keys(rows);
    for (const key of ['LEFT', 'RIGHT', 'SPACE', '1', '2', 'D', 'F1', 'ESC']) {
      assert.ok(listed.includes(key), `${width}px solo help omitted ${key}`);
    }
    assert.equal(await help.locator('#snap-public-help-panel').isVisible(), true);
    return { width, page: 'solo', keys: listed, pass: true };
  } finally {
    await page.close();
  }
}

async function checkGram(browser, width) {
  const page = await browser.newPage({ viewport: { width, height: 1000 } });
  try {
    await install(page, 'carousel', `
      <h1>GRAMOFSMACK post</h1>
      <a rel="prev" href="#previous">Previous</a>
      <a rel="next" href="#next">Next</a>
    `);
    const help = await pressF1(page);
    const rows = await helpRows(help);
    const listed = keys(rows);
    for (const key of ['LEFT', 'RIGHT', 'SPACE', 'F1', 'ESC']) {
      assert.ok(listed.includes(key), `${width}px gram help omitted ${key}`);
    }
    for (const soloOnly of ['1', '2', 'D']) {
      assert.ok(!listed.includes(soloOnly), `${width}px gram listed solo-only key ${soloOnly}`);
    }
    return { width, page: 'gram', keys: listed, pass: true };
  } finally {
    await page.close();
  }
}

async function checkAlfred(browser, width) {
  const page = await browser.newPage({ viewport: { width, height: 1000 } });
  try {
    await install(page, 'smacktalk', `
      <h1>ALFRED archive</h1>
      <div class="alfred-archive-grid">
        <a class="alfred-archive-tile" href="#first" data-full="${firstPhoto}" data-title="First"><img src="${firstPhoto}" alt="First"></a>
        <a class="alfred-archive-tile" href="#second" data-full="${secondPhoto}" data-title="Second"><img src="${secondPhoto}" alt="Second"></a>
      </div>
      <div id="alfred-archive-lightbox" hidden>
        <button class="alfred-lb-close">Close</button>
        <button class="alfred-lb-prev">Previous</button>
        <img class="alfred-lb-img" alt="">
        <button class="alfred-lb-next">Next</button>
        <p class="alfred-lb-caption"></p>
      </div>
    `, true);

    await page.locator('.alfred-archive-tile').first().click();
    const box = page.locator('#alfred-archive-lightbox');
    assert.equal(await box.getAttribute('hidden'), null, `${width}px ALFRED lightbox did not open`);
    assert.equal(await box.locator('.alfred-lb-img').getAttribute('src'), firstPhoto);

    await page.keyboard.press('ArrowRight');
    assert.equal(await box.locator('.alfred-lb-img').getAttribute('src'), secondPhoto,
      `${width}px ArrowRight did not advance through ALFRED author order`);
    await page.keyboard.press('ArrowLeft');
    assert.equal(await box.locator('.alfred-lb-img').getAttribute('src'), firstPhoto,
      `${width}px ArrowLeft did not return through ALFRED author order`);

    const help = await pressF1(page);
    assert.equal(await box.getAttribute('hidden'), null,
      `${width}px F1 closed the ALFRED lightbox while opening help`);
    const listed = keys(await helpRows(help));
    for (const key of ['LEFT', 'RIGHT', 'F1', 'ESC']) {
      assert.ok(listed.includes(key), `${width}px ALFRED help omitted ${key}`);
    }

    // The PM required one F1 binding. One press opened the dialog; the next
    // must close it, not re-open it through a duplicate listener.
    await page.keyboard.press('F1');
    assert.equal(await help.isVisible(), false,
      `${width}px ALFRED F1 was handled more than once`);
    return { width, page: 'alfred-lightbox', keys: listed, pass: true };
  } finally {
    await page.close();
  }
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const results = [];
  try {
    for (const width of [1920, 1440, 1024]) {
      results.push(await checkSolo(browser, width));
      results.push(await checkGram(browser, width));
      results.push(await checkAlfred(browser, width));
    }
  } finally {
    await browser.close();
  }
  console.log(JSON.stringify({ pass: true, cases: results.length, results }, null, 2));
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
