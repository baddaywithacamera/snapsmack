// SNAPSMACK_EOF_HEADER: end this file with the canonical SNAPSMACK EOF marker.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const seam = process.env.SNAP_BROWSER_SEAM_ROOT || path.resolve(__dirname, '../..');
const { chromium } = require(path.join(seam, 'tools/browser-tests/runtime.cjs'));
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const photo = 'data:image/svg+xml;base64,' + Buffer.from(
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="300"><rect width="200" height="300" fill="#936c39"/></svg>'
).toString('base64');

async function check(page, width) {
    await page.setViewportSize({ width, height: 1000 });
    await page.route('https://glide.test/**', route => route.fulfill({
        contentType: 'text/html',
        body: `<article><h1 class="static-page-title">About</h1><div class="static-content"><div class="page-hero"><img data-lightbox-src="${photo}" src="${photo}" alt="Portrait"></div><div class="description">About the photographer</div></div></article>`
    }));
    await page.goto('https://glide.test/');
    const layout = read('skins/glide/layout.php');
    const legacyAttribute = layout.includes('data-lightbox-src') ? `data-lightbox-src="${photo}"` : '';
    const tiles = ['One', 'Two', 'Three'].map((title, index) =>
        `<a class="glide-tile" href="/photo-${index}" data-wall-photo="${photo}" aria-label="${title}"><img ${legacyAttribute} src="${photo}" alt="${title}"></a>`
    ).join('');
    await page.setContent(`<main class="glide-page" data-glide-wall data-idle-motion="alternating"><nav data-wall-archive="/archive" data-wall-about="/about"><a href="/about">About</a></nav><div data-glide-row><div class="glide-track">${tiles}</div></div><div data-glide-row><div class="glide-track">${tiles}</div></div></main>`);
    await page.addStyleTag({ content: read('assets/css/public-base.css') });
    await page.addStyleTag({ content: read('skins/glide/style.css') });
    for (const engine of ['glide', 'comms', 'lightbox', 'public-help']) {
        await page.addScriptTag({ content: read(`assets/js/ss-engine-${engine}.js`) });
    }
    // Use the real click path, including the generic lightbox listener.
    await page.locator('.glide-tile').first().focus();
    await page.locator('.glide-tile img').first().dispatchEvent('click');
    await page.waitForFunction(() => document.querySelector('.wall-viewer[open] img')?.getAttribute('src'));
    assert.equal(await page.locator('#ss-lightbox-overlay').count(), 0);
    assert.equal(await page.locator('.wall-viewer figure').evaluate(el => getComputedStyle(el).outlineStyle), 'none');
    await page.waitForTimeout(350);
    await page.keyboard.press('ArrowRight');
    await page.waitForFunction(() => document.querySelector('.wall-viewer figcaption').textContent === 'Two');
    assert.match(await page.locator('.wall-viewer img').evaluate(img => img.getAnimations()[0].effect.getKeyframes()[0].transform), /90deg/);
    await page.waitForTimeout(350);
    await page.keyboard.press('Space');
    await page.waitForFunction(() => document.querySelector('.wall-viewer figcaption').textContent === 'One');
    assert.match(await page.locator('.wall-viewer img').evaluate(img => img.getAnimations()[0].effect.getKeyframes()[0].transform), /-90deg/);
    await page.keyboard.press('1');
    assert.equal(await page.locator('[data-wall-pane=info]').getAttribute('aria-pressed'), 'true');
    await page.keyboard.press('2');
    assert.equal(await page.locator('[data-wall-pane=comments]').getAttribute('aria-pressed'), 'true');
    await page.keyboard.press('F1');
    const help = page.locator('#snap-public-help-backdrop');
    assert.equal(await help.evaluate(el => el.matches('dialog:modal')), true);
    assert.equal(await help.locator('h2').evaluate(el => getComputedStyle(el).color), 'rgb(255, 255, 255)');
    assert.match(await help.innerText(), /Toggle comments/);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('.wall-viewer').evaluate(el => el.open), true);
    await page.keyboard.press('Escape');
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('.wall-viewer').open);
    await page.waitForTimeout(850);
    const before = await page.locator('.glide-track').first().evaluate(el => getComputedStyle(el).transform);
    await page.waitForTimeout(200);
    assert.notEqual(await page.locator('.glide-track').first().evaluate(el => getComputedStyle(el).transform), before);
    await page.locator('a[href="/about"]').click();
    await page.locator('.wall-portrait img').waitFor({ state: 'attached' });
    const opacity = await page.locator('.wall-portrait img').evaluate(async img => {
        const values = [];
        const start = performance.now();
        while (performance.now() - start < 1000) {
            values.push(Number(getComputedStyle(img).opacity));
            await new Promise(requestAnimationFrame);
        }
        return values;
    });
    assert.equal(opacity.at(-1), 1);
    assert.ok(opacity.every((value, index) => !index || value + 0.001 >= opacity[index - 1]), 'About portrait must not blink after its flip');
    const bounds = await page.locator('.wall-panel').evaluate(el => {
        const rect = el.getBoundingClientRect();
        return { left: rect.left, right: rect.right };
    });
    assert.ok(bounds.left >= 0 && bounds.right <= width);
    console.log(`PASS ${width}px: flips, keys, F1 theme/top layer, band restart and monotonic About entrance`);
}

(async () => {
    const browser = await chromium.launch({ channel: 'chrome' });
    try {
        for (const width of [1920, 1440, 1024]) {
            const page = await browser.newPage();
            page.setDefaultTimeout(6000);
            await check(page, width);
            await page.close();
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
// ===== SNAPSMACK EOF =====
