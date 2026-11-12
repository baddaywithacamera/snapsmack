// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const baseUrl = process.env.SNAPSMACK_CA_URL || 'http://127.0.0.1:8765';
const output = process.env.SNAPSMACK_BROWSER_OUTPUT || path.join(
  root,
  'tools/browser-tests/evidence/snapsmack-ca-public-banner-preview'
);

const publicPages = [
  'index.php', 'features.php', 'skins.php', 'tools.php', 'security.php',
  'wotcha.php', 'brass-tacks.php', 'coming-soon.php', 'oi.php', 'tnb.php',
  'hairy-muff.php', 'buzzers.php', 'ding-dong-bell.php', 'the-reckoning.php',
  'hows-yer-father.php', 'bugger.php',
  'faq-why.php', 'faq-running.php', 'faq-content.php', 'faq-tools.php',
  'faq-fediverse.php', 'faq-security.php', 'faq-future.php',
  'tool-cold-snap.php', 'tool-flkr-fckr.php', 'tool-gyss.php',
  'tool-smackpress.php', 'tool-snap-hq.php', 'tool-snap-slapper.php',
  'tool-suyb.php', 'tool-sybu.php', 'tool-unzucker.php',
  'export-your-photos.php', 'fediverse-photography.php',
  'flickr-alternative.php', 'instagram-alternative.php',
  'photo-blog-software.php', 'self-hosted-photography.php',
];

const samples = [
  'index.php',
  'security.php',
  'wotcha.php',
  'faq-running.php',
  'tool-snap-slapper.php',
  'photo-blog-software.php',
];

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];

  try {
    for (const width of [1440, 390]) {
      const context = await browser.newContext({ viewport: { width, height: 1000 } });
      const page = await context.newPage();

      for (const pageName of publicPages) {
        const response = await page.goto(`${baseUrl}/${pageName}`, { waitUntil: 'networkidle' });
        assert.equal(response.status(), 200, `${pageName} must return HTTP 200`);

        const measured = await page.evaluate(() => {
          const banner = document.querySelector('.beta-delay');
          const header = document.querySelector('#site-header');
          const bannerBox = banner?.getBoundingClientRect();
          const headerBox = header?.getBoundingClientRect();
          return {
            bannerCount: document.querySelectorAll('.beta-delay').length,
            bannerText: banner?.textContent.replace(/\s+/g, ' ').trim() || '',
            bannerTop: bannerBox?.top ?? null,
            headerBottom: headerBox?.bottom ?? null,
            bannerOverflow: banner ? banner.scrollWidth - banner.clientWidth : null,
            horizontalOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            overflowingElements: [...document.body.querySelectorAll('*')]
              .map((element) => {
                const box = element.getBoundingClientRect();
                return {
                  tag: element.tagName.toLowerCase(),
                  id: element.id,
                  className: typeof element.className === 'string' ? element.className : '',
                  left: box.left,
                  right: box.right,
                  width: box.width,
                };
              })
              .filter((item) => item.right > document.documentElement.clientWidth + 0.5 || item.left < -0.5)
              .slice(0, 12),
          };
        });

        assert.equal(measured.bannerCount, 1, `${pageName} must render one shared banner`);
        assert.equal(
          measured.bannerText,
          'Closed beta is running late. It opens when the software is ready. Read why →',
          `${pageName} banner wording must remain exact`
        );
        assert.ok(measured.bannerOverflow <= 0, `${pageName} banner must not overflow at ${width}px`);
        if (samples.includes(pageName)) {
          assert.ok(measured.horizontalOverflow <= 0, `${pageName} sample must not overflow at ${width}px`);
        }
        assert.ok(
          Math.abs(measured.bannerTop - measured.headerBottom) <= 0.5,
          `${pageName} banner must begin immediately after the shared header at ${width}px`
        );

        if (width === 390 && samples.includes(pageName)) {
          const toggle = page.locator('label[for="ss-nav-main"]');
          await toggle.click();
          assert.equal(await page.locator('#site-header nav').isVisible(), true, `${pageName} mobile menu must open`);
          await toggle.click();
        }

        if (samples.includes(pageName)) {
          await page.screenshot({
            path: path.join(output, `${path.parse(pageName).name}-${width}.png`),
            fullPage: false,
          });
        }

        results.push({ page: pageName, width, ...measured });
      }

      await page.goto(`${baseUrl}/wotcha.php`, { waitUntil: 'networkidle' });
      assert.equal(
        await page.locator('.beta-delay-link').getAttribute('href'),
        'wotcha.php#closed-beta-delay',
        'WOTCHA banner must keep its same-page article anchor'
      );

      await page.goto(`${baseUrl}/index.php`, { waitUntil: 'networkidle' });
      const cards = page.locator('.whodat-card');
      assert.equal(await cards.count(), 4, 'Home page must render four credit cards');
      await page.locator('#whodat').scrollIntoViewIfNeeded();
      await page.waitForFunction(() => [...document.querySelectorAll('#whodat img')].every((image) => image.complete && image.naturalWidth > 0));
      const creditGeometry = await page.locator('#whodat').evaluate((section) => {
        const cards = [...section.querySelectorAll('.whodat-card')];
        const rects = cards.map((card) => {
          const box = card.getBoundingClientRect();
          return { left: box.left, top: box.top, width: box.width, height: box.height };
        });
        return {
          rects,
          horizontalOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
          placeholderText: section.querySelector('.whodat-placeholder')?.textContent.replace(/\s+/g, ' ').trim() || '',
        };
      });
      assert.equal(creditGeometry.placeholderText, 'MUSE Portrait placeholder');
      assert.ok(creditGeometry.horizontalOverflow <= 0, `Credits must not overflow at ${width}px`);
      await page.locator('#whodat').screenshot({ path: path.join(output, `credits-${width}.png`) });
      results.push({ page: 'index.php#whodat', width, ...creditGeometry });

      await context.close();
    }
  } finally {
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(results, null, 2));
    await browser.close();
  }

  console.log(`PASS ${publicPages.length} public pages at 1440px and 390px; shared banner, mobile menus, WOTCHA anchor, overflow and four-card credits verified.`);
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
