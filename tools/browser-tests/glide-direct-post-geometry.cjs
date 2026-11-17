#!/usr/bin/env node
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const seam = process.env.SNAP_BROWSER_SEAM_ROOT || path.resolve(__dirname, '..', '..');
const { chromium } = require(path.join(seam, 'tools', 'browser-tests', 'runtime.cjs'));

const root = path.resolve(__dirname, '..', '..');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT || '';
const css = fs.readFileSync(path.join(root, 'skins', 'glide', 'style.css'), 'utf8');
const fixtures = {
    landscape: '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1067"><rect width="100%" height="100%" fill="#833"/><circle cx="800" cy="460" r="270" fill="#d9b56f"/></svg>',
    portrait: '<svg xmlns="http://www.w3.org/2000/svg" width="1067" height="1600"><rect width="100%" height="100%" fill="#245"/><circle cx="533" cy="720" r="260" fill="#9bd"/></svg>'
};
const pageMarkup = image => `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head>
<body class="glide-v2"><header id="header" class="site-header"><div class="inside"><a class="site-title logo-area"><span class="site-title-text">The Portal Project</span></a><ul class="nav-menu"><li><a>Home</a></li><li><a>Archive</a></li><li><a>About</a></li></ul></div></header>
<main class="public-content"><article class="single-image-page"><h1 class="photo-title-footer">Rockyford Fire Department Entrance</h1><div class="post-image-wrap"><img class="post-image" src="data:image/svg+xml,${encodeURIComponent(image)}" alt="Fixture"></div><div id="infobox"><p>A photograph and its direct-post story remain readable below the image.</p></div></article></main>
<footer id="system-footer"><div class="inside"><p>GLIDE · SnapSmack</p></div></footer></body></html>`;

(async () => {
    if (output) fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ headless: true });
    try {
        for (const width of [3840, 1920, 1440, 1024]) {
            for (const [orientation, image] of Object.entries(fixtures)) {
                const height = width === 3840 ? 2160 : 900;
                const page = await browser.newPage({ viewport: { width, height } });
                await page.setContent(pageMarkup(image), { waitUntil: 'load' });
                const geometry = await page.evaluate(() => {
                    const body = document.body.getBoundingClientRect();
                    const article = document.querySelector('.single-image-page').getBoundingClientRect();
                    const image = document.querySelector('.post-image').getBoundingClientRect();
                    const title = document.querySelector('.photo-title-footer').getBoundingClientRect();
                    const bg = getComputedStyle(document.body).backgroundColor;
                    return { body, article, image, title, bg, overflow: document.documentElement.scrollWidth - innerWidth };
                });
                assert.match(geometry.bg, /rgb\((?:11|8), (?:11|8), (?:11|8)\)/, `${width} ${orientation}: GLIDE dark canvas is active`);
                assert.ok(geometry.article.left >= 20 && geometry.article.right <= width - 20, `${width} ${orientation}: article stays inside the viewport`);
                assert.ok(Math.max(geometry.image.width, geometry.image.height) > 500, `${width} ${orientation}: photograph remains dominant`);
                assert.ok(geometry.title.width > 250, `${width} ${orientation}: title remains readable`);
                assert.ok(geometry.overflow <= 0, `${width} ${orientation}: no horizontal overflow`);
                const visibleCentre = (geometry.body.left + geometry.body.right) / 2;
                assert.ok(Math.abs((geometry.image.left + geometry.image.right) / 2 - visibleCentre) <= 1,
                    `${width} ${orientation}: photograph is centred in the visible page`);
                assert.ok(geometry.image.top >= 0 && geometry.image.bottom <= height + 1,
                    `${width} ${orientation}: photograph stays fully visible vertically`);
                assert.ok(geometry.title.top >= geometry.image.bottom - 1,
                    `${width} ${orientation}: title follows the photograph rather than pulling it off-centre`);
                if (output) await page.screenshot({ path: path.join(output, `glide-direct-post-${orientation}-${width}.png`), fullPage: true });
                console.log(`PASS ${width} ${orientation}: GLIDE direct post is centred, contained, and photo-led`);
                await page.close();
            }
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

// ===== SNAPSMACK EOF =====
