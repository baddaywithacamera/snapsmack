// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// Rendered geometry for SS-1528 piece 2. The fixture is ALFRED's real strict
// template and stylesheet. The fixture generator has no owner accounts, so
// this check supplies bounded owner values in the browser after load; the PHP
// regression separately proves the real component and order.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { chromium } = require('./runtime.cjs');

const fixture = path.resolve(process.argv[2] || '');
const shots = path.resolve(process.argv[3] || path.join(path.dirname(fixture), 'alfred-header-shots'));
if (!fs.existsSync(fixture)) throw new Error(`ALFRED fixture not found: ${fixture}`);
fs.mkdirSync(shots, { recursive: true });

const viewports = [
    { width: 1920, height: 1080 },
    { width: 1440, height: 900 },
    { width: 1024, height: 768 },
];

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        for (const viewport of viewports) {
            const page = await browser.newPage({ viewport });
            await page.goto(pathToFileURL(fixture).href, { waitUntil: 'load' });
            await page.evaluate(() => {
                const inside = document.querySelector('.site-header .inside');
                const tagline = document.createElement('p');
                tagline.className = 'site-tagline blog-description';
                tagline.textContent = 'An owner supplied tagline';
                const dock = document.createElement('nav');
                dock.className = 'social-dock social-dock-inline';
                dock.setAttribute('aria-label', 'Social profiles');
                dock.innerHTML = '<a class="dock-link u-url" href="#" aria-label="One"><svg viewBox="0 0 16 16"></svg></a>'
                    + '<a class="dock-link u-url" href="#" aria-label="Two"><svg viewBox="0 0 16 16"></svg></a>';
                inside.append(tagline, dock);
            });

            const measured = await page.evaluate(() => {
                const header = document.querySelector('.site-header');
                const title = document.querySelector('.site-header .site-title');
                const tagline = document.querySelector('.site-header .site-tagline');
                const dock = document.querySelector('.site-header .social-dock');
                const link = dock.querySelector('.dock-link');
                const rect = (el) => {
                    const r = el.getBoundingClientRect();
                    return { left: r.left, right: r.right, top: r.top, bottom: r.bottom,
                        width: r.width, height: r.height, center: r.left + r.width / 2 };
                };
                return { header: rect(header), title: rect(title), tagline: rect(tagline),
                    dock: rect(dock), link: rect(link), textAlign: getComputedStyle(tagline).textAlign };
            });

            const pageCenter = viewport.width / 2;
            console.log(`MEASURE ${viewport.width}px ${JSON.stringify(measured)}`);
            assert.ok(Math.abs(measured.header.center - pageCenter) < 1, `${viewport.width}: header is not centred`);
            assert.ok(Math.abs(measured.title.center - pageCenter) < 1, `${viewport.width}: logo/title is not centred`);
            assert.ok(Math.abs(measured.tagline.center - pageCenter) < 1, `${viewport.width}: tagline is not centred`);
            assert.ok(Math.abs(measured.dock.center - pageCenter) < 1, `${viewport.width}: social dock is not centred`);
            assert.equal(measured.textAlign, 'center', `${viewport.width}: tagline text is not centred`);
            assert.equal(measured.link.width, 36, `${viewport.width}: social circle width drifted`);
            assert.equal(measured.link.height, 36, `${viewport.width}: social circle height drifted`);
            assert.ok(measured.tagline.top >= measured.title.bottom, `${viewport.width}: tagline is not below logo/title`);
            assert.ok(measured.dock.top >= measured.tagline.bottom, `${viewport.width}: dock is not below tagline`);

            await page.screenshot({ path: path.join(shots, `alfred-header-${viewport.width}.png`), fullPage: true });
            console.log(`PASS ${viewport.width}px ${JSON.stringify(measured)}`);
            await page.close();
        }
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error.stack || error);
    process.exit(1);
});

// ===== SNAPSMACK EOF =====
