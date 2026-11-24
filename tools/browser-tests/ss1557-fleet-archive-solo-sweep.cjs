/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 *
 * SS-1557 — read-only public fleet sweep of archive and solo pages.
 * No login, form submission, browser storage mutation, or site write occurs.
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const rosterPath = path.join(root, 'tools', '_build', 'release-visual-sites.txt');
const sites = fs.readFileSync(rosterPath, 'utf8')
  .split(/\r?\n/)
  .map((line) => line.trim())
  .filter((line) => line && !line.startsWith('#'));
if (sites.length !== 17 || new Set(sites).size !== sites.length) {
  throw new Error(`fleet sweep requires 17 unique production-skin sites; found ${sites.length}`);
}
const widths = [1920, 1024];
const evidenceDir = path.join(__dirname, 'evidence', 'ss-1557-fleet-archive-solo-sweep');
fs.mkdirSync(evidenceDir, { recursive: true });

const safeName = (value) => String(value).replace(/[^a-z0-9.-]+/gi, '-').toLowerCase();
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function pageIdentity(page) {
  return page.evaluate(() => {
    const styleHref = Array.from(document.styleSheets, (sheet) => sheet.href || '')
      .find((href) => /\/skins\/[^/]+\/style\.css/i.test(href)) || '';
    return {
      skin: styleHref.match(/\/skins\/([^/]+)\/style\.css/i)?.[1] || 'unknown',
      title: document.title,
    };
  });
}

async function discover(page, site) {
  const home = `https://${site}/`;
  const response = await page.goto(home, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(800);
  const archive = await page.evaluate(() => {
    const links = Array.from(document.querySelectorAll('a[href]'));
    const chosen = links.find((link) => /(^|\s)archive(s)?(\s|$)/i.test(link.textContent || ''))
      || links.find((link) => /\/archive(?:[/?#]|$)/i.test(link.href));
    return chosen?.href || new URL('/archive', location.href).href;
  });
  return { home, archive, homeStatus: response?.status() || 0 };
}

async function candidateSolo(page, origin) {
  return page.evaluate((expectedOrigin) => {
    const bad = /\/(?:archive|about|contact|faq|search|tag|tags|category|album|albums|feed|rss)(?:[/?#]|$)/i;
    const valid = (href) => {
      try {
        const url = new URL(href, location.href);
        return url.origin === expectedOrigin
          && url.pathname !== '/'
          && !bad.test(url.pathname)
          && !/\.(?:jpg|jpeg|png|gif|webp|svg|css|js)$/i.test(url.pathname);
      } catch { return false; }
    };
    const selectors = [
      'main a:has(img)[href]',
      'article a:has(img)[href]',
      '.archive a:has(img)[href]',
      'main article a[href]',
      'main h2 a[href], main h3 a[href]',
      'article a[href]',
    ];
    for (const selector of selectors) {
      const found = Array.from(document.querySelectorAll(selector))
        .find((link) => valid(link.getAttribute('href')));
      if (found) return found.href;
    }
    return '';
  }, origin);
}

async function unavailableReason(page) {
  return page.evaluate(() => {
    const text = (document.body?.innerText || '').replace(/\s+/g, ' ').trim();
    if (/\bunder maintenance\b/i.test(text)) return 'site is under maintenance';
    if (/\bcoming soon\b|\bnew site being built\b/i.test(text) && text.length < 500) return 'site is a coming-soon page';
    if (/\bwhoopsie doodle\b/i.test(`${document.title} ${text}`)) return 'site is serving its public error page';
    return '';
  });
}

async function measure(page, pageType) {
  return page.evaluate((kind) => {
    const visible = (element) => {
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden'
        && Number(style.opacity || 1) > 0 && rect.width > 1 && rect.height > 1;
    };
    const selectors = [
      'main > article', 'main article', '.archive-item', '.archive-grid > *',
      '.photo-grid > *', '.posts > article', '.post-grid > *', '.post', '[class*="tile"]',
    ];
    let itemSelector = '';
    let items = [];
    for (const selector of selectors) {
      const candidates = Array.from(document.querySelectorAll(selector)).filter(visible);
      if (candidates.length > items.length) {
        itemSelector = selector;
        items = candidates;
      }
    }
    const overlaps = [];
    for (let first = 0; first < Math.min(items.length, 80); first += 1) {
      for (let second = first + 1; second < Math.min(items.length, 80); second += 1) {
        const a = items[first];
        const b = items[second];
        if (a.contains(b) || b.contains(a)) continue;
        const ar = a.getBoundingClientRect();
        const br = b.getBoundingClientRect();
        const width = Math.min(ar.right, br.right) - Math.max(ar.left, br.left);
        const height = Math.min(ar.bottom, br.bottom) - Math.max(ar.top, br.top);
        if (width > 12 && height > 12) overlaps.push({ first, second, width, height });
      }
    }
    const brokenImageDetails = Array.from(document.images)
      .filter((image) => {
        const source = image.currentSrc || image.getAttribute('src') || '';
        const pendingLazySource = image.getAttribute('data-src') || image.getAttribute('data-lazy-src');
        return source && !pendingLazySource && image.complete && image.naturalWidth === 0;
      })
      .map((image) => ({
        src: image.currentSrc || image.getAttribute('src') || '',
        alt: image.getAttribute('alt') || '',
        className: image.className || '',
      }));
    const labels = Array.from(document.querySelectorAll('.social-dock .screen-reader-text'))
      .filter(visible).map((label) => (label.textContent || '').trim()).filter(Boolean);
    const main = document.querySelector('main, [role="main"], #content, .content');
    const skinHref = Array.from(document.styleSheets, (sheet) => sheet.href || '')
      .find((href) => /\/skins\/[^/]+\/style\.css/i.test(href)) || '';
    const skinSlug = skinHref.match(/\/skins\/([^/]+)\/style\.css/i)?.[1] || 'unknown';
    const pagination = Array.from(document.querySelectorAll(
      '.pagination a, .pagination button, .pager a, .pager button, nav[aria-label*="pagination" i] a, nav[aria-label*="pagination" i] button, .next.page-numbers, a[class*="page"][rel="next"]'
    )).some((element) => !element.closest('noscript') && visible(element));
    const hasMoreSentinel = Boolean(document.querySelector('[data-has-more="1"][data-next-url]'));
    let trueGritArchive = null;
    if (kind === 'archive' && skinSlug === 'true-grit') {
      const legacySelects = Array.from(document.querySelectorAll(
        '#infobox select[data-archive-select="1"]'
      )).filter(visible);
      const legacyLabels = Array.from(document.querySelectorAll(
        '#infobox .archive-legacy-filter label'
      )).filter(visible).map((label) => (label.textContent || '').trim());
      const photographs = Array.from(document.querySelectorAll(
        '#browse-grid .tg-tile .tg-thumb img, #browse-grid .tg-tile .thumb-link > img'
      )).filter(visible);
      const photographRatios = photographs.map((image) => {
        const rect = image.getBoundingClientRect();
        return rect.height > 0 ? Number((rect.width / rect.height).toFixed(3)) : 0;
      }).filter((ratio) => ratio > 0);
      const links = Array.from(document.querySelectorAll(
        '#browse-grid .tg-tile .thumb-link'
      )).filter(visible);
      trueGritArchive = {
        legacySelectNames: legacySelects.map((select) => select.getAttribute('name') || ''),
        legacyLabels,
        panelFilterVisible: Array.from(document.querySelectorAll('#smack-archive-filter-btn')).some(visible),
        layoutControlsVisible: Array.from(document.querySelectorAll(
          '#infobox [data-layout], #infobox [data-calendar-toggle]'
        )).filter(visible).length,
        photographRatios,
        twoToOneCount: photographRatios.filter((ratio) => Math.abs(ratio - 2) <= 0.08).length,
        shadowedTileCount: links.filter((link) => {
          const shadow = getComputedStyle(link).boxShadow;
          return shadow && shadow !== 'none' && !/^rgba?\(0, 0, 0, 0\)$/.test(shadow);
        }).length,
        tileCount: links.length,
      };
    }
    return {
      pageType: kind,
      url: location.href,
      horizontalOverflow: Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
      bodyHeight: document.documentElement.scrollHeight,
      itemSelector,
      itemCount: items.length,
      overlaps: overlaps.slice(0, 10),
      imageCount: document.images.length,
      brokenImages: brokenImageDetails.length,
      brokenImageDetails,
      visibleDockLabels: labels,
      hasDock: Boolean(document.querySelector('.social-dock')),
      mainTextLength: (main?.innerText || '').trim().length,
      pagination,
      hasMoreSentinel,
      trueGritArchive,
    };
  }, pageType);
}

function defectsFor(before, after, pageType, status) {
  const defects = [];
  if (!status || status >= 400) defects.push(`HTTP ${status || 'no response'}`);
  if (before.horizontalOverflow > 4) defects.push(`horizontal overflow ${before.horizontalOverflow}px`);
  if (after.brokenImages) defects.push(`${after.brokenImages} broken image(s)`);
  if (before.overlaps.length) defects.push(`${before.overlaps.length} overlapping item pair(s)`);
  if (before.visibleDockLabels.length) defects.push(`raw Social Dock labels: ${before.visibleDockLabels.join(', ')}`);
  if (!before.mainTextLength && !before.imageCount) defects.push('empty main content');
  if (pageType === 'archive') {
    if (!before.itemCount && !before.imageCount) defects.push('archive has no detectable items or images');
    if (before.pagination) defects.push('pagination control visible; archive must use endless scroll');
    if (before.hasMoreSentinel
      && after.itemCount <= before.itemCount
      && after.imageCount <= before.imageCount) defects.push('endless scroll did not append the next archive batch');
    if (before.trueGritArchive) {
      const trueGrit = before.trueGritArchive;
      if (!trueGrit.legacySelectNames.includes('cat')
        || !trueGrit.legacySelectNames.includes('album')
        || !trueGrit.legacyLabels.includes('REGISTRY:')
        || !trueGrit.legacyLabels.includes('ALBUMS:')) {
        defects.push('TRUE GRIT legacy REGISTRY and ALBUMS selects are missing');
      }
      if (trueGrit.panelFilterVisible) defects.push('TRUE GRIT shows the panel FILTER control');
      if (trueGrit.layoutControlsVisible) {
        defects.push(`TRUE GRIT shows ${trueGrit.layoutControlsVisible} T/M/C layout or calendar control(s)`);
      }
      if (trueGrit.photographRatios.length < 4) {
        defects.push('TRUE GRIT archive has fewer than four measurable photographs');
      } else if (trueGrit.twoToOneCount >= Math.ceil(trueGrit.photographRatios.length / 2)) {
        defects.push(`TRUE GRIT forces ${trueGrit.twoToOneCount}/${trueGrit.photographRatios.length} photographs to 2:1`);
      }
      if (trueGrit.tileCount > 0 && trueGrit.shadowedTileCount !== trueGrit.tileCount) {
        defects.push(`TRUE GRIT shadows reach ${trueGrit.shadowedTileCount}/${trueGrit.tileCount} archive tiles`);
      }
    }
  }
  if (pageType === 'solo' && !before.imageCount) defects.push('solo page has no image');
  return defects;
}

async function inspect(browser, site, pageType, url, width) {
  const page = await browser.newPage({ viewport: { width, height: 1080 } });
  const row = { site, page: pageType, width, url, skin: 'unknown', defects: [] };
  try {
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(900);
    const identity = await pageIdentity(page);
    row.skin = identity.skin;
    row.title = identity.title;
    row.status = response?.status() || 0;
    const before = await measure(page, pageType);
    if (pageType === 'archive') {
      for (let pass = 0; pass < 3; pass += 1) {
        await page.evaluate(() => {
          window.scrollTo(0, document.documentElement.scrollHeight);
          for (const element of document.querySelectorAll('*')) {
            const style = getComputedStyle(element);
            if (element.scrollHeight > element.clientHeight + 8 && /(auto|scroll)/.test(style.overflowY)) {
              element.scrollTop = element.scrollHeight;
            }
          }
        });
        await page.waitForTimeout(900);
      }
    }
    const after = await measure(page, pageType);
    if (after.brokenImageDetails.length) {
      const confirmed = [];
      for (const detail of after.brokenImageDetails) {
        try {
          const verification = await page.request.head(detail.src, { timeout: 15000 });
          if (!verification.ok()) confirmed.push({ ...detail, httpStatus: verification.status() });
        } catch {
          confirmed.push({ ...detail, httpStatus: 'no response' });
        }
      }
      after.brokenImageDetails = confirmed;
      after.brokenImages = confirmed.length;
    }
    row.metrics = { before, after };
    row.notChecked = await unavailableReason(page);
    row.defects = row.notChecked ? [] : defectsFor(before, after, pageType, row.status);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(100);
    if (row.defects.length || (row.notChecked && width === 1024)) {
      row.screenshot = `${safeName(site)}-${safeName(identity.skin)}-${pageType}-${width}.png`;
      await page.screenshot({ path: path.join(evidenceDir, row.screenshot), fullPage: false });
    }
  } catch (error) {
    row.defects = [`inspection failed: ${String(error?.message || error).split('\n')[0]}`];
  } finally {
    await page.close();
  }
  return row;
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const rows = [];
  let completed = 0;
  try {
    const inspectSite = async (site) => {
      const siteRows = [];
      const discoveryPage = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
      try {
        const urls = await discover(discoveryPage, site);
        const archiveResponse = await discoveryPage.goto(urls.archive, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await discoveryPage.waitForTimeout(700);
        const archiveUnavailable = await unavailableReason(discoveryPage);
        let solo = archiveUnavailable ? '' : await candidateSolo(discoveryPage, new URL(urls.archive).origin);
        if (!solo && !archiveUnavailable) {
          await discoveryPage.goto(urls.home, { waitUntil: 'domcontentloaded', timeout: 30000 });
          await discoveryPage.waitForTimeout(700);
          solo = await candidateSolo(discoveryPage, new URL(urls.home).origin);
        }
        if (!archiveResponse || archiveResponse.status() >= 400) {
          urls.archive = new URL('/archive', urls.home).href;
        }
        for (const width of widths) siteRows.push(await inspect(browser, site, 'archive', urls.archive, width));
        if (!solo) {
          for (const width of widths) siteRows.push({
            site, page: 'solo', width, url: '', skin: siteRows.at(-1)?.skin || 'unknown',
            notChecked: archiveUnavailable || 'no public solo-page link discovered from archive or home',
            defects: [],
          });
        } else {
          for (const width of widths) siteRows.push(await inspect(browser, site, 'solo', solo, width));
        }
      } catch (error) {
        for (const pageType of ['archive', 'solo']) {
          for (const width of widths) siteRows.push({
            site, page: pageType, width, url: '', skin: 'unknown',
            defects: [`discovery failed: ${String(error?.message || error).split('\n')[0]}`],
          });
        }
      } finally {
        await discoveryPage.close();
      }
      completed += 1;
      console.log(`[${completed}/${sites.length}] ${site}`);
      await wait(100);
      return siteRows;
    };
    const workerCount = 4;
    const workers = Array.from({ length: workerCount }, async (_, worker) => {
      const workerRows = [];
      for (let index = worker; index < sites.length; index += workerCount) {
        workerRows.push(...await inspectSite(sites[index]));
      }
      return workerRows;
    });
    for (const workerRows of await Promise.all(workers)) rows.push(...workerRows);
  } finally {
    await browser.close();
  }

  const receipt = { generated_at: new Date().toISOString(), site_count: sites.length, widths, rows };
  fs.writeFileSync(path.join(evidenceDir, 'results.json'), JSON.stringify(receipt, null, 2));
  const lines = ['| site | skin | page | width | defect |', '|---|---|---:|---:|---|'];
  for (const row of rows) {
    const result = row.notChecked ? `NOT CHECKED — ${row.notChecked}` : (row.defects.length ? row.defects.join('; ') : 'PASS');
    lines.push(`| ${row.site} | ${row.skin} | ${row.page} | ${row.width} | ${result} |`);
  }
  fs.writeFileSync(path.join(evidenceDir, 'results.md'), `${lines.join('\n')}\n`);
  const defects = rows.filter((row) => row.defects.length);
  console.log(`Complete: ${sites.length} sites, ${rows.length} page/width checks, ${defects.length} rows with findings.`);
  for (const row of defects) console.log(`${row.site} | ${row.skin} | ${row.page} | ${row.width} | ${row.defects.join('; ')}`);
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
