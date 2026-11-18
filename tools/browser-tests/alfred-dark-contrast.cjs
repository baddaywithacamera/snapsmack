#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const css = fs.readFileSync(path.join(root, 'skins', 'alfred', 'style.css'), 'utf8');
const output = path.join(__dirname, 'evidence', 'alfred-dark-text');
fs.mkdirSync(output, { recursive: true });

function channel(value) {
    const normalized = value / 255;
    return normalized <= 0.04045
        ? normalized / 12.92
        : Math.pow((normalized + 0.055) / 1.055, 2.4);
}

function luminance(rgb) {
    const match = rgb.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    assert.ok(match, `expected an rgb colour, got ${rgb}`);
    return (0.2126 * channel(Number(match[1])))
        + (0.7152 * channel(Number(match[2])))
        + (0.0722 * channel(Number(match[3])));
}

function contrast(foreground, background) {
    const light = Math.max(luminance(foreground), luminance(background));
    const dark = Math.min(luminance(foreground), luminance(background));
    return (light + 0.05) / (dark + 0.05);
}

const html = `<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body class="alfred-v2"><div id="page" class="alfred">
<nav class="navigation"><div class="section-inner"><ul class="main-menu"><li><a href="#">HOME</a></li><li><a href="#">ARCHIVE VIEW</a></li><li><a href="#">ABOUT SEAN</a></li></ul></div></nav>
<div class="header-image" aria-hidden="true"></div>
<header class="site-header header section-inner"><div class="inside"><a class="site-title logo-area blog-title" href="#"><span class="site-title-text">SEAN MCCORMICK<br>PHOTOGRAPHY</span></a><p class="site-tagline blog-description">Saving the beauty in this moment for the ones that follow.</p></div></header>
<p class="contrast-probe">Dark-canvas text remains readable.</p>
<div id="content"><main class="content post-inner reading-column longform"><article class="smacktalk-post post-container h-entry static-page"><div class="post-header"><h1 class="post-title p-name">THE HALF CENTURY MARK</h1></div><div class="post-content entry-content e-content"><p>Body copy remains dark on the white reading panel. The surface pairing is explicit, so repairing the dark canvas does not invert a long-form post.</p><p>Photography is the practice of paying attention.</p></div></article></main></div>
<footer id="system-footer"><div class="footer-metadata-bar"><p>© 2026 SEAN MCCORMICK PHOTOGRAPHY &nbsp; | &nbsp; THEME: ALFRED</p></div></footer>
</div></body></html>`;

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        for (const viewport of [
            { name: 'portrait-1024x1366', width: 1024, height: 1366 },
            { name: 'landscape-1366x1024', width: 1366, height: 1024 },
        ]) {
            const page = await browser.newPage({ viewport });
            await page.setContent(html, { waitUntil: 'domcontentloaded' });
            await page.addStyleTag({ content: css });
            await page.addStyleTag({ content: '.contrast-probe{position:relative;z-index:3;width:min(520px,85%);margin:0 auto 32px;font:600 18px/1.5 Montserrat,sans-serif}.post-container{position:relative;z-index:3}' });

            const result = await page.evaluate(() => {
                const body = getComputedStyle(document.body);
                const panel = document.querySelector('.post-container');
                const title = document.querySelector('.post-title');
                const copy = document.querySelector('.post-content');
                const panelStyle = getComputedStyle(panel);
                return {
                    bodyText: body.color,
                    bodyBackground: body.backgroundColor,
                    panelText: panelStyle.color,
                    panelBackground: panelStyle.backgroundColor,
                    titleText: getComputedStyle(title).color,
                    copyText: getComputedStyle(copy).color,
                    overflow: document.documentElement.scrollWidth - innerWidth,
                    panel: panel.getBoundingClientRect().toJSON(),
                };
            });

            const darkRatio = contrast(result.bodyText, result.bodyBackground);
            const panelRatio = contrast(result.panelText, result.panelBackground);
            assert.ok(darkRatio >= 4.5, `${viewport.name}: dark canvas contrast is ${darkRatio.toFixed(2)}:1`);
            assert.ok(panelRatio >= 4.5, `${viewport.name}: white panel contrast is ${panelRatio.toFixed(2)}:1`);
            assert.equal(result.titleText, 'rgb(29, 29, 29)', `${viewport.name}: post title stays dark on white`);
            assert.equal(result.copyText, 'rgb(51, 51, 51)', `${viewport.name}: long-form copy stays dark on white`);
            assert.ok(result.overflow <= 0, `${viewport.name}: no horizontal overflow`);
            assert.ok(result.panel.left >= 0 && result.panel.right <= viewport.width, `${viewport.name}: reading panel stays inside viewport`);

            await page.screenshot({ path: path.join(output, `${viewport.name}.png`), fullPage: true });
            console.log(`PASS ${viewport.name}: dark ${darkRatio.toFixed(2)}:1; panel ${panelRatio.toFixed(2)}:1; no overflow`);
            await page.close();
        }
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
