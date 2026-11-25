// SNAPSMACK_EOF_HEADER
// Run the site compositor against the tile sizes proved in installed COLD SNAP.
const assert = require('node:assert/strict');
const { chromium } = require('../tools/browser-tests/runtime.cjs');

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
    } finally {
        await browser.close();
    }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
// ===== SNAPSMACK EOF =====
