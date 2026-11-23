/* SNAPSMACK_EOF_HEADER
 * Last non-empty line of this file must be the canonical JavaScript EOF
 * marker used below.
 */
/**
 * SS-1555 — selectable Social Dock glyph families.
 *
 * This is the artwork gate. Core chooses the -outline or -solid suffix; this
 * check proves that every supported social platform has both targets and that
 * Chromium can paint every target rather than merely finding an XML id.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const spritePath = path.join(root, 'assets', 'icons', 'crop-circles.svg');
const evidenceDir = path.join(__dirname, 'evidence', 'ss-1555-social-dock-outline');
const platforms = [
  'flickr', 'smugmug', 'instagram', 'facebook', 'youtube', '500px', 'vero',
  'threads', 'mastodon', 'bluesky', 'linkedin', 'pinterest', 'tumblr',
  'deviantart', 'behance', 'linktree', 'pixelfed', 'email', 'website',
];

(async () => {
  const sprite = fs.readFileSync(spritePath, 'utf8');
  for (const platform of platforms) {
    for (const style of ['outline', 'solid']) {
      assert.match(sprite, new RegExp(`id="${platform}-${style}"`),
        `${platform} must expose a ${style} symbol`);
    }
  }

  fs.mkdirSync(evidenceDir, { recursive: true });
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.setContent(`<!doctype html><style>
      body{margin:24px;background:#fff;color:#171717;font:14px system-ui}
      h1{font-size:18px}.grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px}
      .pair{display:flex;align-items:center;gap:9px;border:1px solid #ccc;padding:9px}
      svg{width:34px;height:34px}.name{min-width:78px}
    </style>${sprite}<h1>SS-1555 Social Dock glyph families</h1><div class="grid">${platforms.map((platform) =>
      `<div class="pair" data-platform="${platform}"><span class="name">${platform}</span>` +
      `<svg data-style="outline"><use href="#${platform}-outline"></use></svg>` +
      `<svg data-style="solid"><use href="#${platform}-solid"></use></svg></div>`
    ).join('')}</div>`);

    const painted = await page.locator('.pair svg').evaluateAll((nodes) => nodes.map((node) => {
      const box = node.querySelector('use').getBBox();
      return { platform: node.closest('.pair').dataset.platform, style: node.dataset.style,
        width: box.width, height: box.height };
    }));
    assert.equal(painted.length, platforms.length * 2);
    for (const glyph of painted) {
      assert(glyph.width > 0 && glyph.height > 0,
        `${glyph.platform}-${glyph.style} must paint non-zero geometry`);
    }

    const blueskyOutline = await page.locator('[data-platform="bluesky"] [data-style="outline"]').screenshot();
    const blueskySolid = await page.locator('[data-platform="bluesky"] [data-style="solid"]').screenshot();
    assert.notDeepEqual(blueskyOutline, blueskySolid,
      'outline and solid families must not collapse to the same Bluesky pixels');
    await page.screenshot({ path: path.join(evidenceDir, 'glyph-families-1440.png'), fullPage: true });
    console.log(`PASS: ${platforms.length} platforms expose painted outline and solid glyph families`);
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
