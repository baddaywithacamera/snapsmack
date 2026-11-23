// SS-1532 rendered font-stack verification.
//
// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');

const fixtureScript = path.join(__dirname, 'ss1532-font-fallback-fixture.php');
const html = execFileSync(process.env.SNAPSMACK_PHP || 'php', [fixtureScript], { encoding: 'utf8' });

const widths = [1920, 1440, 1024];
const familyList = (value) => value.split(',').map((item) => item.trim().replace(/^['"]|['"]$/g, ''));

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        for (const width of widths) {
            const page = await browser.newPage({ viewport: { width, height: 768 } });
            await page.setContent(html, { waitUntil: 'load' });
            const values = await page.evaluate(() => ({
                body: getComputedStyle(document.body).fontFamily,
                footer: getComputedStyle(document.querySelector('#system-footer .inside')).fontFamily,
            }));
            assert.deepEqual(
                familyList(values.body),
                ['DotMatrix-Condensed-Bold', 'DotMatrix', 'monospace'],
                `${width}: WRITING WITH IMPACT body must keep DotMatrix before monospace`,
            );
            assert.deepEqual(
                familyList(values.footer),
                ['DotMatrix-Condensed-Bold', 'DotMatrix', 'Courier New', 'monospace'],
                `${width}: IMPACT PRINTER footer must keep DotMatrix before Courier New`,
            );
            console.log(`PASS ${width}px ${JSON.stringify(values)}`);
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
