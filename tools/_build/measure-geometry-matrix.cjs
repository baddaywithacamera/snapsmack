// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====
//
// MEASURE, do not judge. One pass over every skin's rendered pages, recording
// the geometry that Sean has been finding by hand: where the page title sits,
// where the hero sits, where the body column sits, and the space either side
// of each navigation separator.
//
// WHY THIS IS A MEASUREMENT AND NOT YET A GATE. Codex's correction, and it is
// right: skins differ on purpose. A universal rule such as "the hero must be
// as wide as the body text" would report a deliberate full-bleed layout as a
// defect, and a check that calls a design decision a bug is worse than no
// check at all. So this records what each skin DOES. The expectation per skin
// is agreed afterwards, from this table, and only then does it become a gate.
//
// Pages come from tools/_build/render-geometry-fixtures.php, which inlines the
// real resolved stylesheets, so what is measured here is what a skin actually
// receives rather than a fixture stylesheet that never existed.
//
//   node tools/_build/measure-geometry-matrix.cjs [fixture-dir] [out.json]

const path = require('node:path');
const fs = require('node:fs');

const ROOT = path.resolve(__dirname, '..', '..');
// Codex owns tools/browser-tests. runtime.cjs is the seam he published for
// exactly this: resolve the browser from there, never reach into node_modules
// and never edit anything under it.
const { chromium } = require(path.join(ROOT, 'tools', 'browser-tests', 'runtime.cjs'));

const fixtureDir = path.resolve(process.argv[2] || path.join(ROOT, '.geometry-fixtures'));
const outFile = process.argv[3] || path.join(fixtureDir, 'geometry.json');
const shots = !process.argv.includes('--no-shots');

// 1920x1080 first, because that is the viewport the owner's reference captures
// were taken at. Confirmed: every capture is 1920 wide and shows no browser
// chrome, so a pixel in a capture and a pixel measured here are the same
// pixel. Without that, only proportions would be comparable.
//
// WHAT WIDTH IS A DEFECT AT is not the same question as what width a browser
// can be set to. SnapSmack is throwback software. Every skin is built for
// desktop and large tablet except PHOTOGRAM and TELEGRAM, which are the only
// two phone skins. Sean, 2026-10-28, after this harness and a visual review
// had each spent a day reporting phone faults: "all skins are for desktop and
// large tablet except for PHOTOGRAM and TELEGRAM."
//
// Measuring a desktop skin at 390 and printing OVERFLOWS VIEWPORT does not
// find a fault, it invents one. Those inventions became tickets, and one of
// them became two shipped GALLERIA versions repairing a phone layout that no
// skin but two is meant to have.
//
// The manifest already has the right home for this: "incompatible": ["mobile",
// "tablet"]. Only AURORA, JIVE TURKEY and PARADE fill it in, so it cannot be
// the source of truth yet. Until the other 27 declare themselves the two phone
// skins are named here, rather than inferred from a field that is absent in
// 27 of 30 manifests and therefore means nothing.
const MOBILE_SKINS = new Set(['photogram', 'telegram']);

const DESKTOP_WIDTHS = [
  { name: 'reference', width: 1920, height: 1080 },
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'tablet', width: 1024, height: 768 },
];
const PHONE_WIDTHS = [
  { name: 'phone', width: 390, height: 844 },
];

function fail(message) {
  console.error('FAIL ' + message);
  process.exitCode = 1;
}

(async () => {
  const indexFile = path.join(fixtureDir, 'index.json');
  if (!fs.existsSync(indexFile)) {
    fail('no fixtures at ' + fixtureDir + '. Run tools/_build/render-geometry-fixtures.php first.');
    return;
  }
  const index = JSON.parse(fs.readFileSync(indexFile, 'utf8'));
  const pages = index.pages || [];
  if (!pages.length) { fail('fixture index lists no pages'); return; }

  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  let measured = 0;
  let unmeasurable = 0;

  for (const entry of pages) {
    const file = path.join(fixtureDir, entry.file);
    if (!fs.existsSync(file)) {
      // A missing fixture is never a pass. Record it as its own outcome.
      results.push({ ...entry, error: 'fixture file missing' });
      unmeasurable++;
      continue;
    }
    // A phone skin is measured at phone width and a desktop skin is not.
    const WIDTHS = MOBILE_SKINS.has(entry.skin) ? PHONE_WIDTHS : DESKTOP_WIDTHS;
    for (const vp of WIDTHS) {
      const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
      try {
        await page.goto('file://' + file.replace(/\\/g, '/'), { waitUntil: 'load' });
        // Identify the photographs by their exact source, not by naturalWidth.
        // naturalWidth is 0 until a decode completes, so the first run reported
        // "NO PHOTOGRAPHS" on archives that plainly had them -- a checker that
        // cries wolf is the thing this whole exercise is about.
        const srcs = { hero: 'photo-1600x1067.svg', land: 'photo-1200x800.svg', port: 'photo-800x1200.svg' };
        const data = await page.evaluate((srcs) => {
          const box = (el) => {
            if (!el) return null;
            const r = el.getBoundingClientRect();
            return {
              left: Math.round(r.left), right: Math.round(r.right),
              width: Math.round(r.width), height: Math.round(r.height),
              top: Math.round(r.top + window.scrollY),
            };
          };
          const textOf = (el) => (el && el.textContent ? el.textContent.trim() : '');

          const title = [...document.querySelectorAll('h1, h2')]
            .find((e) => textOf(e).includes('About Sean') || textOf(e) === 'Archive') || null;
          const body = [...document.querySelectorAll('p')]
            .find((e) => textOf(e).startsWith('First paragraph')) || null;
          const hero = document.querySelector('#photobox, .page-hero')
            || [...document.querySelectorAll('img')].find((i) => (i.getAttribute('src') || '').endsWith(srcs.hero)) || null;

          // The navigation row, and the gap either side of every separator.
          // Group the links by the navigation they belong to. A page can
          // carry more than one -- a header nav and a footer nav -- and
          // treating them as one row produced gaps like 0,0,0,755,40,40,40:
          // the 755 was the distance between two separate navigations. Every
          // gap reported here is between two items of the SAME navigation.
          const navLinks = [...document.querySelectorAll('a')]
            .filter((a) => ['Home', 'Blogroll', 'About Sean', 'Image Use'].includes(textOf(a)));
          const navs = new Map();
          for (const link of navLinks) {
            const item = link.closest('li') || link;
            const owner = link.closest('nav') || item.parentElement;
            if (!owner) continue;
            if (!navs.has(owner)) navs.set(owner, []);
            navs.get(owner).push(item);
          }
          const gaps = [];
          const gapsByNav = [];
          let navRow = null;
          for (const [owner, items] of navs) {
            if (items.length < 2) continue;
            if (!navRow) navRow = owner;
            // Only items sharing a line: a wrapped nav has legitimately
            // negative horizontal gaps between its rows.
            const rows = new Map();
            for (const el of items) {
              const r = el.getBoundingClientRect();
              const key = Math.round(r.top / 4) * 4;
              if (!rows.has(key)) rows.set(key, []);
              rows.get(key).push(r);
            }
            const mine = [];
            for (const row of rows.values()) {
              row.sort((a, b) => a.left - b.left);
              for (let k = 1; k < row.length; k++) mine.push(Math.round(row[k].left - row[k - 1].right));
            }
            if (mine.length) { gapsByNav.push(mine); gaps.push(...mine); }
          }

          // The photographs on an archive, so a collapsed grid is visible here
          // too rather than needing a second pass.
          const tiles = [...document.querySelectorAll('img')]
            .filter((i) => { const v = i.getAttribute('src') || ''; return v.endsWith(srcs.land) || v.endsWith(srcs.port); })
            .map((i) => { const r = i.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height) }; });

          return {
            viewportWidth: window.innerWidth,
            documentWidth: Math.round(document.documentElement.scrollWidth),
            title: box(title), body: box(body), hero: box(hero),
            nav: box(navRow), navGaps: gaps, navGapsByNav: gapsByNav,
            tiles: tiles.length,
            tileSizes: tiles.slice(0, 4),
            overflowsViewport: document.documentElement.scrollWidth > window.innerWidth + 1,
          };
        }, srcs);
        results.push({ skin: entry.skin, kind: entry.kind, skin_version: entry.skin_version, viewport: vp.name, ...data });
        measured++;
        // LOOK AT IT, do not only measure it. Sean: "are you looking at
        // screenshots when verifying skins or just code? because i think if
        // you're not that's how you're missing the problems." He is right.
        // Measuring only ever finds what someone thought to ask for; a
        // picture shows what nobody asked about. These are written so the
        // skins can be looked at, by a person or by an agent that can read an
        // image, without opening a single site.
        if (shots) {
          const shotDir = path.join(fixtureDir, 'shots');
          if (!fs.existsSync(shotDir)) fs.mkdirSync(shotDir, { recursive: true });
          await page.screenshot({
            path: path.join(shotDir, entry.skin + '--' + entry.kind + '--' + vp.name + '.png'),
            fullPage: false,
          });
        }
      } catch (err) {
        results.push({ skin: entry.skin, kind: entry.kind, viewport: vp.name, error: String(err && err.message || err) });
        unmeasurable++;
      } finally {
        await page.close();
      }
    }
  }
  await browser.close();

  fs.writeFileSync(outFile, JSON.stringify({ measured, unmeasurable, results }, null, 1));

  // A readable table, because the point of this is that someone can look at it
  // and say which numbers are deliberate.
  const pad = (s, n) => String(s === null || s === undefined ? '-' : s).padEnd(n);
  console.log(pad('SKIN', 24) + pad('KIND', 8) + pad('VP', 8) + pad('TITLE l/w', 13)
    + pad('BODY l/w', 13) + pad('HERO l/w', 13) + pad('NAV GAPS', 18) + 'NOTE');
  console.log('-'.repeat(118));
  for (const r of results) {
    if (r.error) { console.log(pad(r.skin, 24) + pad(r.kind, 8) + pad(r.viewport || '-', 8) + 'ERROR ' + r.error); continue; }
    const lw = (b) => (b ? b.left + '/' + b.width : '-');
    const note = [];
    if (r.overflowsViewport) note.push('OVERFLOWS VIEWPORT');
    if (r.hero && r.body && (r.hero.left !== r.body.left || r.hero.width !== r.body.width)) note.push('hero != body column');
    // NO AUTOMATIC VERDICT ON NAV SPACING. "All gaps equal" is not a rule.
    // A navigation using space-between has one large deliberate gap in the
    // middle, and flagging that produced false alarms on ALFRED, TELEGRAM and
    // TILEZ. 52 CARD PICKUP was flagged too, until measuring the list items
    // rather than the anchors showed a perfect 20, 20, 20.
    //
    // The numbers are recorded and the per-skin expectation, taken from the
    // owner's own capture, is what judges them. This is Codex's correction
    // about universal rules, applied to my own check.
    // SMACKTALK skins are blog skins and have no photograph archive, so an
    // archive with no photographs is correct for them, not a defect.
    const SMACKTALK = ['alfred', 'stanley', 'telegram', 'tilez', 'writing-with-impact'];
    if (r.kind === 'archive' && r.tiles === 0) {
      note.push(SMACKTALK.includes(r.skin) ? 'no photo archive (SMACKTALK, expected)' : 'NO PHOTOGRAPHS');
    }
    console.log(pad(r.skin, 24) + pad(r.kind, 8) + pad(r.viewport, 8)
      + pad(lw(r.title), 13) + pad(lw(r.body), 13) + pad(lw(r.hero), 13)
      + pad(r.navGaps.join(','), 18) + note.join('; '));
  }
  console.log('\n' + measured + ' measured, ' + unmeasurable + ' unmeasurable. Written to ' + outFile);
  if (unmeasurable > 0) fail(unmeasurable + ' page(s) could not be measured; that is never a pass.');
})().catch((e) => { console.error(e); process.exitCode = 1; });

// ===== SNAPSMACK EOF =====
