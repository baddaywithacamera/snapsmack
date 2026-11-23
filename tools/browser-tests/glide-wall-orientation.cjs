// SNAPSMACK_EOF_HEADER: end this file with the canonical SNAPSMACK EOF marker.
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const seam = process.env.SNAP_BROWSER_SEAM_ROOT || root;
const { chromium } = require(path.join(seam, 'tools/browser-tests/runtime.cjs'));
const engine = fs.readFileSync(path.join(root, 'assets/js/ss-engine-glide.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'skins/glide/style.css'), 'utf8');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT || '';

const tiles = Array.from({ length: 8 }, (_, index) =>
    `<div class="glide-tile-frame" style="--glide-aspect:3/2"><a class="glide-tile" href="#${index}"><img alt="" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='200'%3E%3Crect width='300' height='200' fill='%23${index % 2 ? 'cf6f36' : '527d8d'}'/%3E%3C/svg%3E"></a></div>`
).join('');

function markup(mode, axis) {
    const declaration = mode === null ? '' : ` style="--glide-wall-orientation:${mode}"`;
    const flowMotion = axis === 'vertical' ? ' data-flow-motion="axis"' : '';
    return `<!doctype html><html style="--glide-angle:-20deg"><head><meta charset="utf-8"></head><body>
<main class="glide-page" data-glide-wall data-idle-motion="off" data-flow-axis="${axis}"${flowMotion}${declaration}>
<div class="glide-viewport"><div class="glide-field">${Array.from({ length: 4 }, () =>
        `<div class="glide-row" data-glide-row><div class="glide-track">${tiles}</div></div>`).join('')}</div></div>
</main></body></html>`;
}

function angle(matrix) {
    const m = new DOMMatrix(matrix === 'none' ? undefined : matrix);
    return Math.atan2(m.b, m.a) * 180 / Math.PI;
}

async function measure(browser, width, mode, axis = 'vertical', reduced = false) {
    const page = await browser.newPage({ viewport: { width, height: Math.round(width * 9 / 16) } });
    await page.emulateMedia({ reducedMotion: reduced ? 'reduce' : 'no-preference' });
    await page.setContent(markup(mode, axis));
    await page.addStyleTag({ content: css });
    await page.addScriptTag({ content: engine });
    await page.waitForTimeout(100);
    const result = await page.evaluate(() => {
        const field = document.querySelector('.glide-field');
        const frame = document.querySelector('.glide-tile-frame');
        const track = document.querySelector('.glide-track');
        const before = getComputedStyle(track).transform;
        document.querySelector('.glide-row').dispatchEvent(new WheelEvent('wheel', {
            bubbles: true, cancelable: true, deltaY: 240
        }));
        return new Promise(resolve => setTimeout(() => resolve({
            field: getComputedStyle(field).transform,
            frame: getComputedStyle(frame).transform,
            before,
            after: getComputedStyle(track).transform,
            effective: getComputedStyle(document.querySelector('.glide-page')).getPropertyValue('--glide-effective-angle').trim(),
            counter: getComputedStyle(document.querySelector('.glide-page')).getPropertyValue('--glide-tile-counter-angle').trim()
        }), 220));
    });
    const fieldAngle = await page.evaluate(angle, result.field);
    const frameAngle = await page.evaluate(angle, result.frame);
    if (output) {
        fs.mkdirSync(output, { recursive: true });
        await page.screenshot({ path: path.join(output, `glide-orientation-${mode || 'missing'}-${axis}-${width}${reduced ? '-reduced' : ''}.png`) });
    }
    await page.close();
    return { ...result, fieldAngle, frameAngle, visualAngle: fieldAngle + frameAngle };
}

(async () => {
    const browser = await chromium.launch({ channel: 'chrome' });
    try {
        for (const width of [1920, 1440, 1024]) {
            const missing = await measure(browser, width, null);
            const current = await measure(browser, width, 'current');
            const mirrored = await measure(browser, width, 'mirrored');
            const upright = await measure(browser, width, 'upright');
            assert.ok(Math.abs(missing.fieldAngle + 20) < 0.1, `missing setting keeps -20deg at ${width}px`);
            assert.ok(Math.abs(current.fieldAngle + 20) < 0.1, `current keeps -20deg at ${width}px`);
            assert.ok(Math.abs(mirrored.fieldAngle - 20) < 0.1, `mirrored uses +20deg at ${width}px`);
            assert.ok(Math.abs(upright.fieldAngle + 20) < 0.1, `upright wall keeps -20deg at ${width}px`);
            assert.ok(Math.abs(upright.visualAngle) < 0.1, `upright photo is level at ${width}px`);
            assert.equal(missing.counter, '0deg');
            console.log(`PASS ${width}px: default/current -20deg, mirrored +20deg, upright photo 0deg`);
        }
        const diagonal = await measure(browser, 1440, 'upright', 'diagonal_down');
        assert.ok(Math.abs(diagonal.fieldAngle - 25) < 0.1, 'diagonal wall keeps its 45deg axis plus -20deg lean');
        assert.ok(Math.abs(diagonal.visualAngle) < 0.1, 'diagonal upright mode counters the complete 25deg field angle');
        const reduced = await measure(browser, 1440, 'upright', 'vertical', true);
        assert.equal(reduced.before, reduced.after, 'reduced motion suppresses wheel movement');
        assert.ok(Math.abs(reduced.visualAngle) < 0.1, 'reduced motion preserves the static upright orientation');
        console.log('PASS diagonal and prefers-reduced-motion coverage');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
// ===== SNAPSMACK EOF =====
