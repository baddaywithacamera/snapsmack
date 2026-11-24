/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 *
 * SS-1555 — read-only live fleet check for Social Dock label geometry.
 * It captures only public homepages and applies the proposed core utility to
 * the browser document; it does not write to any site.
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const rosterSource = fs.readFileSync(
  path.join(root, 'projects', 'snapsmack-ca', 'includes', 'skin-stats.php'),
  'utf8'
);
const fleetBlock = rosterSource.match(/\$_skin_fleet_sources\s*=\s*\[(.*?)\];/s)?.[1] || '';
const sites = Array.from(fleetBlock.matchAll(/'([^']+)'\s*=>\s*'https:\/\/[^']+'/g), (match) => match[1]);
const widths = [1920, 1024];
const evidenceDir = path.join(__dirname, 'evidence', 'ss-1555-live-dock-parity');
fs.mkdirSync(evidenceDir, { recursive: true });

const fixedRule = `
.screen-reader-text {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
}
.screen-reader-text:focus {
  position: static; width: auto; height: auto; margin: 0;
  overflow: visible; clip: auto; white-space: normal;
}`;

const safeName = (value) => value.replace(/[^a-z0-9.-]+/gi, '-').toLowerCase();

async function measure(page) {
  return page.evaluate(() => {
    const styleHref = Array.from(document.styleSheets, (sheet) => sheet.href || '')
      .find((href) => /\/skins\/[^/]+\/style\.css/i.test(href)) || '';
    const skin = styleHref.match(/\/skins\/([^/]+)\/style\.css/i)?.[1] || 'unknown';
    const dock = document.querySelector('.social-dock');
    if (!dock) return { skin, dock: false, links: [] };
    const dockRect = dock.getBoundingClientRect();
    const links = Array.from(dock.querySelectorAll('.dock-link')).map((link) => {
      const linkRect = link.getBoundingClientRect();
      const label = link.querySelector('.screen-reader-text');
      const icon = link.querySelector('svg');
      const labelRect = label?.getBoundingClientRect();
      const labelStyle = label ? getComputedStyle(label) : null;
      const iconRect = icon?.getBoundingClientRect();
      return {
        label: label?.textContent?.trim() || '',
        link: { left: linkRect.left, width: linkRect.width, height: linkRect.height },
        icon: iconRect ? { left: iconRect.left, width: iconRect.width, offset: iconRect.left - linkRect.left } : null,
        hiddenLabel: labelRect && labelStyle ? {
          left: labelRect.left,
          width: labelRect.width,
          height: labelRect.height,
          position: labelStyle.position,
          overflow: labelStyle.overflow,
        } : null,
      };
    });
    return {
      skin,
      dock: true,
      dockRect: { left: dockRect.left, top: dockRect.top, width: dockRect.width, height: dockRect.height },
      links,
      overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
  });
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const site of sites) {
      let hasDock = false;
      for (const width of widths) {
        if (width !== widths[0] && !hasDock) break;
        const page = await browser.newPage({ viewport: { width, height: 1080 } });
        try {
          await page.goto(`https://${site}/`, { waitUntil: 'domcontentloaded', timeout: 25000 });
          await page.waitForTimeout(1200);
          const before = await measure(page);
          hasDock = before.dock && before.links.length > 0;
          if (!hasDock) {
            if (width === widths[0]) results.push({ site, width, before, after: null });
            continue;
          }
          await page.screenshot({
            path: path.join(evidenceDir, `${safeName(site)}-${safeName(before.skin)}-${width}-before.png`),
            fullPage: false,
          });
          await page.addStyleTag({ content: fixedRule });
          const after = await measure(page);
          await page.screenshot({
            path: path.join(evidenceDir, `${safeName(site)}-${safeName(after.skin)}-${width}-after.png`),
            fullPage: false,
          });
          results.push({ site, width, before, after });
        } catch (error) {
          results.push({ site, width, error: String(error?.message || error) });
        } finally {
          await page.close();
        }
      }
    }
  } finally {
    await browser.close();
  }

  fs.writeFileSync(path.join(evidenceDir, 'results.json'), JSON.stringify({ generated_at: new Date().toISOString(), results }, null, 2));
  const dockRows = results.filter((row) => row.after);
  const skins = [...new Set(dockRows.map((row) => row.after.skin))];
  console.log(`Checked ${sites.length} public sites; found ${dockRows.length / widths.length} dock-bearing sites across ${skins.length} skins: ${skins.join(', ')}`);
  for (const row of dockRows) {
    const centred = row.after.links.every((link) => link.icon && link.icon.offset >= 0);
    const labelsHidden = row.after.links.every((link) => link.hiddenLabel
      && link.hiddenLabel.position === 'absolute'
      && link.hiddenLabel.overflow === 'hidden'
      && link.hiddenLabel.width <= 2
      && link.hiddenLabel.height <= 2);
    console.log(`${row.site} | ${row.after.skin} | ${row.width} | links=${row.after.links.length} | icons=${centred ? 'PASS' : 'FAIL'} | labels=${labelsHidden ? 'PASS' : 'FAIL'} | overflow=${row.after.overflow}`);
  }
  if (skins.length < 5) {
    console.warn(`LIMIT: the public fleet currently exposes the dock on only ${skins.length} skin(s); five-skin live parity is not possible without fabricating placement.`);
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
