// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const baseUrl = process.env.SNAPSMACK_CA_URL || 'http://127.0.0.1:8879';
const output = process.env.SNAPSMACK_BROWSER_OUTPUT
  || path.join(root, 'tmp/snapsmack-ca-palette-sweep');
const pages = [
  'index.php', 'features.php', 'skins.php', 'tools.php', 'security.php',
  'wotcha.php', 'brass-tacks.php', 'coming-soon.php', 'oi.php', 'tnb.php',
  'hairy-muff.php', 'buzzers.php', 'ding-dong-bell.php', 'the-reckoning.php',
  'hows-yer-father.php', 'bugger.php', 'faq-content.php', 'faq-fediverse.php',
  'faq-future.php', 'faq-running.php', 'faq-security.php', 'faq-tools.php',
  'faq-why.php', 'export-your-photos.php', 'fediverse-photography.php',
  'flickr-alternative.php', 'instagram-alternative.php',
  'photo-blog-software.php', 'self-hosted-photography.php',
  'tool-cold-snap.php', 'tool-flkr-fckr.php', 'tool-gyss.php',
  'tool-smackpress.php', 'tool-snap-hq.php', 'tool-snap-slapper.php',
  'tool-suyb.php', 'tool-sybu.php', 'tool-unzucker.php',
];

const allowedColours = new Set([
  '212,0,0', '245,10,10', '37,39,42', '48,50,54', '64,67,72',
  '104,107,112', '119,122,127', '213,214,216',
]);

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const results = [];
  try {
    for (const pathname of pages) {
      const page = await context.newPage();
      const response = await page.goto(`${baseUrl}/${pathname}`, { waitUntil: 'networkidle' });
      assert.ok(response && response.ok(), `${pathname}: HTTP ${response ? response.status() : 'no response'}`);
      const measured = await page.evaluate(({ allowed }) => {
        const parse = value => {
          const parts = (value.match(/[\d.]+/g) || []).map(Number);
          return parts.length >= 3 ? [...parts.slice(0, 3), parts.length > 3 ? parts[3] : 1] : null;
        };
        const semanticExceptions = [
          ['.post-tag', '26,127,55'],
          ['.state.watched', '26,122,60'],
          ['.key-value', '200,255,0'],
        ];
        const permitted = (value, node) => {
          const colour = parse(value);
          if (!colour || colour[3] === 0) return true;
          if (colour[0] === colour[1] && colour[1] === colour[2]) return true;
          const opaque = colour[3] === 1 ? colour.slice(0, 3).join(',') : '';
          if (opaque && allowed.includes(opaque)) return true;
          return semanticExceptions.some(([selector, rgb]) => (
            opaque === rgb && node.matches(selector)
          ));
        };
        const label = node => `${node.tagName.toLowerCase()}${node.id ? `#${node.id}` : ''}${
          typeof node.className === 'string' && node.className.trim()
            ? `.${node.className.trim().replace(/\s+/g, '.')}` : ''
        }`;
        const luminance = value => {
          const channels = parse(value).slice(0, 3).map(channel => {
            const normalized = channel / 255;
            return normalized <= 0.03928
              ? normalized / 12.92
              : ((normalized + 0.055) / 1.055) ** 2.4;
          });
          return (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]);
        };
        const contrast = (foreground, background) => {
          const one = luminance(foreground);
          const two = luminance(background);
          return (Math.max(one, two) + 0.05) / (Math.min(one, two) + 0.05);
        };
        const effectiveBackground = node => {
          const layers = [];
          for (let current = node; current; current = current.parentElement) {
            const colour = parse(getComputedStyle(current).backgroundColor);
            if (colour && colour[3] > 0) layers.unshift(colour);
          }
          const result = layers.reduce((beneath, layer) => {
            const alpha = layer[3];
            return [
              (layer[0] * alpha) + (beneath[0] * (1 - alpha)),
              (layer[1] * alpha) + (beneath[1] * (1 - alpha)),
              (layer[2] * alpha) + (beneath[2] * (1 - alpha)),
              1,
            ];
          }, [255, 255, 255, 1]);
          return `rgb(${result.slice(0, 3).map(Math.round).join(', ')})`;
        };
        const palette = [];
        const lowContrast = [];
        for (const node of document.querySelectorAll('body *')) {
          if (node.getClientRects().length === 0) continue;
          const style = getComputedStyle(node);
          const ownsText = [...node.childNodes].some(child => (
            child.nodeType === Node.TEXT_NODE && child.textContent.trim() !== ''
          ));
          const properties = [['backgroundColor', style.backgroundColor]];
          if (ownsText || node.matches('input, select, textarea')) properties.push(['color', style.color]);
          for (const side of ['Top', 'Right', 'Bottom', 'Left']) {
            if (style[`border${side}Style`] !== 'none' && Number.parseFloat(style[`border${side}Width`]) > 0) {
              properties.push([`border${side}Color`, style[`border${side}Color`]]);
            }
          }
          for (const [property, value] of properties) {
            if (!permitted(value, node)) palette.push({ node: label(node), property, value });
          }
          if (ownsText) {
            const background = effectiveBackground(node);
            const ratio = contrast(style.color, background);
            const size = Number.parseFloat(style.fontSize);
            const weight = Number.parseInt(style.fontWeight, 10) || 400;
            const required = size >= 24 || (size >= 18.66 && weight >= 700) ? 3 : 4.5;
            if (ratio + 0.01 < required) {
              lowContrast.push({
                node: label(node), text: node.textContent.trim().slice(0, 100),
                foreground: style.color, background, ratio, required,
              });
            }
          }
        }
        const semantic = [...document.querySelectorAll('[class], [id]')]
          .filter(node => /alert|warning|notice|status|security|coming|callout|error|success/i.test(
            `${node.id} ${typeof node.className === 'string' ? node.className : ''}`,
          ))
          .filter(node => node.getClientRects().length > 0)
          .map(node => ({
            node: label(node), text: node.textContent.trim().replace(/\s+/g, ' ').slice(0, 160),
            color: getComputedStyle(node).color,
            background: getComputedStyle(node).backgroundColor,
            border: getComputedStyle(node).borderColor,
          }));
        return {
          title: document.title,
          palette: [...new Map(palette.map(item => [JSON.stringify(item), item])).values()],
          lowContrast,
          semantic,
          bannerCount: document.querySelectorAll('.beta-delay').length,
          overflow: document.documentElement.scrollWidth > innerWidth,
        };
      }, { allowed: [...allowedColours] });
      await page.screenshot({ path: path.join(output, pathname.replace('.php', '.png')), fullPage: true });
      results.push({ pathname, ...measured });
      await page.close();
    }
    fs.writeFileSync(path.join(output, 'measurements.json'), JSON.stringify({
      baseUrl,
      note: 'Palette, beta-banner, and overflow findings fail the sweep. Contrast findings are diagnostic.',
      results,
    }, null, 2));
    const failures = results.filter(result => (
      result.palette.length || result.bannerCount !== 1 || result.overflow
    ));
    assert.deepEqual(failures, [], `1440 public-page palette failures; see ${output}/measurements.json`);
    const contrastCount = results.reduce((total, result) => total + result.lowContrast.length, 0);
    console.log(`PASS: ${pages.length} public snapsmack.ca pages at 1440px; ${contrastCount} contrast findings recorded. ${output}`);
  } finally {
    await context.close();
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
