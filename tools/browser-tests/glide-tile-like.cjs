#!/usr/bin/env node
// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const seam = process.env.SNAP_BROWSER_SEAM_ROOT || __dirname;
const { chromium } = require(path.join(seam, 'runtime.cjs'));

const root = path.resolve(__dirname, '..', '..');
const output = process.env.SNAPSMACK_BROWSER_OUTPUT || path.join(root, 'tmp', 'glide-tile-like');
const glideCss = fs.readFileSync(path.join(root, 'skins', 'glide', 'style.css'), 'utf8');
const likeCss = fs.readFileSync(path.join(root, 'assets', 'css', 'ss-tile-like.css'), 'utf8');
const likeJs = fs.readFileSync(path.join(root, 'assets', 'js', 'ss-engine-tile-like.js'), 'utf8');
const glideJs = fs.readFileSync(path.join(root, 'assets', 'js', 'ss-engine-glide.js'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'skins', 'glide', 'layout.php'), 'utf8');
const photo = 'data:image/svg+xml,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="#17334a"/><circle cx="600" cy="400" r="220" fill="#d88b3f"/></svg>'
);

assert.equal((layout.match(/class="glide-tile-frame ss-tile-like-host"/g) || []).length, 2,
    'both GLIDE conveyor data paths wrap the photo link and like control in one positioned host');
assert.equal((layout.match(/snap_render_component\('tile-like', \['item' => \$item\]\)/g) || []).length, 2,
    'both GLIDE conveyor data paths print the prepared tile-like component');
assert.equal((layout.match(/<\/a><\?php echo snap_render_html\(snap_render_component\('tile-like', \['item' => \$item\]\)\); \?><\/div>/g) || []).length, 2,
    'both GLIDE hearts are siblings after the photo link, never buttons nested inside links');

function populatedWallHtml() {
    const fixture = `<?php
declare(strict_types=1);
define('BASE_URL', 'https://fixture.test/');
define('SNAPSMACK_VERSION', 'Alpha 0.0.0D');
define('SNAPSMACK_VERSION_SHORT', '0.0.0D');
define('SNAPSMACK_SKIN_RENDER', true);
$root = ${JSON.stringify(root.replace(/\\/g, '/'))};
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/public-controller.php';
require_once $root . '/core/tile-like-control.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
@$pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-11-16 00:00:00', 0);
@$pdo->sqliteCreateFunction('RAND', static fn(): float => 0.5, 0);
$pdo->exec("CREATE TABLE snap_pages (
    id INTEGER PRIMARY KEY, slug TEXT, title TEXT, content TEXT, image_asset TEXT,
    image_size TEXT, image_align TEXT, image_shadow INTEGER DEFAULT 0,
    menu_order INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1, created_at TEXT)");
$pdo->exec("CREATE TABLE snap_images (
    id INTEGER PRIMARY KEY, img_title TEXT, img_slug TEXT, img_alt TEXT, img_file TEXT,
    img_width INTEGER, img_height INTEGER, img_thumb_square TEXT, img_thumb_aspect TEXT,
    allow_comments INTEGER DEFAULT 1, img_status TEXT, img_date TEXT)");
$pdo->exec("CREATE TABLE snap_comments (
    id INTEGER PRIMARY KEY, img_id INTEGER, post_id INTEGER, comment_author TEXT,
    comment_url TEXT, comment_text TEXT, comment_date TEXT, ap_source TEXT,
    ap_actor_url TEXT, is_approved INTEGER DEFAULT 1, is_spam INTEGER DEFAULT 0)");
$insert = $pdo->prepare("INSERT INTO snap_images
    (id,img_title,img_slug,img_alt,img_file,img_width,img_height,img_thumb_square,img_thumb_aspect,allow_comments,img_status,img_date)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
foreach ([41, 42, 43, 44, 45] as $id) {
    $insert->execute([$id, 'Photograph ' . $id, 'photo-' . $id, 'Photograph ' . $id,
        'https://fixture.test/photo-' . $id . '.jpg', 1200, 800,
        'https://fixture.test/photo-' . $id . '-square.jpg',
        'https://fixture.test/photo-' . $id . '-aspect.jpg', 1, 'published', '2026-11-15 00:00:00']);
}

$repo = new SnapPublicRepository($pdo);
$response = snapsmack_public_controller($repo, ['route' => 'landing'], [
    'active_skin' => 'glide', 'skin_has_landing' => true,
    'homepage_mode' => 'archive', 'site_mode' => 'photoblog', 'posts_per_page' => 5,
]);
if (empty($response['rows'][0])) throw new RuntimeException('real controller did not build a populated GLIDE row');
$prepared = [];
foreach (snap_tile_like_lists($response) as $list) {
    foreach ($list as $item) {
        $id = (int)($item['id'] ?? 0);
        if ($id > 0) $prepared[$id] = snap_tile_like_html($id, $id === 41);
    }
}
$response = snap_tile_like_annotate($response, $prepared);
$view = snapsmack_build_skin_view($response, [
    'site_name' => 'GLIDE fixture', 'site_url' => 'https://fixture.test/',
    'base_url' => 'https://fixture.test/', 'language' => 'en', 'direction' => 'ltr',
    'skin_slug' => 'glide', 'base_style_url' => '', 'skin_style_url' => '',
    'skin_custom_style' => '', 'skin_presentation' => ['style' => '', 'options' => []],
    'registered_assets' => [], 'owner_custom_code' => '', 'footer' => ['slots' => []],
]);
snapsmack_render_strict_skin_template($root . '/skins/glide', 'layout.php', $view);
`;
    const file = path.join(os.tmpdir(), `snapsmack-glide-populated-${process.pid}.php`);
    fs.writeFileSync(file, fixture);
    try {
        return execFileSync('php', [file], { encoding: 'utf8' });
    } finally {
        fs.rmSync(file, { force: true });
    }
}

function documentHtml() {
    return `<!doctype html><html><head><meta charset="utf-8"><base href="https://fixture.test/archive/"><meta name="csrf-token" content="fixture-token">
    <style>${glideCss}</style><style>${likeCss}</style></head><body class="glide-v2">
    <main class="glide-page"><div class="glide-viewport"><div class="glide-field">
      <div class="glide-row"><div class="glide-track">
        <div class="glide-tile-frame ss-tile-like-host" style="--glide-aspect:3/2">
          <a class="glide-tile" href="#solo" aria-label="View photograph"><img src="${photo}" alt="Fixture photograph"></a>
          <button type="button" class="ss-tile-like" data-tile-like="42" data-liked="0" aria-pressed="false" aria-label="Like this photograph" title="Like this photograph">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
          </button>
        </div>
        <div class="glide-tile-frame ss-tile-like-host" style="--glide-aspect:3/2">
          <a class="glide-tile" href="#liked" aria-label="View liked photograph"><img src="${photo}" alt="Liked fixture photograph"></a>
          <button type="button" class="ss-tile-like is-liked" data-tile-like="43" data-liked="1" aria-pressed="true" aria-label="Unlike this photograph" title="Unlike this photograph">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
          </button>
        </div>
      </div></div>
    </div></div></main></body></html>`;
}

function tileFrame(id) {
    return `<div class="glide-tile-frame ss-tile-like-host" style="--glide-aspect:3/2">
      <a class="glide-tile" href="https://fixture.test/photo-${id}" aria-label="Photograph ${id}"><img src="${photo}" alt="Photograph ${id}"></a>
      <button type="button" class="ss-tile-like" data-tile-like="${id}" data-liked="0" aria-pressed="false" aria-label="Like this photograph" title="Like this photograph">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
      </button>
    </div>`;
}

function redistributionHtml() {
    return `<!doctype html><html><head><meta charset="utf-8"><base href="https://fixture.test/archive/"><meta name="csrf-token" content="fixture-token">
    <style>${glideCss}</style><style>${likeCss}</style></head><body class="glide-v2">
    <main class="glide-page" data-glide-wall data-wall-inner-coverage>
      <div class="glide-viewport"><div class="glide-field">
        ${[10, 20, 30, 40, 50].map(id => `<div class="glide-row" data-glide-row><div class="glide-track">${tileFrame(id)}</div></div>`).join('')}
      </div></div>
    </main></body></html>`;
}

async function installBehaviour(page) {
    await page.evaluate(() => {
        window.likeRequests = [];
        window.dragStarts = 0;
        document.querySelectorAll('.glide-row').forEach(row => {
            row.addEventListener('pointerdown', () => { window.dragStarts += 1; });
        });
        window.fetch = (url, options) => {
            window.likeRequests.push({ url, resolved: new URL(url, document.baseURI).href, method: options && options.method });
            return Promise.resolve({ json: () => Promise.resolve({ liked: true }) });
        };
    });
    await page.addScriptTag({ content: likeJs });
}

(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ headless: true });
    try {
        const pipelineHtml = populatedWallHtml();
        assert.match(pipelineHtml, /class="glide-row" data-glide-row>[\s\S]*data-tile-like="41"/,
            'a populated wall built by the real controller reaches the GLIDE layout with a prepared heart');
        const pipeline = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        await pipeline.route('https://fixture.test/*.jpg', route => route.fulfill({
            contentType: 'image/svg+xml',
            body: '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="#17334a"/><circle cx="600" cy="400" r="220" fill="#d88b3f"/></svg>',
        }));
        await pipeline.setContent(pipelineHtml, { waitUntil: 'load' });
        await pipeline.addStyleTag({ content: glideCss });
        await pipeline.addStyleTag({ content: likeCss });
        const populatedHeart = pipeline.locator('.glide-row .ss-tile-like[data-tile-like="41"]');
        assert.equal(await populatedHeart.count(), 1,
            'the rendered populated controller row contains exactly one prepared heart for photograph 41');
        assert.equal(await populatedHeart.evaluate(el =>
            el.parentElement.classList.contains('glide-tile-frame')
            && el.previousElementSibling.classList.contains('glide-tile')),
        true, 'the populated controller row renders the heart beside the photo link inside its frame');
        await populatedHeart.scrollIntoViewIfNeeded();
        await pipeline.screenshot({ path: path.join(output, 'glide-tile-like-populated-controller-1440.png'), fullPage: true });
        console.log('PASS populated controller wall 1440: prepared row reaches the real GLIDE layout with its heart');
        await pipeline.close();

        for (const width of [1920, 1440, 1024]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            await page.setContent(documentHtml(), { waitUntil: 'load' });
            await installBehaviour(page);

            const heart = page.locator('.ss-tile-like').first();
            const tile = page.locator('.glide-tile').first();
            assert.equal(await heart.evaluate(el => getComputedStyle(el).opacity), '0',
                `${width}: an unliked heart is quiet before hover`);
            assert.equal(await page.locator('.ss-tile-like.is-liked').evaluate(el => getComputedStyle(el).opacity), '1',
                `${width}: a liked heart remains visibly filled`);

            await tile.hover();
            await page.waitForFunction(() => Number.parseFloat(getComputedStyle(document.querySelector('.ss-tile-like')).opacity) > 0.99);
            assert.ok(await heart.evaluate(el => Number.parseFloat(getComputedStyle(el).opacity)) > 0.99,
                `${width}: hovering the photograph reveals the heart`);
            const geometry = await page.evaluate(() => {
                const heart = document.querySelector('.ss-tile-like');
                const heartStyle = getComputedStyle(heart);
                return {
                    left: heartStyle.left,
                    bottom: heartStyle.bottom,
                    width: heartStyle.width,
                    height: heartStyle.height,
                    text: heart.textContent.trim(),
                    overflow: document.documentElement.scrollWidth - innerWidth,
                };
            });
            assert.equal(geometry.left, '10px', `${width}: heart is 10px from the photograph's left edge`);
            assert.equal(geometry.bottom, '10px', `${width}: heart is 10px from the photograph's bottom edge`);
            assert.equal(geometry.width, '44px', `${width}: heart has a 44px target width`);
            assert.equal(geometry.height, '44px', `${width}: heart has a 44px target height`);
            assert.equal(geometry.text, '', `${width}: conveyor heart has no count`);
            assert.ok(geometry.overflow <= 0, `${width}: heart introduces no horizontal overflow`);
            await page.screenshot({ path: path.join(output, `glide-tile-like-hover-${width}.png`), fullPage: true });

            await heart.dispatchEvent('pointerdown', { pointerId: 7, pointerType: 'mouse', button: 0 });
            assert.equal(await page.evaluate(() => window.dragStarts), 0,
                `${width}: pressing the heart does not begin a conveyor drag`);
            await heart.dispatchEvent('click');
            await page.waitForFunction(() => window.likeRequests.length === 1);
            assert.equal(await page.evaluate(() => location.hash), '',
                `${width}: clicking the heart does not open the photograph`);
            assert.deepEqual(await page.evaluate(() => window.likeRequests[0]),
                { url: '/process-like.php', resolved: 'https://fixture.test/process-like.php', method: 'POST' },
                `${width}: heart uses the root endpoint even from a deeper page URL`);
            assert.equal(await heart.getAttribute('aria-pressed'), 'true', `${width}: successful like updates pressed state`);

            await page.screenshot({ path: path.join(output, `glide-tile-like-liked-${width}.png`), fullPage: true });
            console.log(`PASS ${width}: GLIDE heart is bottom-left, hover-revealed, count-free, and interaction-safe`);
            await page.close();
        }

        const touch = await browser.newPage({ viewport: { width: 1024, height: 900 }, hasTouch: true, isMobile: true });
        await touch.setContent(documentHtml(), { waitUntil: 'load' });
        assert.equal(await touch.evaluate(() => matchMedia('(hover: none)').matches), true,
            'touch fixture actually exercises the no-hover rule');
        assert.equal(await touch.locator('.ss-tile-like').first().evaluate(el => getComputedStyle(el).opacity), '0.72',
            'touch: unliked heart is always visible at quiet opacity');
        await touch.screenshot({ path: path.join(output, 'glide-tile-like-touch-1024.png'), fullPage: true });
        console.log('PASS touch 1024: GLIDE heart remains discoverable without hover');
        await touch.close();

        const redistributed = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        await redistributed.setContent(redistributionHtml(), { waitUntil: 'load' });
        await installBehaviour(redistributed);
        await redistributed.addScriptTag({ content: glideJs });
        const innerRow = redistributed.locator('.glide-row').nth(1);
        const innerOriginals = innerRow.locator('.glide-tile-frame:not([aria-hidden="true"])');
        await assert.doesNotReject(() => innerOriginals.nth(1).waitFor(),
            'the GLIDE engine redistributes a complete framed tile into the inner row');
        assert.equal(await innerOriginals.count(), 2,
            'redistribution adds the outer photograph as one complete frame');
        const scenery = innerRow.locator('.glide-tile-frame[aria-hidden="true"]');
        assert.ok(await scenery.count() > 0, 'the fixture exercises GLIDE scenery duplication after redistribution');
        assert.equal(await scenery.evaluateAll(frames => frames.every(frame =>
            Array.from(frame.querySelectorAll('a[href], button, input, select, textarea, [tabindex]'))
                .every(control => control.tabIndex === -1))), true,
        'aria-hidden scenery leaves no nested photo link or heart in the keyboard tab order');
        const redistributedHeart = innerOriginals.nth(1).locator('.ss-tile-like');
        assert.equal(await redistributedHeart.count(), 1,
            'the redistributed photograph keeps exactly one sibling heart');
        assert.equal(await redistributedHeart.evaluate(el => el.parentElement.classList.contains('glide-tile-frame')), true,
            'the redistributed heart remains inside the positioned frame and outside the photo link');
        await redistributedHeart.dispatchEvent('pointerdown', { pointerId: 9, pointerType: 'mouse', button: 0 });
        assert.equal(await redistributed.evaluate(() => window.dragStarts), 0,
            'pressing a redistributed heart does not begin a conveyor drag');
        await redistributedHeart.dispatchEvent('click');
        await redistributed.waitForFunction(() => window.likeRequests.length === 1);
        assert.deepEqual(await redistributed.evaluate(() => window.likeRequests[0]),
            { url: '/process-like.php', resolved: 'https://fixture.test/process-like.php', method: 'POST' },
            'a redistributed heart records the like through the root endpoint');
        await redistributed.screenshot({ path: path.join(output, 'glide-tile-like-redistributed-1440.png'), fullPage: true });
        console.log('PASS redistribution 1440: cloned inner-row tile keeps one working heart without flinging');
        await redistributed.close();
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

// ===== SNAPSMACK EOF =====
