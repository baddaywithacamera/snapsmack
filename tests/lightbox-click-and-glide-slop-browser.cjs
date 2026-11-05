// A photograph wrapped in a link must open the lightbox WITHOUT the browser
// leaving for the solo page -- and a press that wobbles must still count as a
// click.
//
// WHY A BROWSER AND NOT A STRING SEARCH. Both of these are event behaviour:
// whether preventDefault was called, and whether a sequence of pointermoves
// trips a threshold. Neither can be established by reading the file, and both
// were wrong in ways that read perfectly fine in source.
//
// Chromium comes from Codex's published seam, never from node_modules.
const path = require('node:path');
const fs = require('node:fs');

const ROOT = path.resolve(__dirname, '..');

// Codex's seam resolves @playwright/test from tools/browser-tests/node_modules,
// which is gitignored and therefore absent in a git worktree. The engines under
// test still come from ROOT; only the browser is borrowed. Point this at a
// checkout that has the install:
//     SNAP_BROWSER_SEAM_ROOT=C:/dev/snapsmack node tests/<this file>
const SEAM_ROOT = process.env.SNAP_BROWSER_SEAM_ROOT || ROOT;
const { chromium } = require(path.join(SEAM_ROOT, 'tools', 'browser-tests', 'runtime.cjs'));

const LIGHTBOX = fs.readFileSync(path.join(ROOT, 'assets/js/ss-engine-lightbox.js'), 'utf8');
const GLIDE = fs.readFileSync(path.join(ROOT, 'assets/js/ss-engine-glide.js'), 'utf8');

let pass = 0, fail = 0;
const cx = (ok, what) => {
  if (ok) { pass++; console.log('  ok    ' + what); }
  else { fail++; console.log('  FAIL  ' + what); }
};

const LIGHTBOX_PAGE = `<!doctype html><meta charset="utf-8"><body>
  <a id="link" href="/solo-page"><img id="wrapped" src="/thumb.jpg" data-lightbox-src="/big.jpg" alt=""></a>
  <img id="bare" src="/thumb2.jpg" data-lightbox-src="/big2.jpg" alt="">
</body>`;

const GLIDE_PAGE = `<!doctype html><meta charset="utf-8">
<style>.glide-track{display:flex;gap:12px}.glide-track>*{width:200px;height:120px;flex:0 0 auto;background:#333}</style>
<body><div data-glide-wall data-travel="0.9" style="width:800px;height:400px">
  <div data-glide-row><div class="glide-track"><i></i><i></i><i></i><i></i><i></i></div></div>
</div></body>`;

(async () => {
  const browser = await chromium.launch();

  // ---- the lightbox click ------------------------------------------------
  {
    const page = await browser.newPage({ viewport: { width: 1200, height: 800 } });
    await page.setContent(LIGHTBOX_PAGE);
    await page.addScriptTag({ content: LIGHTBOX });
    await page.waitForTimeout(200);

    const r = await page.evaluate(() => {
      const out = {};
      const fire = (id, init) => {
        const el = document.getElementById(id);
        const e = new MouseEvent('click', Object.assign(
          { bubbles: true, cancelable: true, button: 0 }, init));
        el.dispatchEvent(e);
        return e.defaultPrevented;
      };
      const open = () => {
        const o = document.getElementById('ss-lightbox-overlay');
        return !!o && o.style.display !== 'none';
      };
      const close = () => {
        const o = document.getElementById('ss-lightbox-overlay');
        if (o) o.style.display = 'none';
      };

      out.wiredWrapped = !!document.getElementById('wrapped').dataset._ssLbWired;
      out.wiredBare = !!document.getElementById('bare').dataset._ssLbWired;

      close();
      out.plainPrevented = fire('wrapped', {});
      out.plainOpened = open();

      close();
      out.ctrlPrevented = fire('wrapped', { ctrlKey: true });
      out.ctrlOpened = open();

      close();
      out.metaPrevented = fire('wrapped', { metaKey: true });

      close();
      out.middlePrevented = fire('wrapped', { button: 1 });

      close();
      out.barePrevented = fire('bare', {});
      out.bareOpened = open();
      return out;
    });

    console.log('A PHOTOGRAPH INSIDE A LINK');
    cx(r.wiredWrapped && r.wiredBare, 'both photographs are wired by data-lightbox-src');
    cx(r.plainOpened, 'a plain left click OPENS the lightbox');
    cx(r.plainPrevented,
      'and the anchor is PREVENTED, so the browser does not leave for the solo page');
    cx(!r.ctrlPrevented && !r.ctrlOpened,
      'ctrl-click is left alone: no lightbox, navigation proceeds (open in new tab)');
    cx(!r.metaPrevented, 'cmd-click likewise, for macOS');
    cx(!r.middlePrevented, 'middle-click likewise');

    console.log('A PHOTOGRAPH NOT INSIDE A LINK');
    cx(r.bareOpened, 'still opens the lightbox');
    cx(!r.barePrevented, 'and nothing is prevented, because there is no link to suppress');
    await page.close();
  }

  // ---- the glide drag slop ----------------------------------------------
  {
    const page = await browser.newPage({ viewport: { width: 800, height: 600 } });
    await page.setContent(GLIDE_PAGE);
    await page.addScriptTag({ content: GLIDE });
    await page.waitForTimeout(200);

    const r = await page.evaluate(async () => {
      const row = document.querySelector('[data-glide-row]');
      const b = row.getBoundingClientRect();
      const y = Math.round(b.top + b.height / 2);
      const x0 = Math.round(b.left + 50);

      // Drive a press with a given list of x offsets, then report whether the
      // click that follows survives (not prevented) or is swallowed as a drag.
      const press = async (offsets) => {
        const o = (x) => ({ bubbles: true, cancelable: true, clientX: x, clientY: y,
                            pointerId: 1, pointerType: 'mouse', button: 0, isPrimary: true });
        row.dispatchEvent(new PointerEvent('pointerdown', o(x0)));
        for (const dx of offsets) row.dispatchEvent(new PointerEvent('pointermove', o(x0 + dx)));
        row.dispatchEvent(new PointerEvent('pointerup', o(x0 + (offsets.at(-1) || 0))));
        const click = new MouseEvent('click', {bubbles: true, cancelable: true, button: 0});
        row.dispatchEvent(click);
        return click.defaultPrevented;   // true = treated as a drag
      };

      return {
        tremor4px: await press([2, 3, 4, 3, 2]),          // wobble, never past 10
        tremor9px: await press([4, 7, 9, 8]),             // still under the slop
        slowDrag20px: await press([2, 4, 6, 8, 12, 16, 20]), // slow, 2px at a time
        fastDrag40px: await press([40]),                  // one big jump
      };
    });

    console.log('THE PRESS THAT WOBBLES vs THE PRESS THAT DRAGS');
    cx(r.tremor4px === false, 'a 4px wobble is still a CLICK, not a drag');
    cx(r.tremor9px === false, 'a 9px wobble is still a click (slop is 10px)');
    cx(r.slowDrag20px === true,
      'a SLOW drag of 20px in 2px steps IS a drag -- the old per-move test missed this entirely');
    cx(r.fastDrag40px === true, 'a fast 40px drag is a drag');
    await page.close();
  }

  await browser.close();
  console.log(`\n${pass + fail} checks, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
