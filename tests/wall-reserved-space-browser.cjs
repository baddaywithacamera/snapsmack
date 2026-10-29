// The masonry wall must reserve its space before any script runs.
//
// Sean, 29 October 2026: "the archive page loads everything large and then
// resizes... so stuff doesn't jump all over as the page draws?"
//
// MEASURED on photowalk.ing/archive-view, 50 SHADES, masonry view: all 24 tile
// anchors computed to 0x0 and the wall had no height, so the whole page below
// the archive sat at the top and snapped down when ss-engine-rows.js laid out.
// The dimensions were in the markup the whole time as data-w and data-h, which
// a browser cannot read -- only JavaScript can. SS-1046.
//
// This measures the page in BOTH states, in a real browser:
//   1. engine blocked   -- the state a visitor sees during parse and on a slow
//                          connection. Tiles must have real boxes of the right
//                          shape and the wall must have real height.
//   2. engine running   -- the finished layout. It must still be the engine's
//                          justified rows, absolutely positioned, and the grid
//                          fallback must have switched itself off.
//
// Served over http rather than file:// so the stylesheet and the engine load
// by the same absolute paths the real pages use.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(path.join(__dirname, '..', 'tools', 'browser-tests', 'runtime.cjs'));

const ROOT = path.resolve(__dirname, '..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };
const PAGE = '/tests/fixtures/wall-reserved-space-harness.html';

const server = http.createServer((req, res) => {
  const rel = decodeURIComponent(req.url.split('?')[0]);
  const file = path.join(ROOT, rel);
  if (!file.startsWith(ROOT)) { res.writeHead(403).end(); return; }
  fs.readFile(file, (err, buf) => {
    if (err) { res.writeHead(404).end('not found: ' + rel); return; }
    res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' });
    res.end(buf);
  });
});

const results = [];
const t = (name, ok, got) => results.push({ name, ok: !!ok, got: String(got) });

server.listen(0, '127.0.0.1', async () => {
  const port = server.address().port;
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });

  const read = async (withEngine) => {
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    if (withEngine) {
      await page.goto(`http://127.0.0.1:${port}${PAGE}`, { waitUntil: 'load' });
      await page.addScriptTag({ url: '/assets/js/ss-engine-rows.js' });
      await page.waitForTimeout(250);
    } else {
      await page.goto(`http://127.0.0.1:${port}${PAGE}`, { waitUntil: 'load' });
    }
    const m = await page.evaluate(() => {
      const box = el => { const r = el.getBoundingClientRect();
        return { w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top) }; };
      const grid = document.querySelector('#justified-grid');
      const tiles = [...document.querySelectorAll('.ss-masonry-item')];
      return {
        ready: grid.hasAttribute('data-wall-ready'),
        gridDisplay: getComputedStyle(grid).display,
        gridBox: box(grid),
        belowTop: box(document.querySelector('#below')).top,
        tiles: tiles.map(el => ({ ...box(el), pos: getComputedStyle(el).position })),
      };
    });
    await page.close();
    return m;
  };

  const before = await read(false);
  const after = await read(true);

  // --- 1. Before any script: the space is reserved -------------------------
  t('before: every tile has a real box', before.tiles.every(x => x.w > 0 && x.h > 0),
    before.tiles.map(x => x.w + 'x' + x.h).join(' '));
  t('before: the wall has real height', before.gridBox.h > 400, before.gridBox.h);
  t('before: the content below is pushed down by the wall', before.belowTop > 400, before.belowTop);
  t('before: the parse-time fallback layout is in use, and the engine has not claimed the wall',
    before.gridDisplay !== 'block' && !before.ready,
    before.gridDisplay + ' ready=' + before.ready);
  // Shapes must come from each photograph, not one default. Tile 1 is a
  // portrait at 1233x1850 and tile 2 a landscape at 2500x1667; if both came
  // out the same shape the aspect-ratio is not being generated per tile.
  const ratio = x => +(x.w / x.h).toFixed(2);
  t('before: a portrait tile is taller than it is wide', ratio(before.tiles[0]) < 0.9, ratio(before.tiles[0]));
  t('before: a landscape tile is wider than it is tall', ratio(before.tiles[1]) > 1.1, ratio(before.tiles[1]));
  t('before: a square tile is square', Math.abs(ratio(before.tiles[2]) - 1) < 0.05, ratio(before.tiles[2]));

  // --- 2. After the engine: it owns geometry, as the stylesheet requires ---
  t('after: the engine declares it has taken over', after.ready === true, after.ready);
  t('after: the container is NOT grid, as the partner stylesheet requires',
    after.gridDisplay === 'block', after.gridDisplay);
  t('after: tiles are absolutely positioned by the engine',
    after.tiles.every(x => x.pos === 'absolute'), after.tiles[0] && after.tiles[0].pos);
  t('after: the wall still has real height', after.gridBox.h > 100, after.gridBox.h);
  t('after: every tile still has a real box', after.tiles.every(x => x.w > 0 && x.h > 0),
    after.tiles.map(x => x.w + 'x' + x.h).join(' '));

  // --- 3. The point of the exercise ---------------------------------------
  // The jump is the distance the page below the archive travels when the
  // engine runs. It will not be zero -- approximate boxes become justified
  // rows -- but it must be a correction, not a drop from nothing.
  const jump = Math.abs(after.belowTop - before.belowTop);
  t('the content below moves by less than half the wall height when the engine runs',
    jump < after.gridBox.h / 2, jump + 'px (wall is ' + after.gridBox.h + 'px)');

  for (const r of results) console.log((r.ok ? '  ok    ' : '  FAIL  ') + r.name + '   [' + r.got + ']');
  const ok = results.filter(r => r.ok).length;
  console.log('\n  ' + ok + ' / ' + results.length + ' passed');
  await browser.close();
  server.close();
  process.exit(ok === results.length ? 0 : 1);
});
