// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const url = process.env.SNAPSMACK_FAQ_URL
  || 'http://127.0.0.1:8879/brass-tacks.php';
const output = process.env.SNAPSMACK_BROWSER_OUTPUT
  || path.join(root, 'tmp/snapsmack-faq-single-page');
const widths = [1920, 1440, 1024];

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    for (const width of widths) {
      const page = await browser.newPage({ viewport: { width, height: 1000 } });
      await page.goto(url, { waitUntil: 'networkidle' });

      const measured = await page.evaluate(() => {
        const jumpLinks = [...document.querySelectorAll('.faq-jumps a')];
        const qaBlocks = [...document.querySelectorAll('.qa')];
        const sectionGroups = [...document.querySelectorAll('.faq-section-group')];
        const missingTargets = jumpLinks
          .map(link => link.getAttribute('href'))
          .filter(href => !href || !document.querySelector(href));
        const emptyQuestions = qaBlocks.filter(block => {
          const box = block.getBoundingClientRect();
          return box.width === 0 || box.height === 0;
        }).length;
        return {
          qaCount: qaBlocks.length,
          sectionCount: sectionGroups.length,
          jumpCount: jumpLinks.length,
          duplicateIds: [...document.querySelectorAll('[id]')]
            .map(node => node.id)
            .filter((id, index, ids) => ids.indexOf(id) !== index),
          missingTargets,
          emptyQuestions,
          oldFaqLinks: [...document.querySelectorAll('a[href]')]
            .map(link => link.getAttribute('href'))
            .filter(href => /^faq-[^#]*\.php/.test(href || '')),
          hasSecurityCopy: document.body.innerText.includes('As secure as Claude, Codex, Muse and I could make it.'),
          overflow: document.documentElement.scrollWidth > innerWidth,
          scrollWidth: document.documentElement.scrollWidth,
          viewportWidth: innerWidth,
        };
      });

      assert.equal(measured.qaCount, 56, `${width}: expected all 56 questions`);
      assert.equal(measured.sectionCount, 7, `${width}: expected seven grouped sections`);
      assert.equal(measured.jumpCount, 7, `${width}: expected seven jump links`);
      assert.deepEqual(measured.duplicateIds, [], `${width}: duplicate anchors`);
      assert.deepEqual(measured.missingTargets, [], `${width}: jump target missing`);
      assert.equal(measured.emptyQuestions, 0, `${width}: hidden or collapsed question`);
      assert.deepEqual(measured.oldFaqLinks, [], `${width}: old split-page link remains`);
      assert.equal(measured.hasSecurityCopy, true, `${width}: approved security wording missing`);
      assert.equal(measured.overflow, false, `${width}: horizontal overflow`);

      await page.evaluate(() => scrollTo(0, 0));
      await page.screenshot({ path: path.join(output, `faq-top-${width}.png`) });
      await page.locator('#faq-security').scrollIntoViewIfNeeded();
      await page.screenshot({ path: path.join(output, `faq-security-${width}.png`) });
      results.push({ width, ...measured });
      await page.close();
    }

    fs.writeFileSync(path.join(output, 'measurements.json'), JSON.stringify({
      note: 'Local static-site render. Live deployment and owner acceptance are not checked.',
      url,
      results,
    }, null, 2));
    console.log(`PASS: single-page FAQ content, anchors, and geometry at ${widths.join('/')}px. ${output}`);
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
