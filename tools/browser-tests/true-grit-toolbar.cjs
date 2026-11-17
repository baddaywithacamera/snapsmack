// SNAPSMACK_EOF_HEADER: last non-empty line is the canonical EOF marker.
const { chromium } = require('./runtime.cjs');
const assert = require('node:assert/strict');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
(async () => {
    const fixture = process.argv[2];
    if (!fixture) throw new Error('Pass a rendered TRUE GRIT archive fixture with search and T/M/C enabled');
    const browser = await chromium.launch({ headless: true });
    try {
        for (const width of [1920, 1440, 1024]) {
            const page = await browser.newPage({ viewport: { width, height: 1080 } });
            await page.goto(pathToFileURL(path.resolve(fixture)).href);
            const geometry = await page.evaluate(() => {
                const rect = selector => { const r = document.querySelector(selector).getBoundingClientRect(); return { left:r.left, right:r.right, top:r.top, bottom:r.bottom }; };
                return { inner:rect('.archive-filter-inner'), controls:rect('.archive-controls'), search:rect('.archive-search-form'), row:rect('#infobox') };
            });
            assert(geometry.controls.left >= geometry.inner.right + 8, `${width}: toolbar stays outside centred content`);
            assert(geometry.controls.left >= geometry.search.right + 8, `${width}: toolbar clears search`);
            assert(geometry.controls.right <= width - 16, `${width}: toolbar stays inside viewport`);
            assert(geometry.controls.top >= geometry.row.top && geometry.controls.bottom <= geometry.row.bottom, `${width}: toolbar stays in row`);
            await page.close();
        }
        console.log('TRUE GRIT toolbar: 12 geometry checks PASS; installed behavior NOT CHECKED.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
// ===== SNAPSMACK EOF =====
