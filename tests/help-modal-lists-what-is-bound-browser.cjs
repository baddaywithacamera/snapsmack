/**
 * The F1 help modal lists every key that is actually bound, and follows the
 * skin into light or dark.
 *
 * REPORTED 30 October 2026: "there used to be more help options? 1 for info,
 * 2 for comments, space for previous post, arrows to go back and forth."
 *
 * Nothing had been unbound. ss-engine-comms.js still bound all of them. The
 * help modal re-derived the list from DOM probes of its own, and those probes
 * demanded window.smackdown and #snap-nav-data -- the two sources the comms
 * engine had stopped depending on at 0.7.875D when it learned to read
 * rel="prev"/rel="next" and #show-details. So the keys worked and the rows
 * vanished. The modal was also hardcoded to a near-black fallback palette,
 * which 18 of the 31 skins fell back to regardless of how they look.
 *
 * This runs the REAL engine files in a REAL browser and reads the rendered
 * panel, because a check that parses source could not tell whether a row
 * reached the screen or what colour it ended up.
 *
 * Run: node tests/help-modal-lists-what-is-bound-browser.cjs
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../tools/browser-tests/runtime.cjs');

const root = path.resolve(__dirname, '..');
const comms = fs.readFileSync(path.join(root, 'assets/js/ss-engine-comms.js'), 'utf8');
const help = fs.readFileSync(path.join(root, 'assets/js/ss-engine-public-help.js'), 'utf8');

let checks = 0;
const check = (cond, label) => { assert.ok(cond, label); checks++; };

// The DOM contract a solo photo page presents. Deliberately WITHOUT
// #snap-nav-data and WITHOUT window.smackdown: those are the two things the
// old help probes insisted on, and the whole point is that the keys work and
// must be listed without them.
function soloPage(css) {
    return `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head>
<body>
  <a rel="prev" href="/photo/older">older</a>
  <a rel="next" href="/photo/newer">newer</a>
  <button id="show-details">info</button>
  <button id="show-comments">comments</button>
  <a class="snap-download-btn" href="/dl">download</a>
</body></html>`;
}

// Measured off the real skins on 30 October 2026.
const CASES = [
    {
        name: 'dark skin, no theme variables (TILEZ / TELEGRAM / ALFRED all use this exact value)',
        css: 'body{background:#1d1d1d;color:#ddd;margin:0}',
        wantDarkPanel: true,
        wantNeonAccent: true
    },
    {
        name: 'light skin, no theme variables (52-CARD-PICKUP)',
        css: 'body{background-color:#F5F0E8;color:#222;margin:0}',
        wantDarkPanel: false,
        wantNeonAccent: false
    },
    {
        name: 'light skin, white (SHOW-N-TELL)',
        css: 'body{background-color:#ffffff;color:#111;margin:0}',
        wantDarkPanel: false,
        wantNeonAccent: false
    },
    {
        name: 'bespoke variable name the old list never read (GALLERIA / HIP-TO-BE-SQUARE use --wall-bg)',
        css: ':root{--wall-bg:#fbf7f0}body{background-color:var(--wall-bg);margin:0}',
        wantDarkPanel: false,
        wantNeonAccent: false
    },
    {
        name: 'transparent body with the fill on html (the walk-up case)',
        css: 'html{background:#101014}body{background:transparent;margin:0}',
        wantDarkPanel: true,
        wantNeonAccent: true
    }
];

function luminance([r, g, b]) {
    const ch = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); };
    return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b);
}
const parseRgb = (s) => s.match(/\d+/g).slice(0, 3).map(Number);

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        for (const testCase of CASES) {
            const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
            await page.setContent(soloPage(testCase.css));
            await page.addScriptTag({ content: comms });
            await page.addScriptTag({ content: help });

            // Nothing in this test sets window.smackdown. Prove it, so a
            // future change that quietly reintroduces the dependency cannot
            // make these assertions pass for the wrong reason.
            check(await page.evaluate(() => typeof window.smackdown === 'undefined'),
                `${testCase.name}: window.smackdown is absent`);
            check(await page.evaluate(() => document.getElementById('snap-nav-data') === null),
                `${testCase.name}: #snap-nav-data is absent`);

            await page.evaluate(() => window.snapPublicHelp.open());

            const panel = await page.evaluate(() => {
                const p = document.getElementById('snap-public-help-panel');
                if (!p) return null;
                // Found by layout, not by id, so this scrape reads the
                // pre-fix modal exactly the same way and the regression it
                // reports is a missing ROW rather than a missing hook.
                const grid = Array.from(p.children)
                    .find((el) => getComputedStyle(el).display === 'grid');
                const cells = grid ? Array.from(grid.children) : [];
                const rows = [];
                for (let i = 0; i < cells.length; i += 2) {
                    rows.push([cells[i].textContent, (cells[i + 1] || {}).textContent]);
                }
                const firstKey = grid ? grid.querySelector('strong') : null;
                return {
                    visible: getComputedStyle(document.getElementById('snap-public-help-backdrop')).display,
                    bg: getComputedStyle(p).backgroundColor,
                    color: getComputedStyle(p).color,
                    accent: firstKey ? getComputedStyle(firstKey).color : '',
                    keys: rows.map((r) => r[0]),
                    rows: rows
                };
            });

            check(panel !== null, `${testCase.name}: panel is in the DOM`);
            check(panel.visible === 'flex', `${testCase.name}: backdrop is displayed`);

            // THE REGRESSION. Every key the comms engine binds on this page
            // must be named in the panel.
            for (const key of ['LEFT', 'RIGHT', 'SPACE', '1', '2', 'D', 'F1', 'ESC']) {
                check(panel.keys.includes(key),
                    `${testCase.name}: help lists [${key}] (keys shown: ${panel.keys.join(' ')})`);
            }

            // And the descriptions are the ones a reader needs, not blanks.
            const described = panel.rows.filter((r) => r[1] && r[1].trim().length > 2);
            check(described.length === panel.rows.length,
                `${testCase.name}: every row has a description`);

            // THEME. Panel background must sit on the same side as the page,
            // and the text must contrast with the panel it is drawn on.
            const bgLum = luminance(parseRgb(panel.bg));
            const textLum = luminance(parseRgb(panel.color));
            if (testCase.wantDarkPanel) {
                check(bgLum < 0.4, `${testCase.name}: panel is dark (luminance ${bgLum.toFixed(3)})`);
                check(textLum > 0.5, `${testCase.name}: text is light on it (${textLum.toFixed(3)})`);
            } else {
                check(bgLum > 0.6, `${testCase.name}: panel is light (luminance ${bgLum.toFixed(3)})`);
                check(textLum < 0.2, `${testCase.name}: text is dark on it (${textLum.toFixed(3)})`);
            }

            const ratio = (Math.max(bgLum, textLum) + 0.05) / (Math.min(bgLum, textLum) + 0.05);
            check(ratio >= 4.5, `${testCase.name}: text/background contrast ${ratio.toFixed(1)}:1 is readable`);

            // The neon green is the established look on dark and is kept
            // exactly; on a pale panel it is unreadable and must not be used.
            const isNeon = panel.accent.replace(/\s/g, '') === 'rgb(57,255,20)';
            check(isNeon === testCase.wantNeonAccent,
                `${testCase.name}: accent ${testCase.wantNeonAccent ? 'is' : 'is not'} the neon green (got ${panel.accent})`);

            const accentRatio = (() => {
                const a = luminance(parseRgb(panel.accent));
                return (Math.max(bgLum, a) + 0.05) / (Math.min(bgLum, a) + 0.05);
            })();
            check(accentRatio >= 3, `${testCase.name}: accent contrast ${accentRatio.toFixed(1)}:1 is readable`);

            await page.close();
        }

        // MUTATION PROOF. If the rows still came from this file's own DOM
        // probes rather than from the comms engine, removing the engine's
        // report would change nothing and the fix would be decorative.
        {
            const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
            await page.setContent(soloPage('body{background:#1d1d1d;margin:0}'));
            await page.addScriptTag({ content: comms });
            await page.addScriptTag({ content: help });
            await page.evaluate(() => { delete window.snapKeyboard; window.snapPublicHelp.open(); });
            const keys = await page.evaluate(() =>
                Array.from(Array.from(document.getElementById('snap-public-help-panel').children).find(function(el){return getComputedStyle(el).display==='grid';}).querySelectorAll('strong')).map((n) => n.textContent));
            for (const key of ['LEFT', 'RIGHT', 'SPACE', '1', '2', 'D']) {
                check(!keys.includes(key),
                    `mutation: without snapKeyboard the modal cannot invent [${key}] (got ${keys.join(' ') || 'nothing'})`);
            }
            check(keys.includes('--'),
                'mutation: an empty list says so rather than showing a bare box');
            await page.close();
        }

        // A carousel site keeps standard cursor controls only. The handler
        // drops F1 and [1]/[2]/[D] there, so the list must drop them too.
        {
            const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
            await page.setContent(soloPage('body{background:#1d1d1d;margin:0}'));
            await page.evaluate(() => { window.SNAP_SITE_MODE = 'carousel'; });
            await page.addScriptTag({ content: comms });
            await page.addScriptTag({ content: help });
            await page.evaluate(() => window.snapPublicHelp.open());
            const keys = await page.evaluate(() =>
                Array.from(Array.from(document.getElementById('snap-public-help-panel').children).find(function(el){return getComputedStyle(el).display==='grid';}).querySelectorAll('strong')).map((n) => n.textContent));
            for (const key of ['F1', '1', '2', 'D']) {
                check(!keys.includes(key), `gram: [${key}] is not offered on a carousel site`);
            }
            for (const key of ['LEFT', 'RIGHT', 'ESC']) {
                check(keys.includes(key), `gram: [${key}] still is`);
            }
            await page.close();
        }

        console.log(`PASS help modal lists every bound key and follows the skin's light/dark — ${checks} checks`);
    } finally {
        await browser.close();
    }
})().catch((err) => { console.error(err); process.exitCode = 1; });
// ===== SNAPSMACK EOF =====
