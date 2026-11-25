#!/usr/bin/env node
'use strict';

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('@playwright/test');

const repoRoot = path.resolve(__dirname, '../..');
const evidenceDir = path.resolve(process.argv[2] || '_review/onyx-1024-overflow');
const label = process.argv[3] || 'current';
const fixtureDir = path.resolve(process.argv[4] || '.geometry-fixtures');
const widths = [1024, 1440, 1920];
const pages = ['landing', 'archive'];

fs.mkdirSync(evidenceDir, { recursive: true });

function fileUrl(file) {
    return `file:///${file.replaceAll('\\', '/')}`;
}

(async () => {
    const browser = await chromium.launch();
    const results = [];

    try {
        for (const kind of pages) {
            const fixture = path.join(fixtureDir, `onyx--${kind}.html`);
            assert.ok(fs.existsSync(fixture), `Missing fixture: ${fixture}`);
            for (const width of widths) {
                const page = await browser.newPage({ viewport: { width, height: 768 } });
                await page.goto(fileUrl(fixture));

                const geometry = await page.evaluate(() => {
                    const grid = document.querySelector('#browse-grid');
                    const stage = document.querySelector('#scroll-stage');
                    const gridRect = grid.getBoundingClientRect();
                    const stageRect = stage.getBoundingClientRect();
                    return {
                        viewport: window.innerWidth,
                        documentWidth: document.documentElement.scrollWidth,
                        overflow: document.documentElement.scrollWidth - window.innerWidth,
                        grid: { left: gridRect.left, right: gridRect.right, width: gridRect.width },
                        stage: { left: stageRect.left, right: stageRect.right, width: stageRect.width },
                        columns: getComputedStyle(grid).gridTemplateColumns,
                    };
                });

                await page.screenshot({
                    path: path.join(evidenceDir, `${label}-onyx-${kind}-${width}.png`),
                    fullPage: true,
                });
                results.push({ kind, ...geometry });

                assert.ok(
                    geometry.overflow <= 1,
                    `ONYX ${kind} ${width}: page overflows by ${geometry.overflow}px `
                        + `(stage ${geometry.stage.width}px, grid ${geometry.grid.width}px)`
                );
                assert.ok(
                    geometry.grid.left >= -0.5 && geometry.grid.right <= width + 0.5,
                    `ONYX ${kind} ${width}: archive grid is outside the viewport`
                );

                await page.close();
            }
        }
    } finally {
        await browser.close();
    }

    fs.writeFileSync(
        path.join(evidenceDir, `${label}-measurements.json`),
        `${JSON.stringify(results, null, 2)}\n`
    );
    process.stdout.write(`ONYX landing/archive overflow PASS (${widths.join(', ')})\n`);
})().catch((error) => {
    console.error(error.stack || error.message || String(error));
    process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
