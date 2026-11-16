#!/usr/bin/env node
// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const seam = process.env.SNAP_BROWSER_SEAM_ROOT || __dirname;
const { chromium } = require(path.join(seam, 'runtime.cjs'));

const root = path.resolve(__dirname, '..', '..');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT || path.join(root, 'tmp', 'glide-tile-like');
const glideCss = fs.readFileSync(path.join(root, 'skins', 'glide', 'style.css'), 'utf8');
const likeCss = fs.readFileSync(path.join(root, 'assets', 'css', 'ss-tile-like.css'), 'utf8');
const likeJs = fs.readFileSync(path.join(root, 'assets', 'js', 'ss-engine-tile-like.js'), 'utf8');
const glideJs = fs.readFileSync(path.join(root, 'assets', 'js', 'ss-engine-glide.js'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'skins', 'glide', 'layout.php'), 'utf8');
const photo = 'data:image/svg+xml,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="#17334a"/><circle cx="600" cy="400" r="220" fill="#d88b3f"/></svg>'
);

assert.equal((layout.match(/class="glide-tile-frame ss-tile-like-host"/g) || []).length, 2,
    'both GLIDE conveyor data paths wrap the photo link and like control in one positioned host');
assert.equal((layout.match(/snap_render_component\('tile-like', \['item' => \$item\]\)/g) || []).length, 2,
    'both GLIDE conveyor data paths print the prepared tile-like component');
assert.equal((layout.match(/<\/a><\?php echo snap_render_html\(snap_render_component\('tile-like', \['item' => \$item\]\)\); \?><\/div>/g) || []).length, 2,
    'both GLIDE hearts are siblings after the photo link, never buttons nested inside links');

function documentHtml() {
    return `<!doctype html><html><head><meta charset="utf-8"><base href="https://fixture.test/archive/"><meta name="csrf-token" content="fixture-token">
    <style>${glideCss}</style><style>${likeCss}</style></head><body class="glide-v2">
    <main class="glide-page"><div class="glide-viewport"><div class="glide-field">
      <div class="glide-row"><div class="glide-track">
        <div class="glide-tile-frame ss-tile-like-host" style="--glide-aspect:3/2">
          <a class="glide-tile" href="#solo" aria-label="View photograph"><img src="${photo}" alt="Fixture photograph"></a>
          <button type="button" class="ss-tile-like" data-tile-like="42" data-liked="0" aria-pressed="false" aria-label="Like this photograph" title="Like this photograph">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
          </button>
        </div>
        <div class="glide-tile-frame ss-tile-like-host" style="--glide-aspect:3/2">
          <a class="glide-tile" href="#liked" aria-label="View liked photograph"><img src="${photo}" alt="Liked fixture photograph"></a>
          <button type="button" class="ss-tile-like is-liked" data-tile-like="43" data-liked="1" aria-pressed="true" aria-label="Unlike this photograph" title="Unlike this photograph">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
          </button>
        </div>
      </div></div>
    </div></div></main></body></html>`;
}

function tileFrame(id) {
    return `<div class="glide-tile-frame ss-tile-like-host" style="--glide-aspect:3/2">
      <a class="glide-tile" href="https://fixture.test/photo-${id}" aria-label="Photograph ${id}"><img src="${photo}" alt="Photograph ${id}"></a>
      <button type="button" class="ss-tile-like" data-tile-like="${id}" data-liked="0" aria-pressed="false" aria-label="Like this photograph" title="Like this photograph">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
      </button>
    </div>`;
}

function redistributionHtml() {
    return `<!doctype html><html><head><meta charset="utf-8"><base href="https://fixture.test/archive/"><meta name="csrf-token" content="fixture-token">
    <style>${glideCss}</style><style>${likeCss}</style></head><body class="glide-v2">
    <main class="glide-page" data-glide-wall data-wall-inner-coverage>
      <div class="glide-viewport"><div class="glide-field">
        ${[10, 20, 30, 40, 50].map(id => `<div class="glide-row" data-glide-row><div class="glide-track">${tileFrame(id)}</div></div>`).join('')}
      </div></div>
    </main></body></html>`;
}

async function installBehaviour(page) {
    await page.evaluate(() => {
        window.likeRequests = [];
        window.dragStarts = 0;
        document.querySelectorAll('.glide-row').forEach(row => {
            row.addEventListener('pointerdown', () => { window.dragStarts += 1; });
        });
        window.fetch = (url, options) => {
            window.likeRequests.push({ url, resolved: new URL(url, document.baseURI).href, method: options && options.method });
            return Promise.resolve({ json: () => Promise.resolve({ liked: true }) });
        };
    });
    await page.addScriptTag({ content: likeJs });
}

(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ headless: true });
    try {
        for (const width of [1920, 1440, 1024]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            await page.setContent(documentHtml(), { waitUntil: 'load' });
            await installBehaviour(page);

            const heart = page.locator('.ss-tile-like').first();
            const tile = page.locator('.glide-tile').first();
            assert.equal(await heart.evaluate(el => getComputedStyle(el).opacity), '0',
                `${width}: an unliked heart is quiet before hover`);
            assert.equal(await page.locator('.ss-tile-like.is-liked').evaluate(el => getComputedStyle(el).opacity), '1',
                `${width}: a liked heart remains visibly filled`);

            await tile.hover();
            await page.waitForFunction(() => Number.parseFloat(getComputedStyle(document.querySelector('.ss-tile-like')).opacity) > 0.99);
            assert.ok(await heart.evaluate(el => Number.parseFloat(getComputedStyle(el).opacity)) > 0.99,
                `${width}: hovering the photograph reveals the heart`);
            const geometry = await page.evaluate(() => {
                const heart = document.querySelector('.ss-tile-like');
                const heartStyle = getComputedStyle(heart);
                return {
                    left: heartStyle.left,
                    bottom: heartStyle.bottom,
                    width: heartStyle.width,
                    height: heartStyle.height,
                    text: heart.textContent.trim(),
                    overflow: document.documentElement.scrollWidth - innerWidth,
                };
            });
            assert.equal(geometry.left, '10px', `${width}: heart is 10px from the photograph's left edge`);
            assert.equal(geometry.bottom, '10px', `${width}: heart is 10px from the photograph's bottom edge`);
            assert.equal(geometry.width, '44px', `${width}: heart has a 44px target width`);
            assert.equal(geometry.height, '44px', `${width}: heart has a 44px target height`);
            assert.equal(geometry.text, '', `${width}: conveyor heart has no count`);
            assert.ok(geometry.overflow <= 0, `${width}: heart introduces no horizontal overflow`);
            await page.screenshot({ path: path.join(output, `glide-tile-like-hover-${width}.png`), fullPage: true });

            await heart.dispatchEvent('pointerdown', { pointerId: 7, pointerType: 'mouse', button: 0 });
            assert.equal(await page.evaluate(() => window.dragStarts), 0,
                `${width}: pressing the heart does not begin a conveyor drag`);
            await heart.dispatchEvent('click');
            await page.waitForFunction(() => window.likeRequests.length === 1);
            assert.equal(await page.evaluate(() => location.hash), '',
                `${width}: clicking the heart does not open the photograph`);
            assert.deepEqual(await page.evaluate(() => window.likeRequests[0]),
                { url: '/process-like.php', resolved: 'https://fixture.test/process-like.php', method: 'POST' },
                `${width}: heart uses the root endpoint even from a deeper page URL`);
            assert.equal(await heart.getAttribute('aria-pressed'), 'true', `${width}: successful like updates pressed state`);

            await page.screenshot({ path: path.join(output, `glide-tile-like-liked-${width}.png`), fullPage: true });
            console.log(`PASS ${width}: GLIDE heart is bottom-left, hover-revealed, count-free, and interaction-safe`);
            await page.close();
        }

        const touch = await browser.newPage({ viewport: { width: 1024, height: 900 }, hasTouch: true, isMobile: true });
        await touch.setContent(documentHtml(), { waitUntil: 'load' });
        assert.equal(await touch.evaluate(() => matchMedia('(hover: none)').matches), true,
            'touch fixture actually exercises the no-hover rule');
        assert.equal(await touch.locator('.ss-tile-like').first().evaluate(el => getComputedStyle(el).opacity), '0.72',
            'touch: unliked heart is always visible at quiet opacity');
        await touch.screenshot({ path: path.join(output, 'glide-tile-like-touch-1024.png'), fullPage: true });
        console.log('PASS touch 1024: GLIDE heart remains discoverable without hover');
        await touch.close();

        const redistributed = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        await redistributed.setContent(redistributionHtml(), { waitUntil: 'load' });
        await installBehaviour(redistributed);
        await redistributed.addScriptTag({ content: glideJs });
        const innerRow = redistributed.locator('.glide-row').nth(1);
        const innerOriginals = innerRow.locator('.glide-tile-frame:not([aria-hidden="true"])');
        await assert.doesNotReject(() => innerOriginals.nth(1).waitFor(),
            'the GLIDE engine redistributes a complete framed tile into the inner row');
        assert.equal(await innerOriginals.count(), 2,
            'redistribution adds the outer photograph as one complete frame');
        const scenery = innerRow.locator('.glide-tile-frame[aria-hidden="true"]');
        assert.ok(await scenery.count() > 0, 'the fixture exercises GLIDE scenery duplication after redistribution');
        assert.equal(await scenery.evaluateAll(frames => frames.every(frame =>
            Array.from(frame.querySelectorAll('a[href], button, input, select, textarea, [tabindex]'))
                .every(control => control.tabIndex === -1))), true,
        'aria-hidden scenery leaves no nested photo link or heart in the keyboard tab order');
        const redistributedHeart = innerOriginals.nth(1).locator('.ss-tile-like');
        assert.equal(await redistributedHeart.count(), 1,
            'the redistributed photograph keeps exactly one sibling heart');
        assert.equal(await redistributedHeart.evaluate(el => el.parentElement.classList.contains('glide-tile-frame')), true,
            'the redistributed heart remains inside the positioned frame and outside the photo link');
        await redistributedHeart.dispatchEvent('pointerdown', { pointerId: 9, pointerType: 'mouse', button: 0 });
        assert.equal(await redistributed.evaluate(() => window.dragStarts), 0,
            'pressing a redistributed heart does not begin a conveyor drag');
        await redistributedHeart.dispatchEvent('click');
        await redistributed.waitForFunction(() => window.likeRequests.length === 1);
        assert.deepEqual(await redistributed.evaluate(() => window.likeRequests[0]),
            { url: '/process-like.php', resolved: 'https://fixture.test/process-like.php', method: 'POST' },
            'a redistributed heart records the like through the root endpoint');
        await redistributed.screenshot({ path: path.join(output, 'glide-tile-like-redistributed-1440.png'), fullPage: true });
        console.log('PASS redistribution 1440: cloned inner-row tile keeps one working heart without flinging');
        await redistributed.close();
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

// ===== SNAPSMACK EOF =====
