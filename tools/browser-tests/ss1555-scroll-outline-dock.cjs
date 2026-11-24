/* SNAPSMACK_EOF_HEADER
 * Last non-empty line must be the canonical JavaScript EOF marker below.
 */
/**
 * SS-1555 / SS-1560 — SCROLL dark outline Social Dock proof.
 * Renders the real shared component from SCROLL's real manifest with every
 * supported owner link at the two owner-requested widths.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const evidenceDir = path.join(__dirname, 'evidence', 'ss-1555-scroll-outline-dock');
const sprite = fs.readFileSync(path.join(root, 'assets', 'icons', 'crop-circles.svg'), 'utf8');
const scrollCss = fs.readFileSync(path.join(root, 'skins', 'scroll', 'style.css'), 'utf8');
const engineCss = fs.readFileSync(path.join(root, 'assets', 'css', 'ss-engine-social-dock.css'), 'utf8');
const publicCss = fs.readFileSync(path.join(root, 'assets', 'css', 'public-base.css'), 'utf8');
const platforms = [
  'flickr', 'smugmug', 'instagram', 'facebook', 'youtube', '500px', 'vero',
  'threads', 'mastodon', 'bluesky', 'linkedin', 'pinterest', 'tumblr',
  'deviantart', 'behance', 'linktree', 'pixelfed', 'email', 'website',
];
const phpRoot = root.replaceAll('\\', '/').replaceAll("'", "\\'");
const settings = platforms.map((platform) =>
  `'social_dock_${platform}' => '${platform === 'email' ? 'owner@example.test' : `https://example.test/${platform}`}'`
).join(',');
let dock = execFileSync('php', ['-r', `
define('BASE_URL', 'https://example.test/');
require '${phpRoot}/core/trusted-html.php';
require '${phpRoot}/core/skin-render-helpers.php';
require '${phpRoot}/core/indieweb.php';
require '${phpRoot}/core/skin-view-model.php';
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
$manifest = json_decode(file_get_contents('${phpRoot}/skins/scroll/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$crop = snapsmack_prepare_crop_circles($pdo, ['social_dock_enabled' => '1', ${settings}], $manifest, []);
$model = snapsmack_social_dock_model($crop, $manifest);
echo (string)snap_render_component('social-dock', ['model' => $model]);
`], { encoding: 'utf8' });
dock = dock.replace(/https:\/\/example\.test\/assets\/icons\/crop-circles\.svg#/g, '#');

const hiddenSprite = sprite.replace('<svg xmlns="http://www.w3.org/2000/svg">',
  '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">');
const html = `<!doctype html><html><head><meta charset="utf-8"><style>
${publicCss}\n${engineCss}\n${scrollCss}
body{margin:0;background:#fff}.proof{min-height:420px;padding:72px 32px;display:grid;place-items:center}
.proof-copy{font:600 20px system-ui;color:#1a1a1a;margin:0 0 28px;text-align:center}
.proof .social-dock-inline{max-width:960px;flex-wrap:wrap;justify-content:center}
</style></head><body>${hiddenSprite}<main class="proof"><section><h1 class="proof-copy">SCROLL — dark outline Social Dock</h1>${dock}</section></main></body></html>`;

(async () => {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const measurements = [];
  try {
    for (const width of [1920, 1024]) {
      const page = await browser.newPage({ viewport: { width, height: 700 } });
      await page.setContent(html, { waitUntil: 'load' });
      const result = await page.evaluate(() => ({
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        visibleText: [...document.querySelectorAll('.dock-link .platform-name')].filter((label) => {
          const rect = label.getBoundingClientRect();
          const style = getComputedStyle(label);
          return style.position !== 'absolute' || rect.width > 1 || rect.height > 1;
        }).map((label) => label.textContent.trim()),
        links: [...document.querySelectorAll('.dock-link')].map((link) => {
          const style = getComputedStyle(link);
          const use = link.querySelector('use');
          const box = use.getBBox();
          const symbol = document.getElementById(use.getAttribute('href').slice(1));
          return {
            glyph: use.getAttribute('href').slice(1),
            color: style.color,
            background: style.backgroundColor,
            border: style.borderColor,
            paintedWidth: box.width,
            paintedHeight: box.height,
            filledShapes: symbol.querySelectorAll('[fill="currentColor"]').length,
          };
        }),
      }));
      assert(result.overflow <= 0, `${width}: no horizontal overflow`);
      assert.equal(result.links.length, platforms.length, `${width}: all 19 platforms render`);
      assert.deepEqual(result.visibleText, [], `${width}: no platform label is visible as raw text`);
      for (const link of result.links) {
        assert.equal(link.color, 'rgb(26, 26, 26)', `${width}: ${link.glyph} is dark`);
        assert.equal(link.border, 'rgba(26, 26, 26, 0.3)', `${width}: ${link.glyph} has the dark outline ring`);
        assert.equal(link.filledShapes, 0, `${width}: ${link.glyph} uses outline artwork`);
        assert(link.paintedWidth > 0 && link.paintedHeight > 0, `${width}: ${link.glyph} paints non-zero geometry`);
      }
      measurements.push({ width, ...result });
      await page.screenshot({ path: path.join(evidenceDir, `scroll-outline-${width}.png`), fullPage: true });
      await page.close();
    }
    fs.writeFileSync(path.join(evidenceDir, 'measurements.json'), `${JSON.stringify(measurements, null, 2)}\n`);
    console.log('PASS: SCROLL renders all 19 dark outline glyphs with no raw text at 1920/1024.');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
