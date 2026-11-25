// SNAPSMACK_EOF_HEADER
// Run the site compositor against the tile sizes proved in installed COLD SNAP.
const assert = require('node:assert/strict');
const { chromium } = require('../tools/browser-tests/runtime.cjs');

async function checkDecodedSource(browser, options) {
    const page = await browser.newPage({ viewport: {width: 2048, height: 1152} });
    try {
        await page.setContent('<main style="width:1920px"><div class="snap-mosaic"></div></main>');
        await page.addStyleTag({path: 'assets/css/ss-engine-mosaic.css'});
        await page.evaluate(({ derivative, original, lazy }) => {
            function photo(width, colour) {
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = width * 2 / 3;
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = colour;
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                return canvas.toDataURL();
            }
            const src = photo(derivative, 'steelblue');
            const full = original ? photo(original, 'goldenrod') : 'data:image/png;base64,broken';
            document.querySelector('.snap-mosaic').dataset.mosaic = JSON.stringify(
                Array.from({length: 5}, (_, i) => ({
                    id: i + 1, width: 1200, height: 800, src, full, lazy,
                    ...(i === 0 ? {weight: 1, href: '/photo'} : {}),
                }))
            );
        }, options);
        if (options.lazy) await page.addScriptTag({path: 'assets/js/ss-engine-lazyload.js'});
        await page.addScriptTag({path: 'assets/js/ss-engine-mosaic.js'});
        const img = page.locator('img[data-asset-id="1"]');
        await page.waitForFunction((expected) => {
            const photo = document.querySelector('img[data-asset-id="1"]');
            return photo?.complete && photo.naturalWidth === expected && photo.style.getPropertyValue('--mosaic-hover-scale');
        }, options.expected);
        const size = () => img.evaluate(photo => {
            const rect = photo.getBoundingClientRect();
            const tile = photo.closest('.mosaic-item').getBoundingClientRect();
            return {
                width: rect.width, height: rect.height, tileWidth: tile.width, tileHeight: tile.height,
                centreX: rect.x + rect.width / 2 - tile.x - tile.width / 2,
                centreY: rect.y + rect.height / 2 - tile.y - tile.height / 2,
                nativeWidth: photo.naturalWidth, nativeHeight: photo.naturalHeight,
                capped: photo.hasAttribute('data-upscale-capped'),
            };
        });
        const rest = await size();
        assert(rest.tileWidth > 1200, 'fixture must exercise the reference-width upscale');
        assert.equal(rest.capped, options.capped);
        assert(Math.abs(rest.centreX) < 1 && Math.abs(rest.centreY) < 1, 'mat stays centred');
        if (!options.capped) assert(Math.abs(rest.width - rest.tileWidth) < 1, 'a larger source fills the tile');
        await img.hover();
        await img.evaluate(photo => Promise.all(photo.getAnimations().map(animation => animation.finished)));
        const hover = await size();
        assert(hover.width <= hover.nativeWidth + 0.1 && hover.height <= hover.nativeHeight + 0.1,
            'hover never enlarges the image beyond decoded pixels');
        if (options.capped) assert.equal(hover.width, rest.width, 'a matted photograph stays at native size on hover');
        console.log(`PASS decoded source ${options.derivative} → ${options.expected}, ${options.capped ? 'matted' : 'fills tile'}`);
    } finally {
        await page.close();
    }
}

async function run() {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        await page.setContent('<section style="width:760px" data-merge-adjacent-mosaics></section>');
        await page.evaluate(() => {
            const scope = document.querySelector('section');
            for (const [count, adjusted] of [[4, false], [4, true], [6, true], [4, false]]) {
                const wall = document.createElement('div');
                wall.className = 'snap-mosaic';
                wall.dataset.gap = '4';
                wall.dataset.mosaic = JSON.stringify(Array.from({length: count}, (_, i) => ({
                    id: i + 1, width: 1200, height: 800,
                    src: 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
                    ...(adjusted && i === 2 ? {weight: 1} : {}),
                })));
                scope.append(wall);
            }
        });
        await page.addScriptTag({path: 'assets/js/ss-engine-mosaic.js'});
        const render = await page.evaluate(() => [...document.querySelectorAll('.snap-mosaic')].map(wall => {
            const tiles = [...wall.querySelectorAll('.mosaic-item')].map(tile => ({
                id: Number(tile.querySelector('img').dataset.assetId),
                width: parseFloat(tile.style.width), height: parseFloat(tile.style.height),
            }));
            const source = JSON.parse(wall.dataset.mosaic);
            return {
                tiles,
                referenceWidths: window.SnapMosaic.computeLayout(source, 1200, 4, 'natural', '').items
                    .map(tile => tile.width),
            };
        }));

        assert.deepEqual(render.map(mosaic => mosaic.tiles.length), [4, 4, 6, 4],
            'Adjusted mosaics must retain their own composition, even beside other mosaics');
        const [before, larger, many] = render;
        assert.deepEqual(larger.tiles.map(tile => tile.id), [1, 2, 3, 4]);
        assert.deepEqual(larger.tiles[2], {id: 3, width: 760, height: 506.67});
        assert.deepEqual(larger.tiles[0], {id: 1, width: 378, height: 252});
        assert.equal(before.tiles[2].width, 148.4);
        assert(many.tiles.every((tile, i) => Math.abs(tile.width - many.referenceWidths[i] * 760 / 1200) < 0.01),
            'The public browser must use the same five-or-more-photo reference width as the editor');
        console.log('PASS browser mosaic sizes match COLD SNAP, and adjusted blocks remain independent.');
        await checkDecodedSource(browser, {derivative: 900, original: 1800, expected: 1800, capped: false, lazy: true});
        await checkDecodedSource(browser, {derivative: 900, original: 1290, expected: 1290, capped: false});
        await checkDecodedSource(browser, {derivative: 900, original: 1200, expected: 1200, capped: true});
        await checkDecodedSource(browser, {derivative: 900, original: 0, expected: 900, capped: true});
        await checkDecodedSource(browser, {derivative: 0, original: 1800, expected: 1800, capped: false});
        await checkDecodedSource(browser, {derivative: 1800, original: 1200, expected: 1800, capped: false});
    } finally {
        await browser.close();
    }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
// ===== SNAPSMACK EOF =====
