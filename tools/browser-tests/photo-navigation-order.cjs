// SNAPSMACK_EOF_HEADER: end this file with the canonical SNAPSMACK EOF marker.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const seam = process.env.SNAP_BROWSER_SEAM_ROOT || path.resolve(__dirname, '../..');
const { chromium } = require(path.join(seam, 'tools/browser-tests/runtime.cjs'));
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const skins = [
    ['50-shades-of-noah-grey', '50-shades-of-noah-grey-v2'],
    ['chaplin', 'chaplin-v2'],
    ['galleria', 'galleria-v2'],
    ['hip-to-be-square', 'hip-to-be-square-v2'],
    ['impact-printer', 'impact-printer-v2'],
    ['new-horizon', 'new-horizon-v2'],
    ['onyx', 'onyx-v2'],
    ['rational-geo', 'rational-geo-v2'],
    ['scroll', 'scroll-v2'],
    ['slickr', 'slickr-v2'],
    ['true-grit', 'true-grit-v2'],
];
const widths = [1920, 1440, 1024];

function phpQuote(value) {
    return `'${value.replaceAll('\\', '/').replaceAll("'", "\\'")}'`;
}

function renderNavigation() {
    const trusted = phpQuote(path.join(root, 'core/trusted-html.php'));
    const helpers = phpQuote(path.join(root, 'core/skin-render-helpers.php'));
    const script = `require_once ${trusted}; require_once ${helpers}; echo (string)snap_render_component('photo-navigation', [`
        + `'item'=>['url'=>'/current'],'previous'=>['url'=>'/previous'],'next'=>['url'=>'/next'],`
        + `'first'=>['url'=>'/first'],'last'=>['url'=>'/last'],'comments'=>[],'comments_enabled'=>true]);`;
    return execFileSync('php', ['-r', script], { cwd: root, encoding: 'utf8' });
}

async function check(page, skin, bodyClass, width, navigation) {
    await page.setViewportSize({ width, height: 900 });
    await page.setContent(`<body class="${bodyClass}"><main class="public-content"><div id="infobox">${navigation}</div></main></body>`);
    await page.addStyleTag({ content: read('assets/css/public-base.css') });
    await page.addStyleTag({ content: read(`skins/${skin}/style.css`) });

    const observed = await page.locator('#infobox .nav-links a').evaluateAll(links => links.map(link => ({
        text: link.textContent.trim(),
        rel: link.getAttribute('rel') || '',
        left: link.getBoundingClientRect().left,
        right: link.getBoundingClientRect().right,
    })));
    assert.deepEqual(observed.map(link => link.text), ['« NEXT', 'FIRST', 'INFO', 'COMMENTS (0)', 'LAST', 'PREV »'],
        `${skin} ${width}px: DOM order must match the approved visible order`);
    assert.equal(observed[0].rel, 'next', `${skin} ${width}px: left NEXT must advertise rel=next`);
    assert.equal(observed[5].rel, 'prev', `${skin} ${width}px: right PREV must advertise rel=prev`);
    for (let index = 1; index < observed.length; index += 1) {
        assert.ok(observed[index].left >= observed[index - 1].right - 0.5,
            `${skin} ${width}px: ${observed[index].text} must render after ${observed[index - 1].text}`);
    }
    process.stdout.write(`PASS ${skin} ${width}px\n`);
}

(async () => {
    const navigation = renderNavigation();
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    try {
        for (const [skin, bodyClass] of skins) {
            for (const width of widths) await check(page, skin, bodyClass, width, navigation);
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

// ===== SNAPSMACK EOF =====
