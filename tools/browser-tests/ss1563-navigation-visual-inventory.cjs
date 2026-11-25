// SNAPSMACK_EOF_HEADER
// Render the five skins that style current navigation, at supported widths.
const { chromium } = require('./runtime.cjs');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const output = path.join(root, '_review', 'ss1563-navigation-current');
const widths = [1920, 1440, 1024];
const states = ['current', 'non-current', 'child', 'no-match'];
const skins = {
  alfred: { wrapper: 'navigation', list: 'main-menu' },
  telegram: { wrapper: 'navigation', list: 'main-menu' },
  tilez: { wrapper: 'navigation', list: 'main-menu' },
  onyx: { wrapper: 'navigation', list: 'nav-menu' },
  heuristic: { wrapper: 'he-sticky-nav', list: 'he-sticky-nav-links' },
};

function menu(state) {
  const homeCurrent = state === 'non-current';
  const archiveCurrent = state === 'current';
  const childCurrent = state === 'child';
  const parentClass = childCurrent ? 'current-menu-ancestor nav-has-children' : 'nav-has-children';
  return `<li${homeCurrent ? ' class="current-menu-item"' : ''}><a data-item="home" href="/"${homeCurrent ? ' aria-current="page"' : ''}>Home</a></li>
    <li${archiveCurrent ? ' class="current-menu-item"' : ''}><a data-item="archive" href="/archive"${archiveCurrent ? ' aria-current="page"' : ''}>Archive</a></li>
    <li class="${parentClass}"><a data-item="pages" href="/pages">Pages</a>
      <ul class="nav-submenu">
        <li${childCurrent ? ' class="current-menu-item"' : ''}><a data-item="about" href="/about"${childCurrent ? ' aria-current="page"' : ''}>About</a></li>
        <li><a data-item="contact" href="/contact">Contact</a></li>
      </ul>
    </li>`;
}

function pageHtml(slug, config, state) {
  const css = fs.readFileSync(path.join(root, 'skins', slug, 'style.css'), 'utf8');
  return `<!doctype html><html><head><meta charset="utf-8"><title>${slug} ${state}</title>
    <style>${css}</style><style>
      html,body{min-height:100%;margin:0} body{padding-top:48px}
      .ss1563-label{font:700 16px/1.4 system-ui,sans-serif;padding:18px 5vw;background:#eee;color:#111}
      .ss1563-stage{min-height:480px;padding:40px 5vw;background:#d9d9d9;color:#111;font:18px/1.5 system-ui,sans-serif}
      .${config.wrapper}{position:relative!important;display:block!important;visibility:visible!important;opacity:1!important}
      .${config.list}{display:flex!important;visibility:visible!important;opacity:1!important;gap:28px;align-items:flex-start;list-style:none;margin:0 auto;padding:20px 5vw;max-width:none!important}
      .${config.list}>li{display:block!important;position:relative!important}
      .${config.list} .nav-submenu{display:block!important;visibility:visible!important;opacity:1!important;position:static!important;list-style:none;margin:10px 0 0;padding:10px;background:rgba(127,127,127,.18)}
      .${config.list} a{display:inline-block!important}
    </style></head><body class="${slug} ${slug}-v2">
    <div class="ss1563-label">SS-1563 · ${slug.toUpperCase()} · ${state}</div>
    <nav class="${config.wrapper}" aria-label="Site navigation"><ul class="${config.list}">${menu(state)}</ul></nav>
    <main class="ss1563-stage"><h1>Navigation current-item visual inventory</h1><p>Rendered at a supported desktop/tablet width using the skin stylesheet and the CMS navigation classes.</p></main>
    </body></html>`;
}

function visibleStyle(style) {
  return {
    color: style.color,
    backgroundColor: style.backgroundColor,
    borderBottomColor: style.borderBottomColor,
    borderBottomWidth: style.borderBottomWidth,
    opacity: style.opacity,
    textShadow: style.textShadow,
    textDecorationLine: style.textDecorationLine,
    fontWeight: style.fontWeight,
  };
}

(async () => {
  fs.rmSync(output, { recursive: true, force: true });
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const [slug, config] of Object.entries(skins)) {
      for (const width of widths) {
        for (const state of states) {
          const page = await browser.newPage({ viewport: { width, height: 760 } });
          await page.setContent(pageHtml(slug, config, state), { waitUntil: 'load' });
          const measured = await page.evaluate(() => {
            const links = Object.fromEntries([...document.querySelectorAll('[data-item]')].map((link) => {
              const rect = link.getBoundingClientRect();
              return [link.dataset.item, {
                markerClass: link.parentElement.classList.contains('current-menu-item'),
                ancestorClass: link.parentElement.classList.contains('current-menu-ancestor'),
                ariaCurrent: link.getAttribute('aria-current'),
                visible: rect.width > 0 && rect.height > 0,
                rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
                style: {
                  color: getComputedStyle(link).color,
                  backgroundColor: getComputedStyle(link).backgroundColor,
                  borderBottomColor: getComputedStyle(link).borderBottomColor,
                  borderBottomWidth: getComputedStyle(link).borderBottomWidth,
                  opacity: getComputedStyle(link).opacity,
                  textShadow: getComputedStyle(link).textShadow,
                  textDecorationLine: getComputedStyle(link).textDecorationLine,
                  fontWeight: getComputedStyle(link).fontWeight,
                },
              }];
            }));
            return { links, currentCount: document.querySelectorAll('[aria-current="page"]').length };
          });
          const links = measured.links;
          for (const value of Object.values(links)) assert(value.visible, `${slug} ${width} ${state}: every inventory link must be visible`);
          if (state === 'current') {
            assert.equal(measured.currentCount, 1, `${slug} ${width} ${state}: exactly one current link`);
            assert(links.archive.markerClass && links.archive.ariaCurrent === 'page', `${slug} ${width} ${state}: Archive marked`);
            assert(!links.home.markerClass && links.home.ariaCurrent === null, `${slug} ${width} ${state}: Home remains non-current`);
            assert.notDeepEqual(visibleStyle(links.archive.style), visibleStyle(links.home.style), `${slug} ${width} ${state}: current state must be visibly different`);
          } else if (state === 'non-current') {
            assert.equal(measured.currentCount, 1, `${slug} ${width} ${state}: exactly one different current link`);
            assert(links.home.markerClass && links.home.ariaCurrent === 'page', `${slug} ${width} ${state}: Home marked`);
            assert(!links.archive.markerClass && links.archive.ariaCurrent === null, `${slug} ${width} ${state}: Archive remains non-current`);
            assert.notDeepEqual(visibleStyle(links.home.style), visibleStyle(links.archive.style), `${slug} ${width} ${state}: non-current state must be visibly different`);
          } else if (state === 'child') {
            assert.equal(measured.currentCount, 1, `${slug} ${width} child: exactly one current link`);
            assert(links.about.markerClass && links.about.ariaCurrent === 'page', `${slug} ${width}: child marked current`);
            assert(links.pages.ancestorClass && links.pages.ariaCurrent === null, `${slug} ${width}: parent is ancestor only`);
            assert(!links.contact.markerClass && links.contact.ariaCurrent === null, `${slug} ${width}: sibling remains non-current`);
            assert.notDeepEqual(visibleStyle(links.about.style), visibleStyle(links.contact.style), `${slug} ${width}: child current state must be visibly different`);
          } else {
            assert.equal(measured.currentCount, 0, `${slug} ${width} no-match: no current link`);
            assert(Object.values(links).every((link) => !link.markerClass && link.ariaCurrent === null), `${slug} ${width} no-match: no stale marker`);
          }
          const file = `${slug}-${width}-${state}.png`;
          await page.screenshot({ path: path.join(output, file), fullPage: true });
          results.push({ skin: slug, width, state, screenshot: file, ...measured });
          await page.close();
          console.log(`PASS ${slug} ${width}px ${state}`);
        }
      }
    }
  } finally {
    await browser.close();
  }
  fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ generatedAt: new Date().toISOString(), results }, null, 2));
  console.log(`PASS ${results.length} rendered states; evidence ${output}`);
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
// ===== SNAPSMACK EOF =====
