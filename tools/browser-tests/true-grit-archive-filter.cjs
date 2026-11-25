// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// Run against an installed TRUE GRIT staging site with at least two
// photographs and a category assigned to exactly one of them.
// Usage: node tools/browser-tests/true-grit-archive-filter.cjs ARCHIVE_URL CATEGORY [EVIDENCE_DIR]

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');

async function main() {
    const [archiveUrl, category, output] = process.argv.slice(2);
    if (!archiveUrl || !category) throw new Error('Supply a staging archive URL and category label.');
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1080 } });
        page.setDefaultTimeout(10000);
        assert.equal((await page.goto(archiveUrl)).status(), 200);
        const consent = page.getByRole('button', { name: 'Continue without' });
        if (await consent.isVisible()) await consent.click();

        const tiles = page.locator('#browse-grid > .tg-tile');
        const selector = page.locator('#smack-archive-cat');
        const before = await tiles.count();
        assert.ok(before > 1, 'The unfiltered staging archive needs multiple photographs.');
        assert.equal(await selector.count(), 1, 'The live TRUE GRIT archive must expose its category select.');
        assert.equal(await page.locator('#smack-archive-filter-panel').count(), 0);

        await Promise.all([
            page.waitForURL(url => Boolean(url.searchParams.get('cat'))),
            selector.selectOption({ label: category }),
        ]);
        await tiles.first().waitFor({ state: 'visible' });
        assert.equal(await tiles.count(), 1, 'Only the matching photograph must remain.');
        assert.equal(await selector.locator('option:checked').textContent(), category);
        const filtered = await tiles.count();
        if (output) {
            fs.mkdirSync(output, { recursive: true });
            await page.screenshot({ path: path.join(output, 'true-grit-one-match.png') });
        }

        await page.getByRole('link', { name: /show all/i }).click();
        await tiles.first().waitFor({ state: 'visible' });
        assert.equal(await tiles.count(), before, 'Show All must restore the unfiltered archive.');
        const restored = await tiles.count();
        if (output) {
            await page.screenshot({ path: path.join(output, 'true-grit-show-all.png') });
            fs.writeFileSync(path.join(output, 'true-grit-filter.json'),
                JSON.stringify({ before, filtered, restored }, null, 2) + '\n');
        }
        console.log(`PASS: ${before} photographs, ${filtered} category match, ${restored} photographs after Show All.`);
    } finally {
        await browser.close();
    }
}

main().catch(error => {
    console.error(error);
    process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
