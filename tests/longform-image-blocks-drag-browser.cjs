/**
 * Does the longform editor's drag handle actually resize an image? (SS-1610)
 *
 * WHY THIS EXISTS. SS-1610 says you cannot drag-resize an image anywhere in
 * SNAPSMACK and that the CMS post editor has no handles. The source says
 * otherwise: assets/js/smack-longform-image-blocks.js draws a green bar on each
 * image block with title "Drag to resize", captures the pointer, and writes the
 * new width back into the shortcode. Both cannot be right.
 *
 * Reading the file cannot settle it, and neither can a test that asserts the
 * handle exists -- that is the mistake this repo keeps paying for. So this puts
 * a real pointer on the real handle in a real browser and reads the textarea
 * afterwards. It needs no database: the panel is driven entirely by the
 * textarea's text, and the one network call it makes (smack-gallery.php, to
 * resolve gallery thumbnails) is answered by this harness.
 *
 * WHAT THIS FOUND, by doing that rather than reading:
 *
 *   SS-1625. The handle was at right:-8px inside a box that clips with
 *   overflow:hidden, so half of its 16px was cut away and its VISIBLE CENTRE
 *   sat exactly on the clip edge. A pointer there produced no pointerdown on
 *   the handle at all and the width never moved; 4px further in it worked
 *   perfectly. The control existed, worked, and could not be hit where it
 *   looked like it should be -- which is the most likely reason SS-1610 was
 *   filed saying the CMS has no handles. Fixed to right:0, and the check below
 *   grabs the CENTRE on purpose so the clipped version cannot pass again.
 *
 *   SS-1626. Each card remembered where its shortcode started and ended. A
 *   rewrite that changes length ([img:g7|40] comes back twelve characters
 *   longer) made those offsets wrong, and the list only redraws 120ms later,
 *   so a second change inside that window cut at the old offsets and left
 *   "l|center|45]" in the body as prose. Offsets are re-derived at write time
 *   by ordinal now.
 *
 * ALSO PINNED:
 *   - the width that lands is the width the preview showed, not a guess;
 *   - the drag snaps to 5% -- a stop interval nobody agreed, so the PM's
 *     snap-to-stops ruling on SS-1610 applies to a decision already in code;
 *   - size, align and the gallery prefix survive the rewrite;
 *   - a DECORATIVE image gets no block at all, so SS-1356's one adjustable
 *     property has no control in the editor;
 *   - a library asset (no g prefix) renders a placeholder, not a photograph,
 *     so its handle drags a grey box.
 *
 * Run: node tests/longform-image-blocks-drag-browser.cjs
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
'use strict';

const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..');

// Playwright lives under tools/browser-tests, which Codex owns; runtime.cjs is
// the seam he published for exactly this. In a worktree without its own
// install, fall back to the main checkout's copy rather than reaching into any
// node_modules by guesswork.
function resolveChromium() {
    try {
        return require(path.join(ROOT, 'tools', 'browser-tests', 'runtime.cjs')).chromium;
    } catch (err) {
        const shared = 'C:/dev/snapsmack/tools/browser-tests/node_modules/@playwright/test';
        if (!fs.existsSync(shared)) { throw err; }
        return require(shared).chromium;
    }
}

let fails = 0;
let ran = 0;
function ok(cond, msg) {
    ran++;
    if (cond) { console.log('  ok    ' + msg); return; }
    fails++;
    console.error('  FAIL  ' + msg);
}

const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css',
                '.png': 'image/png' };

// A 1x1 PNG, so the block renders a real <img> that really loads.
const PIXEL = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==',
    'base64');

const server = http.createServer((req, res) => {
    const url = req.url.split('?')[0];

    // The script resolves gallery thumbnails through this endpoint. Answer it
    // here so the harness needs no database and no install.
    if (url === '/smack-gallery.php') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ images: [
            { id: 7, img_title: 'Harbour', img_file: 'pixel.png' },
            { id: 8, img_title: 'Back fence', img_file: 'pixel.png' },
        ] }));
        return;
    }
    if (url === '/pixel.png') {
        res.writeHead(200, { 'Content-Type': 'image/png' });
        res.end(PIXEL);
        return;
    }

    let rel = decodeURIComponent(url);
    if (rel === '/') { rel = '/tests/fixtures/longform-image-blocks-harness.html'; }
    const file = path.join(ROOT, rel);
    if (!file.startsWith(ROOT)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (err, buf) => {
        if (err) { res.writeHead(404).end('not found: ' + rel); return; }
        res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' });
        res.end(buf);
    });
});

(async () => {
    const chromium = resolveChromium();
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = 'http://127.0.0.1:' + server.address().port;

    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1200, height: 900 } });
    const consoleErrors = [];
    page.on('pageerror', (e) => consoleErrors.push(String(e)));

    async function load(body) {
        await page.goto(base + '/', { waitUntil: 'load' });
        await page.evaluate((text) => {
            const ta = document.getElementById('long-content');
            ta.value = text;
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        }, body);
        // The panel re-renders on a 120ms debounce, then may wait on the fetch.
        await page.waitForTimeout(500);
    }

    console.log('Longform image blocks: does the drag handle resize anything?\n');

    // ── 1. A gallery image, dragged narrower ────────────────────────────────
    await load('Before.\n\n[img:g7|full|center|100]\n\nAfter.');

    const cards = await page.locator('#long-image-block-list article').count();
    ok(cards === 1, 'one image block is drawn for one shortcode');

    const handle = page.locator('[data-handle]').first();
    ok(await handle.count() === 1, 'the block carries a drag handle');
    ok((await handle.getAttribute('title')) === 'Drag to resize',
        'and it says what it is for');

    const img = page.locator('#long-image-block-list article img').first();
    ok(await img.count() === 1, 'the block shows a real image, not a placeholder');

    const stage = await page.locator('[data-stage]').first().boundingBox();
    const box = await handle.boundingBox();

    // SS-1625: the whole handle must be inside the box that clips it. The old
    // right:-8px put half of it outside, and that half was invisible AND the
    // remaining half's centre was unhittable.
    ok(box.x + box.width <= stage.x + stage.width + 0.5,
        'the handle is INSIDE the clipping box (handle right '
        + Math.round(box.x + box.width) + ', stage right '
        + Math.round(stage.x + stage.width) + ')');

    // Grab it in the MIDDLE. This is the check the clipped version failed.
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    // Drag to 60% of the stage width. 60 is on the 5% grid, so a correct
    // implementation lands exactly there and an off-by-a-step one does not.
    await page.mouse.move(stage.x + stage.width * 0.60, box.y + box.height / 2, { steps: 12 });
    const shown = await page.locator('[data-output]').first().textContent();
    await page.mouse.up();
    await page.waitForTimeout(150);

    const after = await page.locator('#long-content').inputValue();
    const widthNow = /\[img:g7\|full\|center\|(\d+)\]/.exec(after);

    ok(widthNow !== null, 'THE SHORTCODE WAS REWRITTEN: ' + JSON.stringify(after.trim()));
    if (widthNow) {
        const written = parseInt(widthNow[1], 10);
        ok(written < 100, 'dragging left made it NARROWER (' + written + '%)');
        ok(written === 60, 'it landed on 60%, the width under the pointer');
        ok(shown === written + '%',
            'the width written is the width the preview showed (' + shown + ')');
        ok(written % 5 === 0, 'and it snapped to the 5% grid already in the code');
    }
    ok(after.startsWith('Before.'), 'the prose either side is untouched');
    ok(after.trim().endsWith('After.'), 'including the text after it');

    // ── 2. The slider writes too, and keeps size/align/prefix ───────────────
    // ONE change event. Playwright's fill() already fires change, so the first
    // version of this fired two and the second rewrite cut at stale offsets --
    // which is how SS-1626 turned up at all. Driven deliberately now.
    async function slide(value) {
        await page.evaluate((v) => {
            const range = document.querySelector('[data-width]');
            range.value = String(v);
            range.dispatchEvent(new Event('change', { bubbles: true }));
        }, value);
        await page.waitForTimeout(200);
    }

    await load('[img:g8|wall|right|80]');
    await slide(35);
    const slid = await page.locator('#long-content').inputValue();
    ok(slid.trim() === '[img:g8|wall|right|35]',
        'the slider writes the width and keeps size, align and the g prefix: '
        + JSON.stringify(slid.trim()));

    // ── 3. A BARE width is normalised to the full positional form ───────────
    await load('[img:g7|40]');
    await slide(45);
    const normalised = await page.locator('#long-content').inputValue();
    ok(normalised.trim() === '[img:g7|full|center|45]',
        'a bare width comes back as the full form, defaults spelled out: '
        + JSON.stringify(normalised.trim()));

    // ── 3b. SS-1626: TWO changes inside the 120ms redraw window ─────────────
    // The rewrite here is twelve characters longer than the original, which is
    // the condition that made the old offsets wrong. Measured before the fix:
    // "[img:g7|full|center|50]l|center|45]" -- the orphaned tail is now prose
    // in the author's post.
    await load('[img:g7|40]');
    await page.evaluate(() => {
        const range = document.querySelector('[data-width]');
        range.value = '45';
        range.dispatchEvent(new Event('change', { bubbles: true }));
        range.value = '50';
        range.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(400);
    const twice = await page.locator('#long-content').inputValue();
    ok(twice.trim() === '[img:g7|full|center|50]',
        'TWO fast width changes leave ONE clean shortcode: ' + JSON.stringify(twice.trim()));
    ok(!/\]\S|\|center\|\d+\]\S/.test(twice.trim()) && twice.trim().split(']').length === 2,
        'and no fragment of the old shortcode is left behind as text');

    // ── 3c. THE SECOND IMAGE IS THE SECOND IMAGE ────────────────────────────
    // Re-deriving offsets by ordinal only works if the ordinal is real. With
    // every card numbered 0, editing the second image rewrites the first --
    // which the rest of this file could not see, because every other case has
    // one editable block in it. The mutant that pinned ordinal to 0 survived
    // until this check existed.
    await load('[img:g7|full|center|100]\n\n[img:g8|wall|right|80]');
    ok(await page.locator('#long-image-block-list article').count() === 2,
        'two images give two blocks');
    await page.evaluate(() => {
        const ranges = document.querySelectorAll('[data-width]');
        ranges[1].value = '40';
        ranges[1].dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(300);
    const pair = (await page.locator('#long-content').inputValue()).trim();
    ok(pair === '[img:g7|full|center|100]\n\n[img:g8|wall|right|40]',
        'editing the SECOND image changes only the second: ' + JSON.stringify(pair));

    // And a decorative shortcode between them must not shift the numbering,
    // since it is skipped by the parse that numbers the cards.
    await load('[img:g7|full|center|100]\n\n[img:g7|full|center|50|decorative]\n\n[img:g8|wall|right|80]');
    await page.evaluate(() => {
        const ranges = document.querySelectorAll('[data-width]');
        ranges[1].value = '30';
        ranges[1].dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(300);
    const withDeco = (await page.locator('#long-content').inputValue()).trim();
    ok(withDeco.includes('[img:g8|wall|right|30]')
        && withDeco.includes('[img:g7|full|center|100]')
        && withDeco.includes('|decorative]'),
        'a decorative image between them shifts nothing: ' + JSON.stringify(withDeco));

    // The same, where the rewrite does NOT change length. This passed even
    // before the fix, which is why the fault was intermittent -- so it is kept
    // as the control rather than as evidence.
    await load('[img:g7|wall|right|80]');
    await page.evaluate(() => {
        const range = document.querySelector('[data-width]');
        range.value = '75';
        range.dispatchEvent(new Event('change', { bubbles: true }));
        range.value = '70';
        range.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(400);
    ok((await page.locator('#long-content').inputValue()).trim() === '[img:g7|wall|right|70]',
        'control: two fast changes at equal length were always safe');

    // ── 4. A DECORATIVE image gets no block ─────────────────────────────────
    // SS-1356 added decorative so a signature could have its WIDTH reduced and
    // nothing else. It is the one image class with no width control here.
    await load('[img:g7|full|center|100|decorative]');
    ok(await page.locator('#long-image-block-list article').count() === 0,
        'a DECORATIVE image gets no block, so it has no width control at all');
    ok(await page.locator('#long-image-blocks').evaluate((el) => el.style.display) === 'none',
        'and the panel stays hidden, as though the body had no images');

    // Mixed: the decorative one is skipped and the plain one still works.
    await load('[img:g7|full|center|100|decorative]\n\n[img:g8|full|center|90]');
    ok(await page.locator('#long-image-block-list article').count() === 1,
        'in a mixed body only the non-decorative image is offered');

    // ── 5. A LIBRARY ASSET drags a grey box ─────────────────────────────────
    // No g prefix, so the script never resolves a thumbnail for it.
    await load('[img:5|full|center|100]');
    ok(await page.locator('#long-image-block-list article').count() === 1,
        'a library asset does get a block');
    ok(await page.locator('#long-image-block-list article img').count() === 0,
        'but NO image: the handle drags a placeholder, not a photograph');

    ok(consoleErrors.length === 0,
        'no page errors during any of it' + (consoleErrors.length ? ': ' + consoleErrors[0] : ''));

    await browser.close();
    await new Promise((resolve) => server.close(resolve));

    console.log('\n' + ran + ' checks, ' + (fails === 0 ? 'all passed' : fails + ' FAILED'));
    process.exit(fails === 0 ? 0 : 1);
})().catch((err) => {
    console.error('HARNESS ERROR: ' + (err && err.stack ? err.stack : err));
    try { server.close(); } catch (e) { /* already down */ }
    process.exit(2);
});

// ===== SNAPSMACK EOF =====
