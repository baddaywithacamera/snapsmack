/**
 * SNAPSMACK release fleet safety net: public BEFORE/AFTER browser sweep.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');
const {
    EXPECTED_PAGE_TYPES,
    EXPECTED_SITE_COUNT,
    EXPECTED_WIDTHS,
    compareRuns,
    parseArgs,
    readRoster,
} = require('./release-fleet-safety-net.cjs');

const root = path.resolve(__dirname, '../..');

function refuse(message) {
    process.stderr.write(`REFUSED: ${message}\n`);
    process.exit(1);
}

function sha256File(file) {
    return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

function safeName(value) {
    return value.replace(/[^a-z0-9.-]+/gi, '_');
}

function writeJson(file, value) {
    fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`);
}

async function visible(locator) {
    try {
        return await locator.isVisible();
    } catch (_) {
        return false;
    }
}

async function discoverRoutes(context, host) {
    const home = `https://${host}/`;
    const page = await context.newPage();
    const routes = { home, archive: `https://${host}/archive`, solo: null };
    try {
        await page.goto(home, { waitUntil: 'domcontentloaded', timeout: 30000 });
        const archiveLink = page.locator('a[href]').filter({ hasText: /archive/i }).first();
        if (await visible(archiveLink)) {
            const href = await archiveLink.getAttribute('href');
            if (href) routes.archive = new URL(href, home).href;
        }
        await page.goto(routes.archive, { waitUntil: 'domcontentloaded', timeout: 30000 });
        routes.solo = await page.evaluate((expectedHost) => {
            const candidates = [...document.querySelectorAll('main a[href], article a[href], figure a[href], .archive a[href], .gallery a[href]')];
            for (const anchor of candidates) {
                const url = new URL(anchor.href, document.baseURI);
                if (url.hostname !== expectedHost || url.pathname === '/' || /\/archive\/?$/.test(url.pathname)) continue;
                if (anchor.querySelector('img, picture') || anchor.closest('article, figure, .archive-item, .photo')) return url.href;
            }
            return null;
        }, host);
    } catch (_) {
        // The capture pass records the precise public-page failure for each width.
    } finally {
        await page.close();
    }
    return routes;
}

async function captureSurface(context, output, host, pageType, width, url) {
    const height = width >= 1500 ? 1080 : 768;
    const key = `${host}-${pageType}-${width}`;
    if (!url) {
        return {
            host, page_type: pageType, width, height, url: null,
            not_checked_reason: 'no public solo-page link was discoverable from the archive',
            screenshot_sha256: null,
        };
    }
    const page = await context.newPage();
    await page.setViewportSize({ width, height });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    let response = null;
    let navigationError = null;
    try {
        response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
        await page.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' });
        await page.evaluate(async () => {
            if (document.fonts && document.fonts.ready) await document.fonts.ready;
            window.scrollTo(0, document.documentElement.scrollHeight);
        });
        await page.waitForTimeout(700);
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.waitForTimeout(200);
    } catch (error) {
        navigationError = error.message;
    }

    const metrics = await page.evaluate((kind) => {
        const shown = (element) => {
            const style = getComputedStyle(element);
            const rect = element.getBoundingClientRect();
            return style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity) !== 0
                && rect.width > 1 && rect.height > 1;
        };
        const brokenImages = [...document.images].filter((image) => image.complete && image.naturalWidth === 0).length;
        const labels = [...document.querySelectorAll('.screen-reader-text,.sr-only,.visually-hidden')]
            .filter((element) => shown(element) && (element.textContent || '').trim() !== '').length;
        const main = document.querySelector('main,[role="main"]') || document.body;
        const items = kind === 'archive'
            ? main.querySelectorAll('article,figure,.archive-item,.photo-item,.gallery-item,.mosaic-item').length
            : 0;
        const pagination = [...document.querySelectorAll('.pagination,.pager,.page-numbers,nav[aria-label*="pagination" i]')]
            .some(shown);
        const primaryImage = [...main.querySelectorAll('img,picture img')].some(shown);
        return {
            document_title: document.title,
            final_url: location.href,
            viewport_width: window.innerWidth,
            viewport_height: window.innerHeight,
            horizontal_overflow_px: Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
            broken_images: brokenImages,
            visible_screen_reader_labels: labels,
            main_has_content: ((main.innerText || '').trim().length > 0) || Boolean(main.querySelector('img,video,canvas,svg')),
            pagination_visible: pagination,
            content_item_count: items,
            primary_image_visible: primaryImage,
        };
    }, pageType).catch(() => ({
        document_title: '', final_url: page.url(), viewport_width: width, viewport_height: height, horizontal_overflow_px: 0,
        broken_images: 0, visible_screen_reader_labels: 0, main_has_content: false,
        pagination_visible: false, content_item_count: 0, primary_image_visible: false,
    }));

    const screenshotFile = path.join(output, `${safeName(key)}.png`);
    let screenshotSha = null;
    try {
        await page.screenshot({ path: screenshotFile, fullPage: true, animations: 'disabled' });
        screenshotSha = sha256File(screenshotFile);
    } catch (error) {
        if (!navigationError) navigationError = `screenshot failed: ${error.message}`;
    }
    await page.close();
    return {
        host, page_type: pageType, width, height, url,
        http_status: response ? response.status() : null,
        navigation_error: navigationError,
        page_errors: errors.length,
        page_error_messages: errors.slice(0, 10),
        screenshot: screenshotSha ? path.basename(screenshotFile) : null,
        screenshot_sha256: screenshotSha,
        ...metrics,
    };
}

async function mapLimited(items, concurrency, worker) {
    const results = new Array(items.length);
    let cursor = 0;
    async function run() {
        while (cursor < items.length) {
            const index = cursor++;
            results[index] = await worker(items[index], index);
        }
    }
    await Promise.all(Array.from({ length: concurrency }, run));
    return results;
}

async function capture(options) {
    const rosterPath = options.roster || path.join(root, 'tools/_build/release-visual-sites.txt');
    let hosts = readRoster(rosterPath);
    if (options.hosts) {
        if (process.env.SNAPSMACK_RELEASE_FLEET_TEST_MODE !== '1') refuse('--hosts is available only in explicit test mode');
        hosts = String(options.hosts).split(',').map((host) => host.trim()).filter(Boolean);
    }
    const output = path.resolve(options.output);
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        ignoreHTTPSErrors: false,
        userAgent: 'SnapSmack release safety net (read-only public-page check)',
        serviceWorkers: 'block',
    });
    const routes = {};
    for (const host of hosts) routes[host] = await discoverRoutes(context, host);
    const jobs = [];
    for (const host of hosts) {
        for (const pageType of EXPECTED_PAGE_TYPES) {
            for (const width of EXPECTED_WIDTHS) jobs.push({ host, pageType, width, url: routes[host][pageType] });
        }
    }
    const surfaces = await mapLimited(jobs, 4, async (job, index) => {
        process.stdout.write(`[${index + 1}/${jobs.length}] ${job.host} ${job.pageType} ${job.width}\n`);
        return captureSurface(context, output, job.host, job.pageType, job.width, job.url);
    });
    await context.close();
    await browser.close();
    const manifest = {
        schema: 1,
        phase: options.phase,
        release: options.release,
        generated_at: new Date().toISOString(),
        roster: path.relative(root, rosterPath).replaceAll('\\', '/'),
        site_count: hosts.length,
        expected_site_count: EXPECTED_SITE_COUNT,
        widths: EXPECTED_WIDTHS,
        page_types: EXPECTED_PAGE_TYPES,
        surface_count: surfaces.length,
        routes,
        surfaces,
    };
    writeJson(path.join(output, 'manifest.json'), manifest);
    return manifest;
}

async function main() {
    const options = parseArgs(process.argv.slice(2));
    options.phase = String(options.phase || '').toLowerCase();
    if (!['before', 'after'].includes(options.phase)) refuse('--phase must be before or after');
    if (!options.release) refuse('--release is required');
    if (!options.output) refuse('--output is required');
    if (options.phase === 'after' && !options.before) refuse('--before=<BEFORE directory> is required for AFTER');
    if (options.phase === 'before' && options.before) refuse('--before is only valid for AFTER');

    const manifest = await capture(options);
    if (options.phase === 'before') {
        process.stdout.write(`BEFORE PASS: ${manifest.surface_count} public surfaces captured in ${path.resolve(options.output)}\n`);
        return;
    }
    const beforeFile = path.join(path.resolve(options.before), 'manifest.json');
    if (!fs.existsSync(beforeFile)) refuse(`BEFORE manifest is missing: ${beforeFile}`);
    const before = JSON.parse(fs.readFileSync(beforeFile, 'utf8'));
    const comparison = compareRuns(before, manifest);
    const output = path.resolve(options.output);
    comparison.generated_at = new Date().toISOString();
    writeJson(path.join(output, 'comparison.json'), comparison);
    if (comparison.status === 'FAIL') {
        writeJson(path.join(output, 'rollback-required.json'), {
            schema: 1,
            release: options.release,
            status: 'ROLLBACK REQUIRED',
            regression_count: comparison.regressions.length,
            regressions: comparison.regressions,
            instruction: 'Restore the immediately preceding deployed release, then post this file to Brain Trust before owner notification.',
        });
        process.stderr.write(`AFTER FAIL: ${comparison.regressions.length} regressions. ROLLBACK REQUIRED.\n`);
        process.exitCode = 2;
        return;
    }
    process.stdout.write(`AFTER PASS: ${comparison.compared_surface_count} surfaces compared; ${comparison.changed_screenshot_count} screenshots changed; nothing measured got worse.\n`);
}

main().catch((error) => refuse(error.stack || error.message));

// ===== SNAPSMACK EOF =====
