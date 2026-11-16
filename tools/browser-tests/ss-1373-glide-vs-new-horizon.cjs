const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('playwright');

const sites = [
    { name: 'GLIDE', url: 'https://portalproject.photoblogs.fyi/archive' },
    { name: 'NEW HORIZON', url: 'https://curbappeal.photoblogs.fyi/archive' },
];
const widths = [1920, 1440, 1024];
const output = path.join(os.tmpdir(), 'ss-1373-glide-vs-new-horizon');
fs.mkdirSync(output, { recursive: true });

async function snapshot(page, elapsed) {
    return page.evaluate((at) => {
        const images = [...document.images].slice(0, 16).map((image) => {
            const rect = image.getBoundingClientRect();
            const style = getComputedStyle(image);
            return {
                src: image.currentSrc || image.src,
                className: image.className,
                complete: image.complete,
                naturalWidth: image.naturalWidth,
                naturalHeight: image.naturalHeight,
                displayedWidth: Math.round(rect.width * 10) / 10,
                displayedHeight: Math.round(rect.height * 10) / 10,
                opacity: style.opacity,
                visibility: style.visibility,
                display: style.display,
            };
        });
        const body = document.body ? getComputedStyle(document.body) : null;
        return {
            at,
            readyState: document.readyState,
            bodyBackground: body ? body.backgroundColor : null,
            images,
        };
    }, elapsed).catch(() => ({ at: elapsed, readyState: 'unavailable', images: [] }));
}

async function load(page, cdp, url, cacheDisabled, screenshotPath) {
    await cdp.send('Network.setCacheDisabled', { cacheDisabled });
    const started = Date.now();
    const frames = [];
    await page.goto(url, { waitUntil: 'commit', timeout: 60000 });
    for (const delay of [0, 50, 100, 150, 250, 400, 650, 1000, 1500]) {
        const remaining = started + delay - Date.now();
        if (remaining > 0) await page.waitForTimeout(remaining);
        frames.push(await snapshot(page, Date.now() - started));
    }
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    await page.screenshot({ path: screenshotPath, fullPage: false });

    const measured = await page.evaluate(() => {
        const resources = performance.getEntriesByType('resource').map((entry) => ({
            name: entry.name,
            initiatorType: entry.initiatorType,
            startTime: Math.round(entry.startTime * 10) / 10,
            duration: Math.round(entry.duration * 10) / 10,
            transferSize: entry.transferSize,
            decodedBodySize: entry.decodedBodySize,
        }));
        const paints = performance.getEntriesByType('paint').map((entry) => ({
            name: entry.name,
            startTime: Math.round(entry.startTime * 10) / 10,
        }));
        const images = [...document.images].slice(0, 16).map((image) => {
            const rect = image.getBoundingClientRect();
            const style = getComputedStyle(image);
            return {
                src: image.currentSrc || image.src,
                className: image.className,
                loading: image.loading,
                complete: image.complete,
                naturalWidth: image.naturalWidth,
                naturalHeight: image.naturalHeight,
                displayedWidth: Math.round(rect.width * 10) / 10,
                displayedHeight: Math.round(rect.height * 10) / 10,
                opacity: style.opacity,
                visibility: style.visibility,
                background: style.backgroundColor,
                aspectRatio: style.aspectRatio,
            };
        });
        return {
            url: location.href,
            title: document.title,
            paints,
            resources,
            images,
            bodyBackground: getComputedStyle(document.body).backgroundColor,
        };
    });
    return { elapsedToNetworkIdle: Date.now() - started, frames, ...measured };
}

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    const results = [];
    try {
        for (const site of sites) {
            for (const width of widths) {
                const context = await browser.newContext({
                    viewport: { width, height: 1000 },
                    serviceWorkers: 'block',
                });
                const page = await context.newPage();
                const cdp = await context.newCDPSession(page);
                await cdp.send('Network.enable');
                const slug = site.name.toLowerCase().replace(/\s+/g, '-');
                const cold = await load(
                    page,
                    cdp,
                    site.url,
                    true,
                    path.join(output, `${slug}-${width}-cold.png`),
                );
                const warm = await load(
                    page,
                    cdp,
                    site.url,
                    false,
                    path.join(output, `${slug}-${width}-warm.png`),
                );
                results.push({ site: site.name, width, cold, warm });
                await context.close();
            }
        }
    } finally {
        await browser.close();
    }
    const resultPath = path.join(output, 'measurements.json');
    fs.writeFileSync(resultPath, JSON.stringify(results, null, 2));
    console.log(resultPath);
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
// ===== SNAPSMACK EOF =====
