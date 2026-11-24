// TRUE GRIT archive geometry regression.
//
// Usage:
//   node tools/browser-tests/true-grit-archive-reference.cjs <fixture.html> <shot-dir>
//
// This is deliberately a rendered-browser check.  Markup cannot prove that
// the photograph kept its real proportions or that a shadow is not clipped.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '..', '..');
const fixture = path.resolve(process.argv[2] || '');
const shotDir = path.resolve(process.argv[3] || path.join(path.dirname(fixture), 'true-grit-shots'));

if (!fs.existsSync(fixture) || !fs.statSync(fixture).isFile()) {
    throw new Error(`TRUE GRIT fixture not found: ${fixture}`);
}
fs.mkdirSync(shotDir, { recursive: true });

const widths = [
    { name: 'reference', width: 1920, height: 1080 },
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'tablet', width: 1024, height: 768 },
];

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        for (const viewport of widths) {
            const page = await browser.newPage({ viewport });
            await page.goto(pathToFileURL(fixture).href, { waitUntil: 'load' });
            await page.locator('#browse-grid img').first().waitFor({ state: 'visible' });
            await page.addScriptTag({ path: path.join(root, 'assets', 'js', 'ss-engine-archive-filter.js') });

            const measured = await page.evaluate(() => {
                const grid = document.querySelector('#browse-grid');
                const filter = document.querySelector('#infobox');
                const links = [...document.querySelectorAll('#browse-grid > .tg-archive-item .thumb-link')];
                const images = [...document.querySelectorAll('#browse-grid > .tg-archive-item img')];
                return {
                    columns: getComputedStyle(grid).gridTemplateColumns.split(/\s+/).filter(Boolean).length,
                    shadows: links.map((link) => getComputedStyle(link).boxShadow),
                    containerOverflow: links.map((link) => getComputedStyle(link.parentElement).overflow),
                    filterBottom: filter ? filter.getBoundingClientRect().bottom : 0,
                    firstRowTops: links.slice(0, 4).map((link) => link.getBoundingClientRect().top),
                    imageRatios: images.map((image) => image.getBoundingClientRect().width / image.getBoundingClientRect().height),
                    naturalRatios: images.map((image) => image.naturalWidth / image.naturalHeight),
                    legacyLabels: [...document.querySelectorAll('.archive-legacy-filter label')].map((label) => label.textContent.trim()),
                    legacyPrompts: [...document.querySelectorAll('.archive-legacy-filter select')].map((select) => select.options[0].textContent.trim()),
                    modernPanel: !!document.querySelector('#smack-archive-filter-panel'),
                    archiveControls: !!document.querySelector('.archive-controls'),
                };
            });

            assert.equal(measured.columns, 4, `${viewport.name}: TRUE GRIT must remain four columns`);
            assert.ok(measured.shadows.every((shadow) => shadow !== 'none'), `${viewport.name}: soft shadows must remain`);
            assert.ok(measured.containerOverflow.every((overflow) => overflow === 'visible'), `${viewport.name}: shadows must not be clipped`);
            assert.ok(measured.firstRowTops.every((top) => top > measured.filterBottom), `${viewport.name}: first row must clear the filter bar`);
            measured.imageRatios.forEach((ratio, index) => {
                assert.ok(
                    Math.abs(ratio - measured.naturalRatios[index]) < 0.02,
                    `${viewport.name}: photograph ${index + 1} was cropped away from its real aspect ratio`,
                );
            });
            assert.deepEqual(measured.legacyLabels, ['REGISTRY:', 'ALBUMS:'], `${viewport.name}: legacy labels must be restored`);
            assert.deepEqual(measured.legacyPrompts, ['-- ALL CATEGORIES --', '-- ALL ALBUMS --'], `${viewport.name}: legacy prompts must be restored`);
            assert.equal(measured.modernPanel, false, `${viewport.name}: checkbox panel must not replace the selects`);
            assert.equal(measured.archiveControls, false, `${viewport.name}: T/M/C controls must not render`);

            await page.screenshot({ path: path.join(shotDir, `true-grit-archive-${viewport.width}.png`), fullPage: true });

            if (viewport.name === 'reference') {
                await page.evaluate(() => {
                    window.__legacySubmitted = false;
                    document.querySelector('.archive-select-form').addEventListener('submit', (event) => {
                        event.preventDefault();
                        window.__legacySubmitted = true;
                    });
                });
                await page.locator('#smack-archive-cat').selectOption({ index: 1 });
                assert.equal(await page.evaluate(() => window.__legacySubmitted), true, 'legacy category selection must submit');
            }

            console.log(`PASS ${viewport.name} ${viewport.width}px ${JSON.stringify(measured)}`);
            await page.close();
        }
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(`FAIL ${error.stack || error.message}`);
    process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
