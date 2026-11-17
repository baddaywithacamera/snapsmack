#!/usr/bin/env node
'use strict';

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('@playwright/test');

const fixtureDir = path.resolve(process.argv[2] || '.geometry-fixtures');
const evidenceDir = path.resolve(process.argv[3] || '.geometry-evidence');
const label = process.argv[4] || 'measurement';
const repoRoot = path.resolve(__dirname, '../..');
const skins = ['new-horizon', 'impact-printer'];
const widths = [880, 1024, 1440, 1920];

fs.mkdirSync(evidenceDir, { recursive: true });

function fileUrl(file) {
    return `file:///${file.replaceAll('\\', '/')}`;
}

(async () => {
    const browser = await chromium.launch();
    const results = [];

    try {
        for (const skin of skins) {
            const fixture = path.join(fixtureDir, `${skin}--page.html`);
            assert.ok(fs.existsSync(fixture), `Missing fixture: ${fixture}`);

            for (const width of widths) {
                const page = await browser.newPage({ viewport: { width, height: 1100 } });
                await page.goto(fileUrl(fixture));
                await page.addScriptTag({
                    path: path.join(repoRoot, 'assets/js/ss-engine-image-fade-load.js'),
                });
                await page.locator('#photobox.page-hero img').waitFor({ state: 'visible' });
                await page.locator('#photobox.page-hero img').evaluate(async (image) => {
                    if (!image.complete) await image.decode();
                });
                await page.locator('#photobox.page-hero img').waitFor({ state: 'visible' });
                await page.waitForFunction(() => {
                    const image = document.querySelector('#photobox.page-hero img');
                    return image && getComputedStyle(image).opacity === '1';
                });

                const geometry = await page.evaluate(() => {
                    const pick = (selector) => document.querySelector(selector);
                    const rect = (element) => {
                        const value = typeof element.getBoundingClientRect === 'function'
                            ? element.getBoundingClientRect()
                            : element;
                        return {
                            top: value.top,
                            right: value.right,
                            bottom: value.bottom,
                            left: value.left,
                            width: value.width,
                            height: value.height,
                        };
                    };
                    const firstTextLine = (element) => {
                        const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
                        let textNode = walker.nextNode();
                        while (textNode && !textNode.textContent.trim()) textNode = walker.nextNode();
                        const range = document.createRange();
                        range.selectNodeContents(textNode);
                        return rect(range.getClientRects()[0]);
                    };

                    const content = pick('.static-content');
                    const hero = pick('#photobox.page-hero');
                    const mainPhoto = pick('#photobox.page-hero .main-photo');
                    const image = pick('#photobox.page-hero img');
                    const description = pick('.static-content > .description');
                    const paragraph = pick('.static-content > .description p:first-of-type');
                    const imageStyle = getComputedStyle(image);
                    const mainStyle = getComputedStyle(mainPhoto);
                    const descriptionStyle = getComputedStyle(description);

                    const imageRect = rect(image);
                    const lineRect = firstTextLine(paragraph);
                    const shadowSpread = imageStyle.boxShadow === 'none'
                        ? 0
                        : Math.max(...imageStyle.boxShadow
                            .split(/,(?![^()]*\))/)
                            .map((shadow) => {
                                const lengths = [...shadow.matchAll(/(-?\d+(?:\.\d+)?)px/g)]
                                    .map((match) => Number(match[1]));
                                return lengths[3] || 0;
                            }));
                    const frameOuterTop = imageRect.top - shadowSpread;
                    const frameOuterRight = imageRect.right + shadowSpread;
                    return {
                        content: rect(content),
                        hero: rect(hero),
                        mainPhoto: rect(mainPhoto),
                        image: imageRect,
                        description: rect(description),
                        paragraph: rect(paragraph),
                        firstTextLine: lineRect,
                        frameTopToTextTop: imageRect.top - lineRect.top,
                        frameToTextGutter: lineRect.left - imageRect.right,
                        shadowSpread,
                        frameOuterTop,
                        frameOuterRight,
                        visibleFrameTopToTextTop: frameOuterTop - lineRect.top,
                        visibleFrameToTextGutter: lineRect.left - frameOuterRight,
                        computed: {
                            mainPhotoPadding: mainStyle.padding,
                            imageBorder: imageStyle.border,
                            imageBoxShadow: imageStyle.boxShadow,
                            descriptionPadding: descriptionStyle.padding,
                            descriptionLineHeight: descriptionStyle.lineHeight,
                        },
                        overflow: document.documentElement.scrollWidth - window.innerWidth,
                    };
                });

                await page.screenshot({
                    path: path.join(evidenceDir, `${label}-${skin}-${width}.png`),
                    fullPage: true,
                });

                await page.addStyleTag({
                    content: '.static-page-title{font-size:72px!important;line-height:1.4!important}',
                });
                const headingStress = await page.evaluate(() => {
                    const image = document.querySelector('#photobox.page-hero img');
                    const paragraph = document.querySelector(
                        '.static-content > .description p:first-of-type'
                    );
                    const imageRect = image.getBoundingClientRect();
                    const walker = document.createTreeWalker(paragraph, NodeFilter.SHOW_TEXT);
                    let textNode = walker.nextNode();
                    while (textNode && !textNode.textContent.trim()) textNode = walker.nextNode();
                    const range = document.createRange();
                    range.selectNodeContents(textNode);
                    const lineRect = range.getClientRects()[0];
                    const shadow = getComputedStyle(image).boxShadow;
                    const shadowSpread = shadow === 'none'
                        ? 0
                        : Math.max(...shadow.split(/,(?![^()]*\))/).map((entry) => {
                            const lengths = [...entry.matchAll(/(-?\d+(?:\.\d+)?)px/g)]
                                .map((match) => Number(match[1]));
                            return lengths[3] || 0;
                        }));
                    return {
                        frameOuterTop: imageRect.top - shadowSpread,
                        textTop: lineRect.top,
                        visibleFrameTopToTextTop:
                            imageRect.top - shadowSpread - lineRect.top,
                    };
                });
                geometry.headingStress = headingStress;

                if (label === 'after') {
                    assert.ok(
                        geometry.overflow <= 1,
                        `${skin} ${width}: page overflows by ${geometry.overflow}px`
                    );
                    if (width > 900) {
                        assert.ok(
                            Math.abs(geometry.visibleFrameTopToTextTop) <= 0.5,
                            `${skin} ${width}: frame/text tops differ by `
                                + `${geometry.visibleFrameTopToTextTop}px`
                        );
                        assert.ok(
                            Math.abs(geometry.visibleFrameToTextGutter - 46) <= 0.5,
                            `${skin} ${width}: visible gutter is `
                                + `${geometry.visibleFrameToTextGutter}px, expected 46px`
                        );
                        assert.ok(
                            Math.abs(headingStress.visibleFrameTopToTextTop) <= 0.5,
                            `${skin} ${width}: enlarged heading changes top alignment by `
                                + `${headingStress.visibleFrameTopToTextTop}px`
                        );
                    }
                }

                const record = { label, skin, width, geometry };
                results.push(record);
                await page.close();
                console.log(
                    `${label} ${skin} ${width}: `
                    + `visible frame/text top delta `
                    + `${geometry.visibleFrameTopToTextTop.toFixed(2)}px; `
                    + `visible frame/text gutter `
                    + `${geometry.visibleFrameToTextGutter.toFixed(2)}px; `
                    + `heading stress delta `
                    + `${headingStress.visibleFrameTopToTextTop.toFixed(2)}px; `
                    + `overflow ${geometry.overflow.toFixed(2)}px`
                );
            }
        }
    } finally {
        await browser.close();
    }

    fs.writeFileSync(
        path.join(evidenceDir, `${label}-measurements.json`),
        `${JSON.stringify(results, null, 2)}\n`
    );
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
