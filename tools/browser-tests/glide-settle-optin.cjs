// SNAPSMACK_EOF_HEADER: end this file with the canonical SNAPSMACK EOF marker.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const seam = process.env.SNAP_BROWSER_SEAM_ROOT || path.resolve(__dirname, '../..');
const { chromium } = require(path.join(seam, 'tools/browser-tests/runtime.cjs'));
const root = path.resolve(__dirname, '../..');
const engine = fs.readFileSync(path.join(root, 'assets/js/ss-engine-glide.js'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'skins/glide/layout.php'), 'utf8');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT || '';

assert.match(layout, /data-glide-wall data-glide-settle="nearest"/,
    'the GLIDE landing page opts into the reviewed nearest-boundary engine');

const tiles = Array.from({ length: 12 }, (_, index) =>
    `<a class="glide-tile" href="#${index}"><span>${index + 1}</span></a>`).join('');

const pageMarkup = `<!doctype html><html><head><meta charset="utf-8"><style>
*{box-sizing:border-box}html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#090909;color:#fff}
.glide-page{position:fixed;inset:0;overflow:hidden}.glide-field{position:absolute;inset:8vh -20vw;display:grid;gap:24px;transform:rotate(-8deg)}
.glide-row{width:140vw;height:170px;overflow:hidden;touch-action:none}.glide-track{display:flex;width:max-content;height:100%;gap:20px;will-change:transform}
.glide-tile{display:grid;place-items:center;flex:0 0 220px;height:100%;background:linear-gradient(135deg,#17334a,#4f849a);border:2px solid #eee;color:#fff;text-decoration:none;font:700 36px sans-serif}
</style></head><body><main class="glide-page" data-glide-wall data-glide-settle="nearest" data-wall-inner-coverage data-idle-motion="off" data-travel="0.9">
<div class="glide-field">${Array.from({ length: 5 }, () => `<div class="glide-row" data-glide-row><div class="glide-track">${tiles}</div></div>`).join('')}</div>
</main></body></html>`;

async function exercise(browser, width) {
    const page = await browser.newPage({ viewport: { width, height: Math.round(width * 9 / 16) } });
    await page.setContent(pageMarkup);
    await page.addScriptTag({ content: engine });
    await page.waitForTimeout(150);

    const result = await page.evaluate(async () => {
        const rows = Array.from(document.querySelectorAll('[data-glide-row]'));
        const x = row => new DOMMatrix(getComputedStyle(row.querySelector('.glide-track')).transform).m41;
        const start = rows.map(x);
        rows.forEach((row, index) => row.dispatchEvent(new WheelEvent('wheel', {
            bubbles: true,
            cancelable: true,
            deltaY: 170 + index * 17
        })));

        const samples = [];
        const began = performance.now();
        while (performance.now() - began < 1800) {
            await new Promise(resolve => requestAnimationFrame(resolve));
            samples.push(rows.map(x));
        }
        return { start, samples };
    });

    for (let rowIndex = 0; rowIndex < result.start.length; rowIndex++) {
        const series = result.samples.map(sample => sample[rowIndex]);
        const deltas = series.slice(1).map((value, index) => value - series[index])
            .filter(delta => Math.abs(delta) > 0.02);
        assert.ok(Math.abs(series.at(-1) - result.start[rowIndex]) > 20,
            `row ${rowIndex + 1} moves after wheel input at ${width}px`);
        const direction = Math.sign(deltas[0]);
        assert.ok(deltas.every(delta => Math.sign(delta) === direction),
            `row ${rowIndex + 1} reaches rest without reversing at ${width}px`);
        assert.ok(Math.abs(series.at(-1) - series.at(-2)) < 0.15,
            `row ${rowIndex + 1} is at rest after one approach at ${width}px`);
    }

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    assert.ok(overflow <= 1, `GLIDE has no horizontal page overflow at ${width}px`);
    if (output) {
        fs.mkdirSync(output, { recursive: true });
        await page.screenshot({ path: path.join(output, `glide-settle-${width}.png`) });
    }
    console.log(`PASS ${width}px: every visible GLIDE row settles once without reversal`);
    await page.close();
}

(async () => {
    const browser = await chromium.launch({ channel: 'chrome' });
    try {
        for (const width of [3840, 1920, 1440, 1024]) await exercise(browser, width);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
// ===== SNAPSMACK EOF =====
