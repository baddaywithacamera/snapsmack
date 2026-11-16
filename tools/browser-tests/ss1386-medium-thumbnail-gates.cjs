// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====
//
// SS-1386: measure the medium-thumbnail srcset on every real skin archive.
// This is a browser gate, not a markup test. It records which candidate the
// browser requests, how many bytes that response contains, whether the tile
// paints, whether a missing m_ file leaves the a_ fallback intact, and whether
// adding srcset changes cumulative layout shift. It also compares the sizes
// hint with the tile width the skin actually lays out at the supported widths.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const { chromium } = require(path.join(__dirname, 'runtime.cjs'));
const WIDTHS = [1920, 1440, 1024];

function readConstant(source, name) {
  const match = source.match(new RegExp("define\\('" + name + "',\\s*([^;]+)\\);"));
  if (!match) throw new Error(`Cannot read ${name} from core/constants.php`);
  const raw = match[1].trim();
  if (/^\d+$/.test(raw)) return Number(raw);
  const quoted = raw.match(/^'([^']*)'$/);
  if (quoted) return quoted[1];
  throw new Error(`Unsupported ${name} declaration: ${raw}`);
}

function paddedSvg(width, height, label, bytes) {
  const head = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><rect width="100%" height="100%" fill="#789"/><text x="12" y="30">${label}</text>`;
  const tail = '</svg>';
  const pad = Math.max(0, bytes - Buffer.byteLength(head) - Buffer.byteLength(tail) - 7);
  return Buffer.from(head + '<!--' + 'x'.repeat(pad) + '-->' + tail);
}

function candidate(shape, size) {
  if (shape === 'land') {
    return size === 'm'
      ? { width: 600, height: 400, body: paddedSvg(600, 400, 'm landscape', 12000) }
      : { width: 900, height: 600, body: paddedSvg(900, 600, 'a landscape', 36000) };
  }
  return size === 'm'
    ? { width: 400, height: 600, body: paddedSvg(400, 600, 'm portrait', 12000) }
    : { width: 600, height: 900, body: paddedSvg(600, 900, 'a portrait', 36000) };
}

function injectObserver(html) {
  const script = `<script>
    window.__ss1386Cls = 0;
    new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        if (!entry.hadRecentInput) window.__ss1386Cls += entry.value;
      }
    }).observe({type:'layout-shift', buffered:true});
  </script>`;
  return html.includes('<head>')
    ? html.replace('<head>', '<head>' + script)
    : script + html;
}

function makePage(html, origin, run, mode, sizes, mediumDescriptor, aspectDescriptor) {
  let index = 0;
  return injectObserver(html.replace(
    /<img\b([^>]*?)\bsrc="photo-(1200x800|800x1200)\.svg"([^>]*)>/g,
    (tag, before, dimensions, after) => {
      const id = index++;
      const shape = dimensions === '1200x800' ? 'land' : 'port';
      // Every third image is the normal fleet state while backfill is in
      // progress: there is no m_ path and therefore no srcset at all.
      const fallback = mode === 'baseline' || id % 3 === 1;
      const a = `${origin}/asset/${run}/a-${shape}-${id}.svg`;
      const m = `${origin}/asset/${run}/m-${shape}-${id}.svg`;
      const srcset = fallback ? ''
        : ` srcset="${m} ${mediumDescriptor}w, ${a} ${aspectDescriptor}w" sizes="${sizes}"`;
      return `<img${before}src="${a}"${srcset} data-ss1386-id="${id}" data-ss1386-shape="${shape}" data-ss1386-state="${fallback ? 'fallback' : 'srcset'}"${after}>`;
    }
  ));
}

function hintedSlot(viewport, sizes, renderedWidth) {
  if (/^auto(?:\s*,|$)/.test(sizes.trim())) return renderedWidth;
  if (viewport <= 700) return viewport;
  if (viewport <= 1200) return viewport * 0.5;
  return viewport * 0.33;
}

async function settle(page) {
  const images = page.locator('img[data-ss1386-id]');
  // Candidate selection is the subject of this gate, not lazy-loading's
  // distance threshold. Force the same tags eager so hidden/off-screen tiles
  // request a candidate too; visibility is still measured from their real CSS.
  await images.evaluateAll((nodes) => nodes.forEach((el) => { el.loading = 'eager'; }));
  // The local server deliberately waits 35ms. Four times that is enough for
  // every eager request without turning a hidden tile into a per-page timeout.
  await page.waitForTimeout(140);
}

async function measurePage(browser, html, viewport) {
  const page = await browser.newPage({ viewport: { width: viewport, height: 900 }, deviceScaleFactor: 1 });
  try {
    await page.setContent(html, { waitUntil: 'load' });
    await settle(page);
    return await page.locator('img[data-ss1386-id]').evaluateAll((images) => ({
      cls: window.__ss1386Cls || 0,
      images: images.map((img) => {
        const rect = img.getBoundingClientRect();
        const style = getComputedStyle(img);
        return {
          id: Number(img.dataset.ss1386Id),
          shape: img.dataset.ss1386Shape,
          state: img.dataset.ss1386State,
          src: img.src,
          srcset: img.srcset,
          sizes: img.sizes,
          currentSrc: img.currentSrc,
          naturalWidth: img.naturalWidth,
          naturalHeight: img.naturalHeight,
          renderedWidth: rect.width,
          renderedHeight: rect.height,
          visible: rect.width > 0 && rect.height > 0
            && style.display !== 'none' && style.visibility !== 'hidden',
          complete: img.complete,
        };
      }),
    }));
  } finally {
    await page.close();
  }
}

(async () => {
  const constants = fs.readFileSync(path.join(ROOT, 'core', 'constants.php'), 'utf8');
  const mediumDescriptor = readConstant(constants, 'SNAPSMACK_THUMB_MEDIUM_LONG');
  const aspectDescriptor = readConstant(constants, 'SNAPSMACK_THUMB_ASPECT_LONG');
  const sizes = process.env.SS1386_SIZES_OVERRIDE
    || readConstant(constants, 'SNAPSMACK_GRID_IMAGE_SIZES');
  const skipBaseline = process.env.SS1386_SKIP_BASELINE === '1';

  const fixtureDir = fs.mkdtempSync(path.join(os.tmpdir(), 'ss1386-browser-'));
  const rendered = spawnSync('php', [path.join(ROOT, 'tools', '_build', 'render-geometry-fixtures.php'), fixtureDir], {
    cwd: ROOT, encoding: 'utf8', windowsHide: true,
  });
  if (rendered.status !== 0) {
    throw new Error(`Fixture render failed:\n${rendered.stdout}\n${rendered.stderr}`);
  }
  const index = JSON.parse(fs.readFileSync(path.join(fixtureDir, 'index.json'), 'utf8'));
  const archives = index.pages.filter((entry) => entry.kind === 'archive');
  assert.equal(archives.length, 30, 'all 30 skin archives must be measured');

  const requests = [];
  const assets = new Map();
  const server = http.createServer((request, response) => {
    const pathname = new URL(request.url, 'http://127.0.0.1').pathname;
    const match = pathname.match(/^\/asset\/([^/]+)\/([am])-(land|port)-(\d+)\.svg$/);
    if (!match) {
      response.writeHead(404).end('not found');
      return;
    }
    const [, run, size, shape, id] = match;
    const key = `${size}-${shape}`;
    if (!assets.has(key)) assets.set(key, candidate(shape, size));
    const asset = assets.get(key);
    requests.push({ run, size, shape, id: Number(id), bytes: asset.body.length, pathname });
    response.writeHead(200, {
      'content-type': 'image/svg+xml',
      'content-length': asset.body.length,
      'cache-control': 'no-store',
    });
    // A small delay makes any layout movement observable instead of hiding it
    // behind an in-process response that completes in the same paint.
    setTimeout(() => response.end(asset.body), 35);
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const address = server.address();
  const origin = `http://127.0.0.1:${address.port}`;

  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  const failures = [];
  try {
    for (const entry of archives) {
      const source = fs.readFileSync(path.join(fixtureDir, entry.file), 'utf8');
      for (const viewport of WIDTHS) {
        const baseRun = `${entry.skin}-${viewport}-base`;
        const srcsetRun = `${entry.skin}-${viewport}-srcset`;
        const baseline = skipBaseline ? { cls: null } : await measurePage(browser,
          makePage(source, origin, baseRun, 'baseline', sizes, mediumDescriptor, aspectDescriptor), viewport);
        const measured = await measurePage(browser,
          makePage(source, origin, srcsetRun, 'srcset', sizes, mediumDescriptor, aspectDescriptor), viewport);
        const baselineById = new Map((baseline.images || []).map((image) => [image.id, image]));
        const rows = [];

        for (const image of measured.images) {
          const slot = hintedSlot(viewport, sizes, image.renderedWidth);
          const runRequests = requests.filter((item) => item.run === srcsetRun && item.id === image.id);
          const current = runRequests.find((item) => image.currentSrc.endsWith(item.pathname)) || null;
          const expected = candidate(image.shape, current ? current.size : 'a');
          const aspect = candidate(image.shape, 'a');
          const baselineImage = baselineById.get(image.id);
          const sizeError = image.renderedWidth > 0
            ? Math.abs(slot - image.renderedWidth) / image.renderedWidth : Infinity;
          const row = {
            ...image,
            selected: current ? current.size : 'none',
            transferredBytes: current ? current.bytes : 0,
            hintedSlot: slot,
            sizesRelativeError: sizeError,
            intrinsicWidthExpected: expected.width,
          };
          rows.push(row);

          if (!skipBaseline && baselineImage && baselineImage.visible
              && (!image.visible || !image.complete || image.naturalWidth < 1)) {
            failures.push(`${entry.skin} ${viewport}px image ${image.id}: srcset made a baseline-visible tile fail to decode`);
          }
          if (!current) {
            failures.push(`${entry.skin} ${viewport}px image ${image.id}: selected candidate was not observed on the wire`);
          }
          if (image.state === 'fallback') {
            if (image.srcset !== '' || (current && current.size !== 'a')) {
              failures.push(`${entry.skin} ${viewport}px image ${image.id}: missing m_ did not remain on plain a_ fallback`);
            }
          } else {
            if (!image.srcset || !image.sizes) {
              failures.push(`${entry.skin} ${viewport}px image ${image.id}: m_ exists but srcset/sizes is absent`);
            }
            // A w descriptor is the candidate's intrinsic width, not its
            // long-edge cap. This catches portrait m_=400px advertised 600w.
            const descriptor = current && current.size === 'm' ? mediumDescriptor : aspectDescriptor;
            if (current && descriptor !== expected.width) {
              failures.push(`${entry.skin} ${viewport}px image ${image.id}: ${image.shape} ${current.size}_ is ${expected.width}px wide but advertised ${descriptor}w`);
            }
          }
          if (current && current.size === 'm'
              && expected.width + 1 < Math.min(image.renderedWidth, aspect.width)) {
            failures.push(`${entry.skin} ${viewport}px image ${image.id}: selected ${expected.width}px m_ where ${aspect.width}px a_ is needed for ${image.renderedWidth.toFixed(1)}px tile`);
          }
          if (image.state === 'srcset' && image.visible && image.renderedWidth > 0
              && sizeError > 0.20) {
            failures.push(`${entry.skin} ${viewport}px image ${image.id}: sizes hints ${slot.toFixed(1)}px but tile is ${image.renderedWidth.toFixed(1)}px (${Math.round(sizeError * 100)}% error)`);
          }
        }
        if (!skipBaseline && measured.cls > baseline.cls + 0.01) {
          failures.push(`${entry.skin} ${viewport}px: srcset CLS ${measured.cls.toFixed(4)} exceeds baseline ${baseline.cls.toFixed(4)}`);
        }
        results.push({
          skin: entry.skin, viewport, sizes,
          baselineCls: baseline.cls, srcsetCls: measured.cls,
          images: rows,
        });
      }
    }
  } finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }

  const evidence = path.join(fixtureDir, 'ss1386-medium-thumbnail-results.json');
  fs.writeFileSync(evidence, JSON.stringify({
    descriptors: { mediumDescriptor, aspectDescriptor, sizes },
    results, failures,
  }, null, 2));

  const uniqueFailures = [...new Set(failures)];
  const summary = {
    skins: archives.length,
    viewports: WIDTHS,
    checks: results.length,
    failures: uniqueFailures.length,
    evidence,
    failureSample: uniqueFailures.slice(0, 30),
  };
  console.log(JSON.stringify(summary, null, 2));
  if (uniqueFailures.length) process.exitCode = 1;
})().catch((error) => {
  console.error(error && error.stack || error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
