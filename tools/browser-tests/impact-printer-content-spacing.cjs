// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// SS-1561: measure the real IMPACT PRINTER cameras page before and after the
// candidate stylesheet.  This is deliberately a live-page injection proof:
// it checks the reported content and DOM, while never writing to the site.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '..', '..');
const candidateCss = fs.readFileSync(path.join(root, 'skins', 'impact-printer', 'style.css'), 'utf8');
const output = path.resolve(process.argv[2] || path.join(
    __dirname,
    'evidence',
    'ss-1561-impact-printer-spacing',
));
fs.mkdirSync(output, { recursive: true });

function nearlyEqual(a, b, tolerance = 0.25) {
    return Math.abs(a - b) <= tolerance;
}

async function measure(page) {
    return page.evaluate(() => {
        const full = document.querySelector('.static-transmission .description > .snap-inline-frame');
        const columns = document.querySelector('.static-transmission .snapsmack-columns');
        const columnFrames = [...document.querySelectorAll('.static-transmission .snapsmack-col .snap-inline-frame')];
        if (!full || !columns || columnFrames.length !== 2) return null;

        const fullRect = full.getBoundingClientRect();
        const fullNext = full.nextElementSibling;
        const fullNextRect = fullNext && fullNext.getBoundingClientRect();
        const columnsRect = columns.getBoundingClientRect();
        const columnsNext = columns.nextElementSibling;
        const columnsNextRect = columnsNext && columnsNext.getBoundingClientRect();
        const imageRects = [full, ...columnFrames].map(frame => {
            const image = frame.querySelector('img');
            const rect = image.getBoundingClientRect();
            return { width: rect.width, height: rect.height };
        });

        return {
            fullMarginBottom: parseFloat(getComputedStyle(full).marginBottom),
            fullGap: fullNextRect ? fullNextRect.top - fullRect.bottom : null,
            columnMarginsBottom: columnFrames.map(frame => parseFloat(getComputedStyle(frame).marginBottom)),
            columnsMarginBottom: parseFloat(getComputedStyle(columns).marginBottom),
            columnsGap: columnsNextRect ? columnsNextRect.top - columnsRect.bottom : null,
            imageRects,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    });
}

async function captureBlock(page, selector, target) {
    const locator = page.locator(selector);
    await locator.evaluate(element => element.scrollIntoView({ block: 'center', inline: 'nearest' }));
    await page.waitForTimeout(50);
    const clip = await locator.evaluate(element => {
        const first = element.getBoundingClientRect();
        const next = element.nextElementSibling && element.nextElementSibling.getBoundingClientRect();
        const left = Math.max(0, first.left - 16);
        const top = Math.max(0, first.top - 16);
        const right = Math.min(window.innerWidth, Math.max(first.right, next ? next.right : first.right) + 16);
        const bottom = Math.min(window.innerHeight, Math.max(first.bottom, next ? next.bottom : first.bottom) + 16);
        return { x: left, y: top, width: right - left, height: bottom - top };
    });
    await page.screenshot({ path: target, clip });
}

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        for (const width of [1920, 1440, 1024]) {
            const page = await browser.newPage({ viewport: { width, height: 1080 } });
            await page.goto('https://pixhellated.ca/cameras', { waitUntil: 'networkidle', timeout: 60000 });
            await page.locator('.static-transmission .snapsmack-columns').waitFor({ state: 'visible' });

            const before = await measure(page);
            assert.ok(before, `${width}: the reported full-width and two-column image blocks exist`);
            assert.ok(before.fullMarginBottom >= 55, `${width}: negative control retains the reported oversized full-width gap`);
            assert.ok(before.columnMarginsBottom.every(value => value >= 55), `${width}: negative control retains the reported 60px inside each column`);
            await captureBlock(page, '.static-transmission .description > .snap-inline-frame', path.join(output, `before-full-${width}.png`));
            await captureBlock(page, '.static-transmission .snapsmack-columns', path.join(output, `before-columns-${width}.png`));

            await page.addStyleTag({ content: candidateCss });
            const after = await measure(page);
            assert.ok(nearlyEqual(after.fullMarginBottom, 24), `${width}: standalone frame uses the 24px prose rhythm`);
            assert.ok(nearlyEqual(after.fullGap, 24), `${width}: standalone frame-to-paragraph gap is 24px`);
            assert.ok(after.columnMarginsBottom.every(value => nearlyEqual(value, 0)), `${width}: no 60px frame margin survives inside columns`);
            assert.ok(nearlyEqual(after.columnsMarginBottom, 24), `${width}: the columns keep their own 24px prose rhythm`);
            assert.ok(nearlyEqual(after.columnsGap, 24), `${width}: columns-to-paragraph gap is 24px`);
            before.imageRects.forEach((rect, index) => {
                assert.ok(nearlyEqual(after.imageRects[index].width, rect.width), `${width}: image ${index + 1} width is unchanged`);
                assert.ok(nearlyEqual(after.imageRects[index].height, rect.height), `${width}: image ${index + 1} height is unchanged`);
            });
            assert.ok(after.overflow <= 0.25, `${width}: no horizontal overflow`);

            await captureBlock(page, '.static-transmission .description > .snap-inline-frame', path.join(output, `after-full-${width}.png`));
            await captureBlock(page, '.static-transmission .snapsmack-columns', path.join(output, `after-columns-${width}.png`));
            console.log(`PASS ${width}: full frame 60->24px; column frames 60->0px; outer columns remain 24px; image geometry unchanged`);
            await page.close();
        }
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
