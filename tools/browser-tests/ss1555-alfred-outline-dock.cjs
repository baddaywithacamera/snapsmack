/* SNAPSMACK_EOF_HEADER
 * Last non-empty line must be the canonical JavaScript EOF marker below.
 */
/**
 * SS-1555 / SS-1560 — ALFRED white-ring dock and one outline glyph family.
 * Renders the real shared component from ALFRED's real manifest at the two
 * owner-requested widths. The sprite is embedded only so file:// evidence can
 * paint the same symbols without a web server.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const evidenceDir = path.join(__dirname, 'evidence', 'ss-1555-social-dock-outline');
const sprite = fs.readFileSync(path.join(root, 'assets', 'icons', 'crop-circles.svg'), 'utf8');
const alfredCss = fs.readFileSync(path.join(root, 'skins', 'alfred', 'style.css'), 'utf8');
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
$manifest = json_decode(file_get_contents('${phpRoot}/skins/alfred/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$crop = snapsmack_prepare_crop_circles($pdo, ['social_dock_enabled' => '1', ${settings}], $manifest, []);
$model = snapsmack_social_dock_model($crop, $manifest);
echo (string)snap_render_component('social-dock', ['model' => $model]);
`], { encoding: 'utf8' });
dock = dock.replace(/https:\/\/example\.test\/assets\/icons\/crop-circles\.svg#/g, '#');

const hiddenSprite = sprite.replace('<svg xmlns="http://www.w3.org/2000/svg">',
  '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">');
const html = `<!doctype html><html><head><meta charset="utf-8"><style>
${publicCss}\n${engineCss}\n${alfredCss}
body{margin:0;background:#1d1d1d}.proof-title{color:#fff;font:20px system-ui;text-align:center;margin:0 0 24px}
.site-header{min-height:360px;padding:70px 24px;background:#1d1d1d}.site-header .inside{display:grid;place-items:center}
#system-footer{min-height:220px;padding:50px 24px;background:#171717}
</style></head><body class="alfred-v2">${hiddenSprite}
<header class="site-header"><div class="inside"><h1 class="proof-title">ALFRED header — white outline Social Dock</h1>${dock}</div></header>
<footer id="system-footer"><div id="footer" class="inside"><h2 class="proof-title">ALFRED footer</h2>${dock}</div></footer>
</body></html>`;

(async () => {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const measurements = [];
  try {
    for (const width of [1920, 1024]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.setContent(html, { waitUntil: 'load' });
      const result = await page.evaluate(() => ({
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        links: [...document.querySelectorAll('.dock-link')].map((link) => {
          const style = getComputedStyle(link);
          const rect = link.getBoundingClientRect();
          const use = link.querySelector('use');
          const box = use.getBBox();
          return {
            section: link.closest('header') ? 'header' : 'footer',
            glyph: use.getAttribute('href').slice(1),
            width: rect.width, height: rect.height,
            background: style.backgroundColor, border: style.borderColor, color: style.color,
            paintedWidth: box.width, paintedHeight: box.height,
          };
        }),
      }));
      assert.equal(result.overflow, 0, `${width}: no horizontal overflow`);
      assert.equal(result.links.length, platforms.length * 2, `${width}: both placements render all platforms`);
      for (const link of result.links) {
        assert.equal(link.background, 'rgba(0, 0, 0, 0)', `${width}: ${link.glyph} ring interior is transparent`);
        assert.equal(link.border, 'rgb(255, 255, 255)', `${width}: ${link.glyph} ring is white`);
        assert.equal(link.color, 'rgb(255, 255, 255)', `${width}: ${link.glyph} outline is white`);
        assert(link.paintedWidth > 0 && link.paintedHeight > 0, `${width}: ${link.glyph} paints non-zero geometry`);
        assert.equal(link.width, link.section === 'header' ? 36 : 48, `${width}: ${link.glyph} uses approved ${link.section} size`);
      }
      measurements.push({ width, ...result });
      await page.screenshot({ path: path.join(evidenceDir, `alfred-outline-${width}.png`), fullPage: true });
      await page.close();
    }
    fs.writeFileSync(path.join(evidenceDir, 'measurements.json'), `${JSON.stringify(measurements, null, 2)}\n`);
    console.log('PASS: ALFRED white transparent rings and all 19 white outline glyphs at 1920/1024.');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
