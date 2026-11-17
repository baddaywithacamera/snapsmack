// SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
//
// REAL-BROWSER PIXEL CHECK for the archive thumbnail frame. (987D.)
//
// The 986D frame (inset box-shadow on the tile element) was invisible on Sean's
// live /archive: the opaque photo fills the tile and paints OVER the inset
// shadow. The earlier test only read the CSS string, so it passed while the
// frame was gone -- the six-week "measure the markup, not the pixels" failure.
//
// This renders the thumbnail the way the browser does, screenshots it, DECODES
// the PNG, and asserts the frame colour is actually painted at the image edge
// (and the photo still shows in the middle, and nothing shifts). It also proves
// the OLD inset-on-element form FAILS this check, so the regression cannot creep
// back. The frame is drawn as a ::after overlay on top of the photo -- the exact
// CSS smack-appearance-archive.php now emits (contract asserted in the PHP test
// archive-frame-rule-survives-recompile-regression.php).
const assert = require('node:assert/strict');
const zlib = require('node:zlib');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { chromium } = require('../tools/browser-tests/runtime.cjs');

// Minimal PNG decoder (8-bit RGBA, non-interlaced -- what Playwright emits).
function decodePNG(buf) {
  let p = 8, width = 0, height = 0, colorType = 6; const idat = [];
  while (p < buf.length) {
    const len = buf.readUInt32BE(p);
    const type = buf.toString('ascii', p + 4, p + 8);
    const data = buf.subarray(p + 8, p + 8 + len);
    if (type === 'IHDR') { width = data.readUInt32BE(0); height = data.readUInt32BE(4); colorType = data[9]; }
    else if (type === 'IDAT') idat.push(data);
    else if (type === 'IEND') break;
    p += 12 + len;
  }
  const raw = zlib.inflateSync(Buffer.concat(idat));
  // Playwright emits RGB (colour type 2, 3 bytes) or RGBA (type 6, 4 bytes).
  const bpp = colorType === 6 ? 4 : 3, stride = width * bpp, out = Buffer.alloc(height * stride);
  for (let y = 0; y < height; y++) {
    const filter = raw[y * (stride + 1)];
    const rs = y * (stride + 1) + 1;
    for (let x = 0; x < stride; x++) {
      const v = raw[rs + x];
      const a = x >= bpp ? out[y * stride + x - bpp] : 0;
      const b = y > 0 ? out[(y - 1) * stride + x] : 0;
      const c = (x >= bpp && y > 0) ? out[(y - 1) * stride + x - bpp] : 0;
      let r;
      switch (filter) {
        case 1: r = v + a; break;
        case 2: r = v + b; break;
        case 3: r = v + ((a + b) >> 1); break;
        case 4: { const pa = Math.abs(b - c), pb = Math.abs(a - c), pc = Math.abs(a + b - 2 * c);
                  r = v + (pa <= pb && pa <= pc ? a : pb <= pc ? b : c); break; }
        default: r = v;
      }
      out[y * stride + x] = r & 0xff;
    }
  }
  return { width, height, at: (x, y) => { const i = y * stride + x * bpp; return [out[i], out[i + 1], out[i + 2]]; } };
}
const isGrey = ([r, g, b]) => Math.abs(r - g) < 24 && Math.abs(g - b) < 24 && r > 60 && r < 150;
const isRed  = ([r, g, b]) => r > 190 && g < 70 && b < 70;

// A solid RED photo that fills the tile -- the opaque image that hid the frame.
const REDIMG = 'data:image/svg+xml,'
  + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect width="200" height="200" fill="#ff0000"/></svg>');

// The overlay the CMS now emits for dbl_grey (3px + gap + 1px, grey).
const SHADOW = 'inset 0 0 0 3px #666666, inset 0 0 0 8px #0d0d0d, inset 0 0 0 9px #666666';
const OVERLAY_CSS = `.cropped-grid .thumb-link { position: relative !important; }
  .cropped-grid .thumb-link::after { content:'' !important; position:absolute !important; inset:0 !important;
     pointer-events:none !important; box-sizing:border-box !important; box-shadow:${SHADOW} !important; }`;
// The 986D form that was invisible: inset shadow on the element itself, under the photo.
const OLD_CSS = `.cropped-grid .thumb-link { box-shadow: ${SHADOW} !important; }`;

const FIXTURE = `<div class="cropped-grid"><article class="thumb-container">
  <a class="thumb-link" style="display:block;width:200px;height:200px;overflow:hidden">
    <img src="${REDIMG}" alt="" style="display:block;width:100%;height:100%;object-fit:cover">
  </a></article></div>`;

async function edgeAndCentre(page) {
  const el = await page.$('.thumb-link');
  const png = decodePNG(await el.screenshot());
  const mid = Math.floor(png.width / 2);
  return { edge: png.at(mid, 2), centre: png.at(mid, Math.floor(png.height / 2)),
           box: await el.evaluate(n => { const r = n.querySelector('img').getBoundingClientRect();
             return { w: Math.round(r.width), h: Math.round(r.height) }; }) };
}

(async () => {
  const browser = await chromium.launch();
  let passed = 0;
  try {
    const page = await browser.newPage({ viewport: { width: 1024, height: 900, deviceScaleFactor: 1 } });
    await page.setContent(`<body>${FIXTURE}</body>`);
    await page.evaluate(() => document.querySelector('img').decode());

    // Baseline geometry with no frame, to prove the frame shifts nothing.
    const base = await edgeAndCentre(page);

    // THE FIX: overlay frame is visible over the photo at the edge.
    await page.addStyleTag({ content: OVERLAY_CSS });
    const fixed = await edgeAndCentre(page);
    assert.ok(isGrey(fixed.edge), `frame not visible at the image edge (got ${fixed.edge})`);
    assert.ok(isRed(fixed.centre), `photo not visible in the centre (got ${fixed.centre})`);
    assert.deepEqual(fixed.box, base.box, 'the frame changed the photo geometry (layout shift)');
    console.log('PASS overlay frame: grey line at the image edge, photo intact, no layout shift');
    passed++;

    // THE OLD DEFECT must fail this very check: inset shadow under the photo.
    const page2 = await browser.newPage({ viewport: { width: 1024, height: 900, deviceScaleFactor: 1 } });
    await page2.setContent(`<body>${FIXTURE}</body>`);
    await page2.evaluate(() => document.querySelector('img').decode());
    await page2.addStyleTag({ content: OLD_CSS });
    const old = await edgeAndCentre(page2);
    assert.ok(isRed(old.edge), `the 986D inset-on-element form should be HIDDEN under the photo, but edge=${old.edge}`);
    console.log('PASS old inset-on-element frame is correctly detected as invisible (under the photo)');
    passed++;

    // Execute the actual save-path rule remover. Compare complete rendered pixels
    // before/after a prior CMS frame is cleared, retaining a skin-owned shadow.
    const cleanCss = execFileSync('php', ['-r', `
      $src=file_get_contents($argv[1]);
      $start=strpos($src, '$upsert_rule = function');
      $end=strpos($src, "\\n        };", $start);
      eval(substr($src,$start,$end-$start)."\\n};");
      $blob=$argv[2]."\\n/* arch_opt:grid_border */ .thumb-link {box-shadow:none!important;}\\n/* arch_opt:masonry_border */ .justified-item::after {content:'';}\\n";
      $upsert_rule($blob,'/* arch_opt:grid_border */','');
      $upsert_rule($blob,'/* arch_opt:masonry_border */','');
      echo $blob;
    `, path.resolve(__dirname, '../smack-appearance-archive.php'),
      '.thumb-link {box-shadow: 0 8px 15px #333; border: 2px solid #789;}'], { encoding: 'utf8' });
    for (const width of [1920, 1440, 1024]) {
      await page2.setViewportSize({ width, height: 900 });
      await page2.setContent(`<body>${FIXTURE}</body>`);
      await page2.evaluate(() => document.querySelector('img').decode());
      await page2.addStyleTag({ content: '.thumb-link {box-shadow: 0 8px 15px #333; border: 2px solid #789;}' });
      const before = await page2.screenshot();
      await page2.addStyleTag({ content: cleanCss });
      assert.deepEqual(await page2.screenshot(), before, `none changed skin pixels at ${width}`);
      console.log(`PASS none preserves skin border/shadow and geometry at ${width}`);
      passed++;
    }
    console.log(`PASS: ${passed}/5 archive frame pixel checks`);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });

// ===== SNAPSMACK EOF =====
