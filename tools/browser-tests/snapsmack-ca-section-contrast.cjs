// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./runtime.cjs');

const root = path.resolve(__dirname, '../..');
const baseUrl = process.env.SNAPSMACK_CA_URL || 'http://127.0.0.1:8879';
const output = process.env.SNAPSMACK_BROWSER_OUTPUT
  || path.join(root, 'tmp/snapsmack-ca-section-contrast');
const widths = [1920, 1440, 1024];
const pages = ['index.php', 'brass-tacks.php', 'features.php'];

const interactiveSelector = [
  'a[href]',
  'button:not([aria-hidden="true"])',
  'input:not([type="hidden"]):not([aria-hidden="true"])',
  'select:not([aria-hidden="true"])',
  'textarea:not([aria-hidden="true"])',
  '[tabindex]:not([tabindex="-1"]):not([aria-hidden="true"])',
].join(', ');

const paletteViolations = async (page, state = 'normal', interactiveIndex = null) => page.evaluate(({
  state,
  interactiveIndex,
  interactiveSelector,
}) => {
  const parseColour = value => {
    const parts = (value.match(/[\d.]+/g) || []).map(Number);
    if (parts.length < 3) return null;
    return {
      red: parts[0],
      green: parts[1],
      blue: parts[2],
      alpha: parts.length > 3 ? parts[3] : 1,
    };
  };
  const allowed = value => {
    const colour = parseColour(value);
    if (!colour || colour.alpha === 0) return true;
    const grey = colour.red === colour.green && colour.green === colour.blue;
    if (grey) return true;
    if (colour.alpha !== 1) return false;
    const approved = new Set([
      '212,0,0',
      '245,10,10',
      '37,39,42',
      '48,50,54',
      '64,67,72',
      '104,107,112',
      '119,122,127',
      '213,214,216',
    ]);
    return approved.has(`${colour.red},${colour.green},${colour.blue}`);
  };
  const label = node => {
    const id = node.id ? `#${node.id}` : '';
    const classes = typeof node.className === 'string' && node.className.trim()
      ? `.${node.className.trim().replace(/\s+/g, '.')}`
      : '';
    return `${node.tagName.toLowerCase()}${id}${classes}`;
  };
  const renderedBorders = style => [
    ['borderTopColor', style.borderTopColor, style.borderTopStyle, style.borderTopWidth],
    ['borderRightColor', style.borderRightColor, style.borderRightStyle, style.borderRightWidth],
    ['borderBottomColor', style.borderBottomColor, style.borderBottomStyle, style.borderBottomWidth],
    ['borderLeftColor', style.borderLeftColor, style.borderLeftStyle, style.borderLeftWidth],
  ].filter(([, , borderStyle, width]) => borderStyle !== 'none' && Number.parseFloat(width) > 0);
  const violations = [];
  const interactive = interactiveIndex === null
    ? null
    : document.querySelectorAll(interactiveSelector)[interactiveIndex];
  const nodes = interactive
    ? [interactive, ...interactive.querySelectorAll('*')]
    : [...document.querySelectorAll('body *')];
  for (const node of nodes) {
    if (node.getClientRects().length === 0) continue;
    const style = getComputedStyle(node);
    const ownsText = [...node.childNodes].some(child => (
      child.nodeType === Node.TEXT_NODE && child.textContent.trim() !== ''
    ));
    const properties = [
      ...(ownsText || node.matches('input, select, textarea') ? [['color', style.color]] : []),
      ['backgroundColor', style.backgroundColor],
      ...renderedBorders(style).map(([property, value]) => [property, value]),
    ];
    for (const [property, value] of properties) {
      if (!allowed(value)) violations.push({ state, node: label(node), property, value });
    }
  }
  return violations;
}, { state, interactiveIndex, interactiveSelector });

const interactivePaletteViolations = async page => {
  const violations = [];
  const controls = page.locator(interactiveSelector);
  const count = await controls.count();
  for (let index = 0; index < count; index += 1) {
    const control = controls.nth(index);
    if (!await control.isVisible()) continue;
    await control.hover({ force: true });
    violations.push(...await paletteViolations(page, `hover:${index}`, index));
    await control.focus();
    violations.push(...await paletteViolations(page, `focus:${index}`, index));
  }
  return [...new Map(violations.map(violation => [
    `${violation.node}:${violation.property}:${violation.value}`,
    violation,
  ])).values()];
};

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    for (const width of widths) {
      const context = await browser.newContext({ viewport: { width, height: 1000 } });
      for (const pathname of pages) {
        const page = await context.newPage();
        await page.goto(`${baseUrl}/${pathname}`, { waitUntil: 'networkidle' });
        if (pathname === 'index.php') {
          await page.locator('#built-for-use').scrollIntoViewIfNeeded();
          await page.waitForFunction(() => {
            const images = Array.from(document.querySelectorAll('#built-for-use .receipt-card img'));
            return images.length === 11 && images.every(image => image.complete && image.naturalWidth > 0);
          });
        }

        const measured = await page.evaluate(({ pathname }) => {
          const rgb = value => (value.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
          const luminance = colour => {
            const channels = rgb(colour).map(value => {
              const normalized = value / 255;
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
          const opaqueBackground = node => {
            for (let current = node; current; current = current.parentElement) {
              const value = getComputedStyle(current).backgroundColor;
              if (value !== 'rgba(0, 0, 0, 0)') return value;
            }
            return 'rgb(255, 255, 255)';
          };
          const distance = (one, two) => Math.sqrt(one.reduce((sum, value, index) => {
            return sum + ((value - two[index]) ** 2);
          }, 0));
          const forbidden = new Set([
            'rgb(244, 241, 235)',
            'rgb(255, 245, 243)',
            'rgb(255, 248, 246)',
          ]);
          const backgrounds = [...document.querySelectorAll('*')]
            .map(node => ({
              node,
              value: getComputedStyle(node).backgroundColor,
            }))
            .filter(item => item.value !== 'rgba(0, 0, 0, 0)');
          const beige = backgrounds
            .filter(item => forbidden.has(item.value))
            .map(item => `${item.node.tagName.toLowerCase()}${item.node.id ? `#${item.node.id}` : ''}${item.node.className && typeof item.node.className === 'string' ? `.${item.node.className.trim().replace(/\s+/g, '.')}` : ''}`);
          const banner = document.querySelector('.beta-delay');
          const bands = pathname === 'index.php'
            ? [...document.querySelectorAll('main > section, main > aside')].map(node => ({
                id: node.id || node.className,
                background: getComputedStyle(node).backgroundColor,
                height: node.getBoundingClientRect().height,
              }))
            : [];
          const adjacentDistances = bands.slice(1).map((band, index) => ({
            pair: `${bands[index].id}/${band.id}`,
            distance: distance(rgb(bands[index].background), rgb(band.background)),
          }));
          const whiteImageCardBands = pathname === 'index.php'
            ? [...document.querySelectorAll('main > section, main > aside')]
                .filter(node => node.querySelector('.whodat-portrait'))
                .map(node => ({
                  id: node.id || node.className,
                  background: getComputedStyle(node).backgroundColor,
                  luminance: luminance(getComputedStyle(node).backgroundColor),
                }))
            : [];
          const lowContrast = pathname === 'index.php'
            ? [...document.querySelectorAll('main p, main h1, main h2, main h3, main li, main span, main strong, main em, main cite, main figcaption, main a')]
                .filter(node => node.getClientRects().length > 0)
                .filter(node => [...node.childNodes].some(child => child.nodeType === Node.TEXT_NODE && child.textContent.trim()))
                .map(node => {
                  const style = getComputedStyle(node);
                  const ratio = contrast(style.color, opaqueBackground(node));
                  const size = Number.parseFloat(style.fontSize);
                  const weight = Number.parseInt(style.fontWeight, 10) || 400;
                  const large = size >= 24 || (size >= 18.66 && weight >= 700);
                  return {
                    node: `${node.tagName.toLowerCase()}${node.id ? `#${node.id}` : ''}${node.className && typeof node.className === 'string' ? `.${node.className.trim().replace(/\s+/g, '.')}` : ''}`,
                    text: node.textContent.trim().slice(0, 80),
                    foreground: style.color,
                    background: opaqueBackground(node),
                    ratio,
                    required: large ? 3 : 4.5,
                  };
                })
                .filter(item => item.ratio + 0.01 < item.required)
            : [];
          const paragraphSelectors = [
            '#camera-blog .camera-blog-copy > p:first-child',
            '#why-blog .why-blog-copy > p:first-child',
            '#running-late .running-late-copy > p:first-child',
            '#built-for-use .built-for-use-copy > p:first-child',
            '#respect > .wrap > p:first-of-type',
          ];
          const bodyParagraphs = pathname === 'index.php'
            ? paragraphSelectors.map(selector => {
                const node = document.querySelector(selector);
                const style = node ? getComputedStyle(node) : null;
                return {
                  selector,
                  exists: Boolean(node),
                  fontSize: style ? Number.parseFloat(style.fontSize) : null,
                  lineHeight: style ? Number.parseFloat(style.lineHeight) : null,
                };
              })
            : [];
          const rhythmBlocks = pathname === 'index.php'
            ? ['#why-blog', '#built-for-use'].map(selector => {
                const section = document.querySelector(selector);
                const wrap = section ? section.querySelector(':scope > .wrap') : null;
                const block = wrap
                  ? wrap.querySelector(selector === '#built-for-use'
                    ? ':scope > .built-for-use-layout > .built-for-use-copy-wrap'
                    : ':scope > .why-blog-layout > .why-blog-copy-wrap')
                  : null;
                const wrapStyle = wrap ? getComputedStyle(wrap) : null;
                const wrapRect = wrap ? wrap.getBoundingClientRect() : null;
                const blockRect = block ? block.getBoundingClientRect() : null;
                const contentLeft = wrapRect && wrapStyle
                  ? wrapRect.left + Number.parseFloat(wrapStyle.paddingLeft)
                  : null;
                const contentRight = wrapRect && wrapStyle
                  ? wrapRect.right - Number.parseFloat(wrapStyle.paddingRight)
                  : null;
                return {
                  selector,
                  exists: Boolean(block),
                  contentLeft,
                  contentRight,
                  contentWidth: contentLeft !== null && contentRight !== null ? contentRight - contentLeft : null,
                  blockLeft: blockRect ? blockRect.left : null,
                  blockRight: blockRect ? blockRect.right : null,
                  blockWidth: blockRect ? blockRect.width : null,
                };
              })
            : [];
          const receipts = pathname === 'index.php'
            ? (() => {
                const layout = document.querySelector('#built-for-use .built-for-use-layout');
                const stack = document.querySelector('#built-for-use .receipt-stack');
                const cards = Array.from(document.querySelectorAll('#built-for-use .receipt-card'));
                const copy = document.querySelector('#built-for-use .built-for-use-copy-wrap');
                const aside = document.querySelector('#built-for-use .receipts');
                const copyRect = copy ? copy.getBoundingClientRect() : null;
                const asideRect = aside ? aside.getBoundingClientRect() : null;
                return {
                  count: cards.length,
                  layoutColumns: layout ? getComputedStyle(layout).gridTemplateColumns : '',
                  stackColumns: stack ? getComputedStyle(stack).gridTemplateColumns : '',
                  copyTop: copyRect ? copyRect.top : null,
                  copyLeft: copyRect ? copyRect.left : null,
                  copyRight: copyRect ? copyRect.right : null,
                  asideTop: asideRect ? asideRect.top : null,
                  asideLeft: asideRect ? asideRect.left : null,
                  asideRight: asideRect ? asideRect.right : null,
                  valid: cards.every(card => {
                    const image = card.querySelector('img');
                    return card.target === '_blank'
                      && card.relList.contains('noopener')
                      && !/\b0 images\b/i.test(card.textContent || '')
                      && image
                      && image.loading === 'lazy'
                      && image.width > 0
                      && image.height > 0
                      && image.complete
                      && image.naturalWidth > 0;
                  }),
                };
              })()
            : null;
          return {
            pathname,
            beige,
            bannerCount: document.querySelectorAll('.beta-delay').length,
            bannerDisplay: banner ? getComputedStyle(banner).display : null,
            bands,
            adjacentDistances,
            whiteImageCardBands,
            lowContrast,
            bodyParagraphs,
            rhythmBlocks,
            receipts,
            overflow: document.documentElement.scrollWidth > innerWidth,
          };
        }, { pathname });

        if (pathname === 'index.php') {
          assert.deepEqual(measured.beige, [], `${width} ${pathname}: beige-family background remains`);
          assert.deepEqual(
            await paletteViolations(page),
            [],
            `${width} ${pathname}: normal state uses a colour outside black/white/grey/bright-red`,
          );
          assert.deepEqual(
            await interactivePaletteViolations(page),
            [],
            `${width} ${pathname}: hover/focus state uses a colour outside black/white/grey/bright-red`,
          );
        }
        assert.equal(measured.bannerCount, 1, `${width} ${pathname}: closed-beta banner missing or duplicated`);
        assert.notEqual(measured.bannerDisplay, 'none', `${width} ${pathname}: closed-beta banner hidden`);
        assert.equal(measured.overflow, false, `${width} ${pathname}: horizontal overflow`);
        if (pathname === 'index.php') {
          assert.equal(measured.bands.length, 14, `${width}: unexpected homepage band count`);
          assert.equal(measured.bands.some(band => band.height === 0), false, `${width}: collapsed homepage band`);
          for (const pair of measured.adjacentDistances) {
            assert.ok(pair.distance >= 60, `${width}: adjacent bands ${pair.pair} are too similar (${pair.distance.toFixed(1)})`);
          }
          assert.equal(measured.whiteImageCardBands.length > 0, true, `${width}: white image-card band was not measured`);
          assert.equal(
            measured.whiteImageCardBands.some(band => band.luminance < 0.7),
            false,
            `${width}: a band containing white image cards uses a dark background`,
          );
          assert.deepEqual(measured.lowContrast, [], `${width}: homepage text falls below WCAG AA contrast`);
          assert.equal(
            measured.bodyParagraphs.every(item => item.exists),
            true,
            `${width}: a homepage body-paragraph sample is missing`,
          );
          const fontSizes = new Set(measured.bodyParagraphs.map(item => item.fontSize));
          const lineHeights = new Set(measured.bodyParagraphs.map(item => item.lineHeight));
          assert.equal(fontSizes.size, 1, `${width}: homepage body paragraph font sizes differ`);
          assert.equal(lineHeights.size, 1, `${width}: homepage body paragraph line heights differ`);
          for (const block of measured.rhythmBlocks) {
            assert.equal(block.exists, true, `${width} ${block.selector}: rhythm block is missing`);
            if (block.selector === '#built-for-use') {
              assert.ok(Math.abs(block.blockLeft - block.contentLeft) <= 1, `${width} ${block.selector}: rhythm block is not left-aligned`);
            } else {
              assert.ok(Math.abs(block.blockRight - block.contentRight) <= 1, `${width} ${block.selector}: rhythm block is not right-aligned`);
            }
            const expectedWidth = width <= 1024
              ? block.contentWidth
              : (block.contentWidth - 42) * (2 / 3);
            assert.ok(
              Math.abs(block.blockWidth - expectedWidth) <= 1,
              `${width} ${block.selector}: rhythm block width ${block.blockWidth} != ${expectedWidth}`,
            );
          }
          assert.equal(measured.receipts.count, 11, `${width}: receipt card count differs from the eleven configured live sites`);
          assert.equal(measured.receipts.valid, true, `${width}: receipt link, count, or lazy image is invalid`);
          if (width <= 1024) {
            assert.ok(measured.receipts.asideTop > measured.receipts.copyTop, `${width}: receipts are not below the copy`);
            assert.equal(measured.receipts.stackColumns.split(' ').length, 2, `${width}: receipts are not a two-up grid`);
          } else {
            assert.ok(measured.receipts.asideLeft > measured.receipts.copyRight, `${width}: receipts are not in the right third`);
          }
          for (const band of await page.locator('main > section, main > aside').all()) {
            await band.scrollIntoViewIfNeeded();
          }
          await page.waitForLoadState('networkidle');
          await page.evaluate(() => scrollTo(0, 0));
          await page.screenshot({
            path: path.join(output, `homepage-${width}.png`),
            fullPage: true,
          });
        }
        results.push({ width, ...measured });
        await page.close();
      }
      await context.close();
    }

    fs.writeFileSync(path.join(output, 'measurements.json'), JSON.stringify({
      note: 'Local rendered checks. Live deployment and owner acceptance are not checked.',
      baseUrl,
      results,
    }, null, 2));
    console.log(`PASS: homepage band contrast, beige-family sweep, shared banner, and geometry at ${widths.join('/')}px. ${output}`);
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
