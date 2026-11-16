#!/usr/bin/env node
// SNAPSMACK_EOF_HEADER: end this file with the canonical SNAPSMACK EOF marker.
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');

const landingUrl = process.env.GLIDE_LIVE_URL || 'https://portalproject.photoblogs.fyi/';
const directUrl = process.env.GLIDE_DIRECT_URL || 'https://portalproject.photoblogs.fyi/blue-chapel-entrance-1793853345';
const output = process.env.SNAPSMACK_BROWSER_OUTPUT || path.join(process.env.TEMP || process.cwd(), 'glide-live-verification');
const widths = [1920, 1440, 1024];

fs.mkdirSync(output, { recursive: true });

async function dismissConsent(page) {
    const button = page.getByRole('button', { name: /continue without/i });
    if (await button.count()) await button.first().click();
}

async function waitForImages(page) {
    await page.waitForFunction(() => Array.from(document.images).some(image => image.complete && image.naturalWidth > 0));
    await page.waitForTimeout(800);
}

async function verifyLanding(browser, width) {
    const height = width === 1024 ? 768 : Math.round(width * 9 / 16);
    const page = await browser.newPage({ viewport: { width, height } });
    await page.goto(landingUrl, { waitUntil: 'networkidle', timeout: 90000 });
    await dismissConsent(page);
    await waitForImages(page);

    const setup = await page.evaluate(() => {
        const wall = document.querySelector('[data-glide-wall]');
        const rows = Array.from(document.querySelectorAll('[data-glide-row]'));
        return {
            settle: wall?.getAttribute('data-glide-settle') || '',
            rowCount: rows.length,
            tileCounts: rows.map(row => row.querySelectorAll('.glide-tile').length)
        };
    });

    const motion = await page.evaluate(async () => {
        const rows = Array.from(document.querySelectorAll('[data-glide-row]'));
        const axisValue = row => {
            const transform = getComputedStyle(row.querySelector('.glide-track')).transform;
            if (transform === 'none') return 0;
            const matrix = new DOMMatrix(transform);
            return document.querySelector('[data-glide-wall]')?.dataset.flowAxis === 'vertical' ? matrix.m42 : matrix.m41;
        };
        const starts = rows.map(axisValue);
        rows.forEach((row, rowIndex) => {
            row.dispatchEvent(new WheelEvent('wheel', {
                bubbles: true,
                cancelable: true,
                deltaY: 240 + rowIndex * 7
            }));
        });
        const samples = [];
        const began = performance.now();
        while (performance.now() - began < 1300) {
            await new Promise(resolve => requestAnimationFrame(resolve));
            samples.push(rows.map(axisValue));
        }
        return rows.map((row, rowIndex) => {
            const series = samples.map(sample => sample[rowIndex]);
            const deltas = series.slice(1).map((value, index) => value - series[index])
                .filter(delta => Math.abs(delta) > 0.02);
            const direction = Math.sign(deltas[0] || 0);
            const reversals = deltas.filter(delta => direction && Math.sign(delta) !== direction).length;
            const end = series.at(-1);
            const track = row.querySelector('.glide-track');
            const firstTile = track?.firstElementChild;
            const style = track ? getComputedStyle(track) : null;
            const gap = parseFloat(style?.rowGap || style?.gap || '0') || 0;
            const stride = (firstTile?.offsetHeight || 0) + gap;
            const modulo = stride > 0 ? ((end % stride) + stride) % stride : Number.NaN;
            const boundaryError = stride > 0 ? Math.min(modulo, stride - modulo) : Number.NaN;
            return {
                row: rowIndex + 1,
                start: starts[rowIndex],
                end,
                travel: end - starts[rowIndex],
                reversals,
                finalDelta: Math.abs(series.at(-1) - series.at(-2)),
                stride,
                boundaryError
            };
        });
    });

    const geometry = await page.evaluate(() => {
        const viewport = { width: innerWidth, height: innerHeight, centreX: innerWidth / 2, centreY: innerHeight / 2 };
        return Array.from(document.querySelectorAll('[data-glide-row]')).map((row, index) => {
            const tiles = Array.from(row.querySelectorAll('.glide-tile')).map(tile => {
                const rect = tile.getBoundingClientRect();
                const visibleWidth = Math.max(0, Math.min(rect.right, innerWidth) - Math.max(rect.left, 0));
                const visibleHeight = Math.max(0, Math.min(rect.bottom, innerHeight) - Math.max(rect.top, 0));
                return {
                    href: tile.href,
                    left: rect.left,
                    right: rect.right,
                    top: rect.top,
                    bottom: rect.bottom,
                    centreX: (rect.left + rect.right) / 2,
                    centreY: (rect.top + rect.bottom) / 2,
                    fullyVisible: rect.left >= -1 && rect.right <= innerWidth + 1 && rect.top >= -1 && rect.bottom <= innerHeight + 1,
                    visibleArea: visibleWidth * visibleHeight
                };
            });
            const visible = tiles.filter(tile => tile.visibleArea > 0);
            const nearest = visible.sort((a, b) => Math.abs(a.centreY - viewport.centreY) - Math.abs(b.centreY - viewport.centreY))[0] || null;
            return { row: index + 1, visibleCount: visible.length, nearest };
        });
    });

    await page.screenshot({ path: path.join(output, `glide-landing-${width}.png`), fullPage: false });
    const firstTile = page.locator('.glide-tile').first();
    await firstTile.dispatchEvent('click');
    await page.waitForTimeout(500);
    const viewer = await page.evaluate(() => {
        const candidates = Array.from(document.querySelectorAll('dialog, [role="dialog"], .wall-viewer, .lightbox, .viewer'))
            .filter(element => {
                const style = getComputedStyle(element);
                const rect = element.getBoundingClientRect();
                return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
            });
        const controls = Array.from(document.querySelectorAll('button, [role="button"], a'))
            .map(element => ({
                text: (element.textContent || '').trim(),
                label: element.getAttribute('aria-label') || '',
                title: element.getAttribute('title') || '',
                className: typeof element.className === 'string' ? element.className : ''
            }));
        const heartControls = controls.filter(control => /heart|like|love/i.test(`${control.text} ${control.label} ${control.title} ${control.className}`));
        const viewerImages = candidates.flatMap(element => Array.from(element.querySelectorAll('img'), image => {
            const rect = image.getBoundingClientRect();
            return { rect, area: rect.width * rect.height };
        })).sort((a, b) => b.area - a.area);
        const viewerImage = viewerImages[0]?.rect || null;
        return {
            dialogCount: candidates.length,
            dialogClasses: candidates.map(element => element.className || element.tagName),
            heartControls,
            dialogControls: candidates.flatMap(element => Array.from(element.querySelectorAll('button, [role="button"], a'), control => control.outerHTML.slice(0, 600))),
            viewerImage: viewerImage ? {
                left: viewerImage.left,
                right: viewerImage.right,
                top: viewerImage.top,
                bottom: viewerImage.bottom,
                centreOffsetX: (viewerImage.left + viewerImage.right) / 2 - innerWidth / 2,
                centreOffsetY: (viewerImage.top + viewerImage.bottom) / 2 - innerHeight / 2,
                fullyVisible: viewerImage.left >= -1 && viewerImage.right <= innerWidth + 1 && viewerImage.top >= -1 && viewerImage.bottom <= innerHeight + 1
            } : null
        };
    });

    await page.screenshot({ path: path.join(output, `glide-viewer-${width}.png`), fullPage: false });
    await page.close();
    return { width, height, setup, motion, geometry, viewer };
}

async function verifyDirect(browser, width) {
    const height = width === 1024 ? 768 : Math.round(width * 9 / 16);
    const page = await browser.newPage({ viewport: { width, height } });
    await page.goto(directUrl, { waitUntil: 'networkidle', timeout: 90000 });
    await dismissConsent(page);
    await page.locator('.post-image').waitFor({ state: 'visible', timeout: 30000 });
    await waitForImages(page);
    const result = await page.evaluate(() => {
        const image = document.querySelector('.post-image')?.getBoundingClientRect();
        if (!image) return { present: false };
        const centreX = (image.left + image.right) / 2;
        const body = document.body.getBoundingClientRect();
        const visibleCentre = (body.left + body.right) / 2;
        const controls = Array.from(document.querySelectorAll('button, [role="button"], a'))
            .map(element => `${element.textContent || ''} ${element.getAttribute('aria-label') || ''} ${element.getAttribute('title') || ''} ${typeof element.className === 'string' ? element.className : ''}`);
        return {
            present: true,
            image: { left: image.left, right: image.right, top: image.top, bottom: image.bottom, width: image.width, height: image.height },
            centreOffset: centreX - visibleCentre,
            fullyVisibleVertically: image.top >= -1 && image.bottom <= innerHeight + 1,
            horizontalOverflow: document.documentElement.scrollWidth - innerWidth,
            heartControls: controls.filter(text => /heart|like|love/i.test(text))
        };
    });
    await page.screenshot({ path: path.join(output, `glide-direct-${width}.png`), fullPage: false });
    await page.close();
    return { width, height, ...result };
}

(async () => {
    const browser = await chromium.launch({ headless: true });
    const report = { generatedAt: new Date().toISOString(), landingUrl, directUrl, landing: [], direct: [] };
    try {
        for (const width of widths) report.landing.push(await verifyLanding(browser, width));
        for (const width of widths) report.direct.push(await verifyDirect(browser, width));
    } finally {
        await browser.close();
    }
    const allMotion = report.landing.flatMap(result => result.motion);
    const allViewers = report.landing.map(result => result.viewer);
    report.summary = {
        everyConveyor: {
            status: report.landing.every(result => result.setup.rowCount === 9)
                && allMotion.every(row => Math.abs(row.travel) > 20 && row.reversals === 0 && row.finalDelta < 0.15) ? 'PASS' : 'FAIL',
            evidence: `${allMotion.length} row/width checks; expected 9 rows at each supported width`
        },
        settleToCentre: {
            status: report.landing.every(result => result.setup.settle === 'nearest')
                && allViewers.every(viewer => viewer.viewerImage?.fullyVisible
                    && Math.abs(viewer.viewerImage.centreOffsetX) <= 1
                    && Math.abs(viewer.viewerImage.centreOffsetY) <= 1) ? 'PASS' : 'FAIL',
            evidence: 'nearest settle is live; opened viewer image must be fully visible and centred on both axes'
        },
        directPostCentred: {
            status: report.direct.every(result => result.present && result.fullyVisibleVertically
                && Math.abs(result.centreOffset) <= 1 && result.horizontalOverflow <= 0) ? 'PASS' : 'FAIL',
            evidence: 'direct-post image must be centred in the visible body, fully visible vertically, with no horizontal overflow'
        },
        hearts: {
            status: allViewers.every(viewer => viewer.heartControls.length > 0)
                && report.direct.every(result => result.heartControls.length > 0) ? 'PASS' : 'FAIL',
            evidence: 'heart/like/love control searched in the opened viewer and direct post at every width; no control was clicked'
        }
    };
    fs.writeFileSync(path.join(output, 'report.json'), `${JSON.stringify(report, null, 2)}\n`);
    console.log(JSON.stringify(report, null, 2));
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
