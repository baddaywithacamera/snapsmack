// SNAPSMACK_EOF_HEADER: end this file with the canonical SNAPSMACK EOF marker.

/**
 * Rendered feature-presence matrix for every skin.
 *
 * A PASS means the control was visible/clickable at all supported widths (and,
 * for keyboard/lightbox, that the declared engine actually responded). A FAIL
 * is measured browser evidence. NOT CHECKED and NOT APPLICABLE are deliberate
 * states; absence is never silently promoted to a pass.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const seam = process.env.SNAP_BROWSER_SEAM_ROOT || path.resolve(__dirname, '../..');
const { chromium } = require(path.join(seam, 'tools/browser-tests/runtime.cjs'));
const root = path.resolve(__dirname, '../..');
const out = process.env.SNAP_FEATURE_FIXTURES || fs.mkdtempSync(path.join(os.tmpdir(), 'snapsmack-feature-presence-'));
const reportPath = process.env.SNAP_FEATURE_REPORT || path.join(out, 'feature-presence-report.json');
const widths = [1920, 1440, 1024];
const features = [
    'consent', 'likes', 'downloads', 'lightbox', 'search', 'navigation',
    'archive-controls', 'comments', 'help', 'telemetry-eligibility', 'keyboard', 'prev-next'
];

function generateFixtures() {
    const skins = fs.readdirSync(path.join(root, 'skins'), { withFileTypes: true })
        .filter(entry => entry.isDirectory()
            && fs.existsSync(path.join(root, 'skins', entry.name, 'layout.php'))
            && fs.existsSync(path.join(root, 'skins', entry.name, 'manifest.json')))
        .map(entry => entry.name).sort();
    const pages = [];
    for (const skin of skins) {
        const result = spawnSync('php', ['tools/browser-tests/feature-presence-fixtures.php', out, skin], {
            cwd: root, encoding: 'utf8'
        });
        if (result.status !== 0) throw new Error(result.stderr || result.stdout || `fixture generation failed for ${skin}`);
        const generated = JSON.parse(fs.readFileSync(path.join(out, 'index.json'), 'utf8')).pages;
        assert.equal(generated.length, 1, `${skin}: expected one isolated fixture`);
        pages.push(generated[0]);
    }
    fs.writeFileSync(path.join(out, 'index.json'), JSON.stringify({ pages }, null, 2));
    console.log(`${pages.length} isolated solo pages written to ${out}`);
}

function declared(page, feature) {
    const scripts = page.require_scripts || [];
    const community = Array.isArray(page.features?.community) ? page.features.community : [];
    const capabilities = page.capabilities || [];
    const carousel = page.site_mode === 'carousel';
    if (feature === 'likes' && carousel) return ['UNVERIFIED', 'carousel likes require the landing-to-modal transport fixture'];
    if (feature === 'likes') return community.includes('likes') || page.community_likes === '1'
        ? ['REQUIRED', 'manifest declares community likes'] : ['NOT APPLICABLE', 'manifest does not declare likes'];
    if (feature === 'comments' && carousel) return ['UNVERIFIED', 'carousel comments require the landing-to-modal transport fixture'];
    if (feature === 'comments') return community.includes('comments') || capabilities.includes('comments') || page.community_comments === '1'
        ? ['REQUIRED', 'manifest declares comments'] : ['NOT APPLICABLE', 'manifest does not declare comments'];
    if (feature === 'lightbox' && carousel) return ['UNVERIFIED', 'carousel lightbox requires the landing-to-modal transport fixture'];
    if (feature === 'lightbox' && page.kind === 'single') return ['UNVERIFIED', 'long-form lightbox requires a story fixture containing inline media'];
    if (feature === 'lightbox') return scripts.includes('smack-lightbox')
        ? ['REQUIRED', 'manifest requires smack-lightbox'] : ['NOT APPLICABLE', 'manifest does not require smack-lightbox'];
    if (feature === 'keyboard' && carousel) return ['UNVERIFIED', 'carousel keyboard navigation requires the landing-to-modal transport fixture'];
    if (feature === 'keyboard') return page.kind === 'photo'
        ? ['REQUIRED', 'core supplies keyboard navigation to every photoblog solo entry']
        : ['NOT APPLICABLE', 'long-form single entry has no image-order keyboard contract'];
    if (feature === 'help') return carousel
        ? ['NOT APPLICABLE', 'carousel mode deliberately omits F1 help']
        : ['REQUIRED', 'core supplies F1 help to every non-carousel public page'];
    if (feature === 'search') return scripts.includes('smack-gram-search')
        ? ['REQUIRED', 'manifest requires smack-gram-search'] : ['UNVERIFIED', 'search may be ordinary navigation; contract not yet classified'];
    if (feature === 'navigation') return ['REQUIRED', 'every public skin needs site navigation'];
    if (feature === 'prev-next' && carousel) return ['UNVERIFIED', 'carousel previous/next requires the landing-to-modal transport fixture'];
    if (feature === 'prev-next') return page.kind === 'photo'
        ? ['REQUIRED', 'photoblog solo entry has previous and next targets'] : ['NOT APPLICABLE', 'long-form single entry has no image-order contract'];
    if (feature === 'archive-controls') return ['REQUIRED', 'every archive is endless scroll and exposes archive controls'];
    if (feature === 'downloads' && carousel) return ['UNVERIFIED', 'carousel download requires the landing-to-modal transport fixture'];
    if (feature === 'downloads') return page.kind === 'photo'
        ? ['REQUIRED', 'runtime supplied a prepared download link for this photoblog entry']
        : ['NOT APPLICABLE', 'long-form single entry has no photo-download contract'];
    if (feature === 'consent') return ['REQUIRED', 'core supplies the consent choice on a fresh public request'];
    if (feature === 'telemetry-eligibility') {
        if (carousel) return ['REQUIRED', 'carousel landing feed is eligible for Scroll Time'];
        if (page.site_mode === 'smacktalk') return ['REQUIRED', 'SMACKTALK story is eligible for Scroll Time'];
        return ['REQUIRED', 'photoblog archive is eligible for Scroll Time'];
    }
    return ['UNVERIFIED', 'classification pending'];
}

async function visibleCount(page, selector) {
    return page.locator(selector).evaluateAll(elements => elements.filter(element => {
        const style = getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        return style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity) > 0
            && rect.width > 0 && rect.height > 0;
    }).length);
}

async function clickFirstVisible(page, selectors) {
    for (const selector of selectors) {
        const candidates = page.locator(selector);
        const count = await candidates.count();
        for (let index = 0; index < count; index++) {
            const candidate = candidates.nth(index);
            if (!await candidate.isVisible()) continue;
            await candidate.click({ force: true });
            await page.waitForTimeout(100);
            return true;
        }
    }
    return false;
}

async function revealCommunity(page) {
    const communitySelector = '.ss-community, .ss-comment-form, .ss-like-btn, .ss-cdock-heart, [data-like]';
    if ((await visibleCount(page, communitySelector)) > 0) return true;
    const controls = [
        '#show-comments',
        '[data-pane="comments"]',
        '[data-ss-target*="community"]',
        '[aria-label*="comment" i]',
        'a[href="#comments"]',
        'button[title*="comment" i]'
    ];
    const clicked = await clickFirstVisible(page, controls);
    return clicked && (await visibleCount(page, communitySelector)) > 0;
}

async function revealSearch(page) {
    const searchSelector = 'input[type="search"], form[role="search"], [data-wall-search], [data-search-dock]';
    if ((await visibleCount(page, searchSelector)) > 0) return true;
    const clicked = await clickFirstVisible(page, [
        '.gsd-toggle',
        '[data-search-toggle]',
        '[aria-label="Search"]',
        '[title="Search"]',
        'a[href="#search"]'
    ]);
    return clicked && (await visibleCount(page, searchSelector)) > 0;
}

async function measureArchive(page) {
    const controls = await visibleCount(page, '.archive-controls, .archive-filter-row, [data-archive-layout]');
    const continuation = await page.locator(
        '[id$="-sentinel"][data-next], [id$="-sentinel"][data-next-url], '
        + '.scroll-wall-sentinel[data-next], [data-has-more="1"][data-next]'
    ).count();
    return { pass: controls > 0 && continuation > 0, controls, continuation };
}

function telemetryFixtureFile(fixture) {
    if (fixture.site_mode === 'carousel') return fixture.landing_file;
    if (fixture.site_mode === 'smacktalk') return fixture.file;
    return fixture.archive_file;
}

async function measure(page, feature) {
    if (feature === 'archive-controls') {
        return (await measureArchive(page)).pass;
    }
    if (feature === 'navigation') {
        return (await visibleCount(page, 'nav[aria-label*="navigation" i] a, nav.navigation a, a[href="/archive"], a[href="/about"], a[href="/blogroll"]')) > 0;
    }
    if (feature === 'comments') {
        await revealCommunity(page);
        return (await visibleCount(page, '.ss-community, .ss-comment-form')) > 0;
    }
    if (feature === 'likes') {
        await revealCommunity(page);
        return (await visibleCount(page, '.ss-like-btn, .ss-cdock-heart, [data-like]')) > 0;
    }
    if (feature === 'prev-next') {
        return (await visibleCount(page, 'a[rel="prev"]')) > 0 && (await visibleCount(page, 'a[rel="next"]')) > 0;
    }
    if (feature === 'downloads') return (await visibleCount(page, 'a[download], a[href*="/download"], .photo-download, .snap-download-btn')) > 0;
    if (feature === 'search') return revealSearch(page);
    if (feature === 'lightbox') {
        const image = page.locator('img[data-lightbox-src], img.post-image, img.pg-post-image').first();
        if (!await image.count() || !await image.isVisible()) return false;
        await image.click({ force: true });
        await page.waitForTimeout(120);
        const opened = await visibleCount(page, '#ss-lightbox-overlay, dialog[open], .lightbox.is-open, .wall-viewer[open]');
        await page.keyboard.press('Escape');
        return opened > 0;
    }
    if (feature === 'keyboard') {
        if (!(await page.locator('a[rel="prev"], a[rel="next"]').count())) return false;
        const before = page.url();
        await page.keyboard.press('ArrowLeft');
        await page.waitForTimeout(100);
        return page.url() !== before;
    }
    if (feature === 'help') {
        await page.keyboard.press('F1');
        await page.waitForTimeout(80);
        const shown = await visibleCount(page, '#snap-public-help-backdrop, dialog[open][aria-label*="help" i], [role="dialog"][data-help]');
        await page.keyboard.press('Escape');
        return shown > 0;
    }
    if (feature === 'consent') {
        return (await visibleCount(page, '#snap-consent-banner #snap-consent-accept')) > 0
            && (await visibleCount(page, '#snap-consent-banner #snap-consent-decline')) > 0;
    }
    if (feature === 'telemetry-eligibility') {
        return (await page.locator(
            '#ss-scrolltime[data-hit]:not([data-hit=""])[data-token]:not([data-token=""])[data-endpoint]:not([data-endpoint=""])'
        ).count()) === 1;
    }
    return null;
}

async function negativeControl(browser, fixture, feature, selector) {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    try {
        await page.goto(pathToFileURL(path.join(out, fixture.file)).href, { waitUntil: 'load' });
        if (feature !== 'consent' && await page.locator('#snap-consent-decline').count()) {
            await page.locator('#snap-consent-decline').click({ force: true });
            await page.waitForTimeout(400);
        }
        const before = await measure(page, feature);
        assert.equal(before, true, `${fixture.skin}: ${feature} needs one passing fixture for its negative control`);
        await page.evaluate(value => document.querySelectorAll(value).forEach(element => element.remove()), selector);
        const after = await measure(page, feature);
        assert.equal(after, false, `${fixture.skin}: ${feature} detector did not fail after control removal`);
        return { feature, skin: fixture.skin, status: 'PASS', mutation: `removed ${selector}` };
    } finally {
        await page.close();
    }
}

(async () => {
    generateFixtures();
    const fixtureIndex = JSON.parse(fs.readFileSync(path.join(out, 'index.json'), 'utf8')).pages;
    assert.equal(fixtureIndex.length, 30, 'the matrix must cover all 30 skins');
    const browser = await chromium.launch({ channel: 'chrome' });
    const matrix = [];
    try {
        for (const fixture of fixtureIndex) {
            const cells = {};
            for (const feature of features) {
                const [requirement, reason] = declared(fixture, feature);
                cells[feature] = { requirement, reason, status: requirement === 'NOT APPLICABLE' ? 'NOT APPLICABLE' : 'NOT CHECKED', widths: {} };
            }
            for (const width of widths) {
                const page = await browser.newPage({ viewport: { width, height: 1000 } });
                page.setDefaultTimeout(2500);
                await page.goto(pathToFileURL(path.join(out, fixture.file)).href, { waitUntil: 'load' });
                for (const feature of features) {
                    const cell = cells[feature];
                    if (cell.requirement !== 'REQUIRED') continue;
                    if (feature === 'archive-controls' || feature === 'telemetry-eligibility') continue;
                    const value = await measure(page, feature);
                    if (value === null) continue;
                    cell.widths[String(width)] = value ? 'PASS' : 'FAIL';
                    if (feature === 'consent' && value) {
                        await page.locator('#snap-consent-decline').click({ force: true });
                        await page.waitForTimeout(400);
                    }
                    if (feature === 'keyboard' && page.url() !== pathToFileURL(path.join(out, fixture.file)).href) {
                        await page.goto(pathToFileURL(path.join(out, fixture.file)).href, { waitUntil: 'load' });
                    }
                }
                await page.close();

                const archivePage = await browser.newPage({ viewport: { width, height: 1000 } });
                archivePage.setDefaultTimeout(2500);
                await archivePage.goto(pathToFileURL(path.join(out, fixture.archive_file)).href, { waitUntil: 'load' });
                const archiveValue = await measureArchive(archivePage);
                cells['archive-controls'].widths[String(width)] = archiveValue.pass ? 'PASS' : 'FAIL';
                cells['archive-controls'].observations ??= {};
                cells['archive-controls'].observations[String(width)] = {
                    visible_control_count: archiveValue.controls,
                    continuation_marker_count: archiveValue.continuation
                };
                await archivePage.close();

                const telemetryFile = telemetryFixtureFile(fixture);
                assert.ok(telemetryFile, `${fixture.skin}: missing eligible telemetry fixture`);
                const telemetryPage = await browser.newPage({ viewport: { width, height: 1000 } });
                telemetryPage.setDefaultTimeout(2500);
                await telemetryPage.goto(pathToFileURL(path.join(out, telemetryFile)).href, { waitUntil: 'load' });
                const telemetryValue = await measure(telemetryPage, 'telemetry-eligibility');
                cells['telemetry-eligibility'].widths[String(width)] = telemetryValue ? 'PASS' : 'FAIL';
                await telemetryPage.close();
            }
            for (const cell of Object.values(cells)) {
                const values = Object.values(cell.widths);
                if (values.length) cell.status = values.every(value => value === 'PASS') ? 'PASS' : 'FAIL';
            }
            matrix.push({ skin: fixture.skin, version: fixture.version, kind: fixture.kind, features: cells });
        }

        const controls = [];
        const controlSpecs = {
            navigation: 'nav[aria-label*="navigation" i],nav.navigation,a[href="/archive"],a[href="/about"],a[href="/blogroll"]',
            comments: '.ss-community,.ss-comment-form',
            likes: '.ss-like-btn,.ss-cdock-heart,[data-like]',
            downloads: 'a[download],a[href*="/download"],.photo-download,.snap-download-btn',
            'prev-next': 'a[rel="prev"],a[rel="next"]',
            lightbox: 'img[data-lightbox-src],img.post-image,img.pg-post-image',
            consent: '#snap-consent-banner',
            'archive-controls': '.archive-controls,.archive-filter-row,[data-archive-layout]',
            'telemetry-eligibility': '#ss-scrolltime'
        };
        for (const [feature, selector] of Object.entries(controlSpecs)) {
            const passing = matrix.find(row => row.features[feature].status === 'PASS');
            assert.ok(passing, `no passing ${feature} fixture available for negative control`);
            const fixture = fixtureIndex.find(candidate => candidate.skin === passing.skin);
            let controlFixture = fixture;
            if (feature === 'archive-controls') {
                controlFixture = { ...fixture, file: fixture.archive_file };
            } else if (feature === 'telemetry-eligibility') {
                controlFixture = { ...fixture, file: telemetryFixtureFile(fixture) };
            }
            controls.push(await negativeControl(browser, controlFixture, feature, selector));
        }

        const summary = {};
        for (const feature of features) {
            summary[feature] = { PASS: 0, FAIL: 0, 'NOT CHECKED': 0, 'NOT APPLICABLE': 0 };
            for (const row of matrix) summary[feature][row.features[feature].status]++;
        }
        const report = {
            generated_at: new Date().toISOString(), widths, page_type: 'solo entry and archive',
            meaning: 'PASS is rendered/browser measured; NOT CHECKED and NOT APPLICABLE are not passes.',
            summary, negative_controls: controls, skins: matrix
        };
        fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
        console.log(JSON.stringify(summary, null, 2));
        console.log(`PASS: 30 skins classified; negative controls proved ${controls.map(item => item.feature).join(', ')}`);
        console.log(`Report: ${reportPath}`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

// ===== SNAPSMACK EOF =====
