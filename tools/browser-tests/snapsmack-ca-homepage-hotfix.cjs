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
          cameraCopyCenter: rect('.camera-blog-copy').top + (rect('.camera-blog-copy').height / 2),
          cameraGridCenter: rect('.camera-grid').top + (rect('.camera-grid').height / 2),
          whyMediaCenter: rect('.why-blog-media').top + (rect('.why-blog-media').height / 2),
          whyCopyCenter: rect('.why-blog-copy-wrap').top + (rect('.why-blog-copy-wrap').height / 2),
          scaleMediaCenter: rect('.scale-media').top + (rect('.scale-media').height / 2),
          scaleCopyCenter: rect('.scale-copy').top + (rect('.scale-copy').height / 2),
          whyHeadingColor: color('#why-blog h2'),
          whyPullColor: color('.why-blog-pull'),
          scaleHeading: document.querySelector('.scale-heading').innerText.trim(),
          scaleCopy: document.querySelector('.scale-heading + p').innerText.trim(),
          scaleCaption: document.querySelector('.scale-shot').getAttribute('data-lightbox-caption'),
          scaleAlt: document.querySelector('.scale-shot img').alt,
          custodianTitles: [...document.querySelectorAll('.custodian-points h3')].map(node => getComputedStyle(node).color),
          dashboardLarge: document.querySelector('.why-blog-shot').getAttribute('data-lightbox'),
          dashboardThumb: document.querySelector('.why-blog-shot img').getAttribute('src'),
          portraitButtons: document.querySelectorAll('.whodat-portrait-button[data-lightbox][data-lightbox-group="co-authors"]').length,
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
      assert.equal(measured.portraitButtons, 5, `${width}: co-author portrait lightbox buttons`);
      assert.equal(measured.custodianTitles.length, 3, `${width}: custodian title count`);
      assert.equal(measured.custodianTitles.every(item => item === 'rgb(245, 10, 10)'), true, `${width}: custodian title colour`);
      if (width >= 1440) {
        assert.ok(Math.abs(measured.cameraCopyCenter - measured.cameraGridCenter) <= 1, `${width}: camera grid is not vertically centred`);
        assert.ok(Math.abs(measured.whyMediaCenter - measured.whyCopyCenter) <= 1, `${width}: dashboard image is not vertically centred`);
        assert.ok(Math.abs(measured.scaleMediaCenter - measured.scaleCopyCenter) <= 1, `${width}: fleet image is not vertically centred`);
      }

      for (const selector of ['.door-shot-thumb', '.camera-card button', '.why-blog-shot', '.scale-shot']) {
        const target = page.locator(selector).first();
        await target.hover();
        const outline = await target.evaluate(node => getComputedStyle(node).outlineColor);
        assert.equal(outline, 'rgb(212, 0, 0)', `${width}: ${selector} hover outline`);
      }

      const portrait = page.locator('.whodat-portrait-button').first();
      await portrait.hover();
      assert.equal(await portrait.evaluate(node => getComputedStyle(node).outlineColor), 'rgb(212, 0, 0)', `${width}: portrait hover outline`);
      await portrait.focus();
      await portrait.press('Enter');
      assert.equal(await page.locator('#ss-lightbox').getAttribute('hidden'), null, `${width}: Enter opens portrait lightbox`);
      assert.equal(await page.locator('.ss-lightbox-caption').textContent(), 'Sean McCormick', `${width}: portrait caption`);
      await page.keyboard.press('Escape');
      assert.notEqual(await page.locator('#ss-lightbox').getAttribute('hidden'), null, `${width}: Escape closes portrait lightbox`);
      assert.equal(await portrait.evaluate(node => document.activeElement === node), true, `${width}: focus returns to portrait opener`);

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
