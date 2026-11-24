// SNAPSMACK_EOF_HEADER — preserve the canonical EOF marker below.
'use strict';

const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const net = require('net');

const root = path.resolve(__dirname, '..', '..');
const site = path.join(root, 'projects', 'snapsmack-ca');
const evidence = path.join(__dirname, 'evidence', 'snapsmack-ca-publish-batch');
const php = process.env.PHP_BINARY || 'C:\\php\\php.exe';

function check(ok, message) {
  if (!ok) throw new Error(message);
  process.stdout.write(`PASS ${message}\n`);
}

function publicCopyFiles(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const target = path.join(dir, entry.name);
    if (entry.isDirectory()) return publicCopyFiles(target);
    return /\.(?:php|html|js)$/i.test(entry.name) ? [target] : [];
  });
}

async function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const port = server.address().port;
      server.close(() => resolve(port));
    });
  });
}

async function waitForServer(url) {
  for (let attempt = 0; attempt < 80; attempt++) {
    try {
      const response = await fetch(url);
      if (response.ok) return;
    } catch (_) {}
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw new Error(`local PHP server did not answer at ${url}`);
}

(async () => {
  fs.mkdirSync(evidence, { recursive: true });
  const copyWithEmDashes = publicCopyFiles(site).filter((file) =>
    /—|&mdash;|&#8212;|&#x2014;/i.test(fs.readFileSync(file, 'utf8')),
  );
  check(copyWithEmDashes.length === 0, 'all snapsmack.ca page sources are free of em dashes');
  const port = await freePort();
  const server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', site], {
    cwd: site,
    stdio: ['ignore', 'pipe', 'pipe'],
    windowsHide: true,
  });
  let serverError = '';
  server.stderr.on('data', (chunk) => { serverError += String(chunk); });

  const base = `http://127.0.0.1:${port}`;
  let browser;
  try {
    await waitForServer(`${base}/index.php`);
    browser = await chromium.launch({ headless: true });
    for (const width of [1920, 1024]) {
      const page = await browser.newPage({ viewport: { width, height: 1080 } });

      await page.goto(`${base}/index.php`, { waitUntil: 'networkidle' });
      const home = await page.evaluate(() => {
        const main = document.querySelector('.desktop-tools-list:not(.desktop-tools-list--migration)');
        const migration = document.querySelector('.desktop-tools-list--migration');
        const quote = document.querySelector('.desktop-tools-quote');
        return {
          mainCount: main?.querySelectorAll('.desktop-tool').length || 0,
          migrationCount: migration?.querySelectorAll('.desktop-tool').length || 0,
          mainColumns: main ? getComputedStyle(main).gridTemplateColumns.split(' ').length : 0,
          quoteSize: quote ? parseFloat(getComputedStyle(quote).fontSize) : 999,
          screenshot: document.querySelector('.desktop-tools-shot img')?.getAttribute('src') || '',
          featured: document.querySelectorAll('.featured-skin-grid .featured-skin').length,
          overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        };
      });
      check(home.mainCount === 12, `${width}: homepage has 12 main desktop tools (found ${home.mainCount})`);
      check(home.migrationCount === 6, `${width}: homepage has 6 migration tools (found ${home.migrationCount})`);
      check(home.mainColumns === (width === 1920 ? 4 : 2), `${width}: desktop tools use the approved column count`);
      check(home.quoteSize < parseFloat(await page.locator('.desktop-tools-copy').evaluate((node) => getComputedStyle(node).fontSize)), `${width}: quote remains smaller than body copy (${home.quoteSize}px)`);
      check(home.screenshot === 'img/snap-hq-new.png', `${width}: current SNAP HQ image is selected`);
      check(home.featured === 4, `${width}: four live skins are featured`);
      check(!home.overflow, `${width}: homepage has no horizontal overflow`);
      await page.screenshot({ path: path.join(evidence, `homepage-${width}.png`), fullPage: true });

      await page.goto(`${base}/hows-yer-father.php`, { waitUntil: 'networkidle' });
      const family = await page.evaluate(() => {
        const hero = document.querySelector('.page-header .wrap');
        const first = document.querySelector('.family-copy');
        const sections = [...document.querySelectorAll('.family-section')];
        return {
          heroLeft: Math.round(hero?.getBoundingClientRect().left || -1),
          copyLeft: Math.round(first?.getBoundingClientRect().left || -2),
          edgeBars: sections.filter((node) => {
            const pseudo = getComputedStyle(node, '::before');
            return pseudo.content !== 'none' && pseudo.content !== 'normal' && parseFloat(pseudo.width) > 0;
          }).length,
          overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        };
      });
      check(Math.abs(family.heroLeft - family.copyLeft) <= 1, `${width}: family copy aligns with the hero grid`);
      check(family.edgeBars === 0, `${width}: viewport-edge red bars are gone`);
      check(!family.overflow, `${width}: family page has no horizontal overflow`);
      await page.screenshot({ path: path.join(evidence, `hows-yer-father-${width}.png`), fullPage: true });

      await page.goto(`${base}/wotcha.php`, { waitUntil: 'networkidle' });
      const news = await page.evaluate(() => {
        const article = document.querySelector('#november-catch-up');
        const text = article?.textContent || '';
        return {
          indexed: !!document.querySelector('.post-index a[href="#november-catch-up"]'),
          article: !!article,
          ai: /written by Codex, an AI/i.test(text),
          noDate: /still no new closed-beta date/i.test(text),
          overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        };
      });
      check(news.indexed && news.article, `${width}: refreshed catch-up is indexed and rendered`);
      check(news.ai, `${width}: AI authorship is stated plainly`);
      check(news.noDate, `${width}: catch-up promises no beta date`);
      check(!news.overflow, `${width}: WOTCHA has no horizontal overflow`);
      await page.screenshot({ path: path.join(evidence, `wotcha-${width}.png`), fullPage: true });
      await page.close();
    }
  } finally {
    if (browser) await browser.close();
    server.kill();
  }
  if (serverError && /Fatal error|Parse error/i.test(serverError)) throw new Error(serverError);
})().catch((error) => {
  console.error(error.stack || error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
