// SNAPSMACK_EOF_HEADER: end this file with the canonical SNAPSMACK EOF marker.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const seam = process.env.SNAP_BROWSER_SEAM_ROOT || path.resolve(__dirname, '../..');
const { chromium } = require(path.join(seam, 'tools/browser-tests/runtime.cjs'));
const root = path.resolve(__dirname, '../..');
const engine = fs.readFileSync(path.join(root, 'assets/js/ss-engine-glide.js'), 'utf8');

const tileMarkup = (vertical) => [180, 240, 200, 220].map((size, index) =>
    `<a class="glide-tile" href="#${index}" style="${vertical ? 'height' : 'width'}:${size}px">${index + 1}</a>`
).join('');

function pageMarkup(vertical, idle) {
    const rows = vertical ? 2 : 3;
    return `<!doctype html><meta charset="utf-8">
<style>
  *{box-sizing:border-box} body{margin:0;background:#111;color:white}
  [data-glide-wall]{${vertical ? 'display:flex;gap:30px;' : ''}}
  .glide-row{${vertical ? 'width:42vw;height:520px' : 'width:100vw;height:180px'};overflow:hidden;touch-action:none}
  .glide-track{display:flex;${vertical ? 'flex-direction:column;width:100%;height:max-content' : 'width:max-content;height:100%'};gap:20px;will-change:transform}
  .glide-tile{display:grid;place-items:center;${vertical ? 'width:100%' : 'height:100%'};flex:0 0 auto;background:#456;border:2px solid #fff}
  @media(prefers-reduced-motion:reduce){.glide-track{transform:none!important}}
  #outside{height:180px}
</style>
<main data-glide-wall data-travel="0.9" ${vertical ? 'data-flow-axis="vertical" data-flow-motion="axis"' : ''}
  ${idle ? 'data-idle-motion="alternating" data-idle-speed="14"' : ''}>
  ${Array.from({length: rows}, () => `<div class="glide-row" data-glide-row><div class="glide-track">${tileMarkup(vertical)}</div></div>`).join('')}
</main><div id="outside"></div>`;
}

async function inspectRows(page, vertical) {
    return page.locator('[data-glide-row]').evaluateAll((rows, downPage) => rows.map(row => {
        const rr = row.getBoundingClientRect();
        const rowCentre = downPage ? (rr.top + rr.bottom) / 2 : (rr.left + rr.right) / 2;
        const tiles = Array.from(row.querySelectorAll('.glide-tile')).map(tile => {
            const tr = tile.getBoundingClientRect();
            const centre = downPage ? (tr.top + tr.bottom) / 2 : (tr.left + tr.right) / 2;
            return {
                distance: Math.abs(centre - rowCentre),
                full: downPage
                    ? tr.top >= rr.top - 1 && tr.bottom <= rr.bottom + 1
                    : tr.left >= rr.left - 1 && tr.right <= rr.right + 1
            };
        }).sort((a, b) => a.distance - b.distance);
        return tiles[0];
    }), vertical);
}

async function waitCentred(page, vertical) {
    // The longest input used here coasts for about 1.4s on the engine's own
    // friction curve. Give it the coast plus the short centring ease, then
    // measure the actual resting geometry rather than a tile passing through
    // centre on its way somewhere else.
    await page.waitForTimeout(2400);
    const measured = await inspectRows(page, vertical);
    measured.forEach((row, index) => {
        assert.ok(row.distance <= 1, `row ${index + 1} rests centred (${row.distance}px)`);
        assert.equal(row.full, true, `row ${index + 1} rests on a fully visible tile`);
    });
}

async function moveEveryRow(page, kind, vertical) {
    await page.locator('[data-glide-row]').evaluateAll((rows, input) => rows.forEach((row, index) => {
        if (input.kind === 'wheel') {
            row.dispatchEvent(new WheelEvent('wheel', {
                bubbles: true, cancelable: true, deltaY: 150 + index * 35
            }));
            return;
        }
        const rect = row.getBoundingClientRect();
        const startX = rect.left + rect.width / 2;
        const startY = rect.top + rect.height / 2;
        const endX = startX + (input.vertical ? 0 : -170 - index * 25);
        const endY = startY + (input.vertical ? -170 - index * 25 : 0);
        const event = (name, x, y) => row.dispatchEvent(new PointerEvent(name, {
            bubbles: true, cancelable: true, pointerId: index + 20,
            pointerType: 'touch', isPrimary: true, button: 0, clientX: x, clientY: y
        }));
        event('pointerdown', startX, startY);
        event('pointermove', (startX + endX) / 2, (startY + endY) / 2);
        event('pointermove', endX, endY);
        event('pointerup', endX, endY);
    }), { kind, vertical });
}

async function standardMatrix(browser, width, vertical) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    await page.setContent(pageMarkup(vertical, false));
    await page.addScriptTag({ content: engine });
    await page.waitForTimeout(150);

    const tie = await page.evaluate(() => ({
        positive: SSGlide.nearestTarget(0, [100, 300], 200, 400, 1),
        negative: SSGlide.nearestTarget(0, [100, 300], 200, 400, -1)
    }));
    assert.deepEqual(tie, { positive: 100, negative: -100 }, 'last movement breaks an exact centre tie');

    await moveEveryRow(page, 'wheel', vertical);
    await waitCentred(page, vertical);
    await moveEveryRow(page, 'touch', vertical);
    await waitCentred(page, vertical);
    console.log(`PASS ${width}px ${vertical ? 'vertical' : 'horizontal'}: wheel and touch settle every row centred and fully visible`);
    await page.close();
}

async function hoverAndIdle(browser) {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.setContent(pageMarkup(false, true));
    await page.addScriptTag({ content: engine });
    const row = page.locator('[data-glide-row]').first();
    const box = await row.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await row.dispatchEvent('wheel', { deltaY: 180 });
    await page.waitForTimeout(2400);
    const centred = await inspectRows(page, false);
    assert.ok(centred[0].distance <= 1 && centred[0].full,
        'the row reaches its centred resting position before the idle hold is measured');
    const at = () => row.locator('.glide-track').evaluate(el => getComputedStyle(el).transform);
    const held = await at();
    await page.waitForTimeout(950);
    assert.equal(await at(), held, 'automatic glide stays paused while the pointer remains on the row');
    await page.mouse.move(10, 800);
    await page.waitForTimeout(950);
    assert.notEqual(await at(), held, 'automatic glide resumes after the pointer leaves and the idle delay passes');
    console.log('PASS hover: centred rest holds under pointer, then automatic glide resumes');
    await page.close();
}

async function reducedMotion(browser) {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.setContent(pageMarkup(false, true));
    await page.addScriptTag({ content: engine });
    const row = page.locator('[data-glide-row]').first();
    await row.dispatchEvent('wheel', { deltaY: 180 });
    await page.waitForTimeout(50);
    const first = await inspectRows(page, false);
    assert.ok(first[0].distance <= 1 && first[0].full, 'reduced motion settles instantly on a fully visible centred tile');
    const transform = await row.locator('.glide-track').evaluate(el => getComputedStyle(el).transform);
    await page.waitForTimeout(950);
    assert.equal(await row.locator('.glide-track').evaluate(el => getComputedStyle(el).transform), transform,
        'reduced motion never resumes automatic glide');
    console.log('PASS reduced motion: instant centred settle and no automatic glide');
    await page.close();
}

(async () => {
    const browser = await chromium.launch({ channel: 'chrome' });
    try {
        for (const width of [1920, 1440, 1024]) {
            await standardMatrix(browser, width, false);
            await standardMatrix(browser, width, true);
        }
        await hoverAndIdle(browser);
        await reducedMotion(browser);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
// ===== SNAPSMACK EOF =====
