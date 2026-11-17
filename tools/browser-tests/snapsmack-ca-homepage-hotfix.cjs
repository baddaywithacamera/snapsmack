#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const baseUrl = process.env.SNAPSMACK_CA_BASE_URL || 'http://127.0.0.1:8879';
const widths = [1920, 1440, 1024];

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    for (const width of widths) {
      const page = await browser.newPage({ viewport: { width, height: 1000 } });
      await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });

      const measured = await page.evaluate(() => {
        const rect = selector => document.querySelector(selector).getBoundingClientRect();
        const color = selector => getComputedStyle(document.querySelector(selector)).color;
        const background = selector => getComputedStyle(document.querySelector(selector)).backgroundColor;
        return {
          overflow: document.documentElement.scrollWidth > innerWidth,
          runningLateBackground: background('#running-late'),
          runningLateColor: color('#running-late'),
          cameraCopyTop: rect('.camera-blog-copy').top,
          cameraGridTop: rect('.camera-grid').top,
          whyHeadingColor: color('#why-blog h2'),
          whyPullColor: color('.why-blog-pull'),
          scaleHeading: document.querySelector('.scale-heading').innerText.trim(),
          scaleCopy: document.querySelector('.scale-heading + p').innerText.trim(),
          scaleCaption: document.querySelector('.scale-shot').getAttribute('data-lightbox-caption'),
          scaleAlt: document.querySelector('.scale-shot img').alt,
          custodianTitles: [...document.querySelectorAll('.custodian-points h3')].map(node => getComputedStyle(node).color),
          dashboardLarge: document.querySelector('.why-blog-shot').getAttribute('data-lightbox'),
          dashboardThumb: document.querySelector('.why-blog-shot img').getAttribute('src'),
        };
      });

      assert.equal(measured.overflow, false, `${width}: horizontal overflow`);
      assert.equal(measured.runningLateBackground, 'rgb(64, 67, 72)', `${width}: running-late grey`);
      assert.equal(measured.runningLateColor, 'rgb(255, 255, 255)', `${width}: running-late text`);
      assert.equal(measured.whyHeadingColor, 'rgb(212, 0, 0)', `${width}: WHY A BLOG heading`);
      assert.equal(measured.whyPullColor, 'rgb(212, 0, 0)', `${width}: WHY A BLOG closer`);
      assert.match(measured.scaleHeading, /^YES, BUT DOES IT SCALE\?\s+Bitch, please\./, `${width}: scale heading`);
      assert.match(measured.scaleCopy, /^28 sites\. 1 hub\. 27 spokes\./, `${width}: scale copy`);
      assert.equal(measured.scaleCaption, '1 hub, 27 spokes, one dashboard', `${width}: scale caption`);
      assert.equal(measured.scaleAlt, '1 hub, 27 spokes, one dashboard', `${width}: scale alt`);
      assert.equal(measured.dashboardLarge, 'img/admin-dashboard-large.png', `${width}: dashboard large image`);
      assert.equal(measured.dashboardThumb, 'img/admin-dashboard-thumb.png', `${width}: dashboard thumbnail`);
      assert.equal(measured.custodianTitles.length, 3, `${width}: custodian title count`);
      assert.equal(measured.custodianTitles.every(item => item === 'rgb(245, 10, 10)'), true, `${width}: custodian title colour`);
      if (width >= 1440) {
        assert.ok(Math.abs(measured.cameraCopyTop - measured.cameraGridTop) <= 1, `${width}: camera copy and grid tops differ`);
      }

      for (const selector of ['.door-shot-thumb', '.camera-card button', '.why-blog-shot', '.scale-shot']) {
        const target = page.locator(selector).first();
        await target.hover();
        const outline = await target.evaluate(node => getComputedStyle(node).outlineColor);
        assert.equal(outline, 'rgb(212, 0, 0)', `${width}: ${selector} hover outline`);
      }

      await page.close();
    }
    console.log(`PASS: homepage hotfix behavior at ${widths.join('/')}px.`);
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
