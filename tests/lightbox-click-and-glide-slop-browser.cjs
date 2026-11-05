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

      // Count capture calls: "capture only once it is a drag" is a claim about
      // WHEN setPointerCapture happens, which no amount of reading proves.
      let captures = 0;
      const realCapture = row.setPointerCapture.bind(row);
      row.setPointerCapture = function (id) { captures++; try { realCapture(id); } catch (e) {} };
      row.hasPointerCapture = function () { return captures > 0; };
      row.releasePointerCapture = function () {};

      const out = {};
      captures = 0; out.tremor4px = await press([2, 3, 4, 3, 2]);
      out.capturesAfterWobble = captures;
      // The class is a promise the engine makes to CSS. GLIDE had
      // .is-dragging .glide-tile { pointer-events: none }, so claiming it on
      // pointerdown killed the click before it could land.
      out.classAfterWobble = row.classList.contains('is-dragging');
      captures = 0; out.tremor9px = await press([4, 7, 9, 8]);
      captures = 0;
      row.dispatchEvent(new PointerEvent('pointerdown',
        {bubbles: true, cancelable: true, clientX: x0, clientY: y,
         pointerId: 1, pointerType: 'mouse', button: 0, isPrimary: true}));
      out.classOnPressAlone = row.classList.contains('is-dragging');
      row.dispatchEvent(new PointerEvent('pointermove',
        {bubbles: true, cancelable: true, clientX: x0 - 40, clientY: y,
         pointerId: 1, pointerType: 'mouse', button: 0, isPrimary: true}));
      out.classOnceDragging = row.classList.contains('is-dragging');
      row.dispatchEvent(new PointerEvent('pointerup',
        {bubbles: true, cancelable: true, clientX: x0 - 40, clientY: y,
         pointerId: 1, pointerType: 'mouse', button: 0, isPrimary: true}));

      captures = 0; out.slowDrag20px = await press([2, 4, 6, 8, 12, 16, 20]);
      out.capturesAfterDrag = captures;
      captures = 0; out.fastDrag40px = await press([40]);

      // A cancelled drag must not swallow the click that comes after it.
      const o = (x) => ({ bubbles: true, cancelable: true, clientX: x, clientY: y,
                          pointerId: 1, pointerType: 'mouse', button: 0, isPrimary: true });
      row.dispatchEvent(new PointerEvent('pointerdown', o(x0)));
      row.dispatchEvent(new PointerEvent('pointermove', o(x0 + 40)));
      row.dispatchEvent(new PointerEvent('pointercancel', o(x0 + 40)));
      const nextClick = new MouseEvent('click', {bubbles: true, cancelable: true, button: 0});
      row.dispatchEvent(nextClick);
      out.clickAfterCancelSwallowed = nextClick.defaultPrevented;

      return out;
    });

    console.log('THE PRESS THAT WOBBLES vs THE PRESS THAT DRAGS');
    cx(r.tremor4px === false, 'a 4px wobble is still a CLICK, not a drag');
    cx(r.tremor9px === false, 'a 9px wobble is still a click (slop is 10px)');
    cx(r.slowDrag20px === true,
      'a SLOW drag of 20px in 2px steps IS a drag -- the old per-move test missed this entirely');
    cx(r.fastDrag40px === true, 'a fast 40px drag is a drag');

    console.log('WHO OWNS THE POINTER, AND WHEN');
    cx(r.capturesAfterWobble === 0,
      'a press that stays under the slop NEVER captures the pointer, so a real click keeps its own target');
    cx(r.capturesAfterDrag === 1,
      'a genuine drag captures exactly once, when travel crosses the slop');
    cx(r.clickAfterCancelSwallowed === false,
      'a CANCELLED drag does not swallow the next click on that row');
    cx(r.classAfterWobble === false && r.classOnPressAlone === false,
      'the row does NOT claim is-dragging on a press alone -- the CSS that killed the click keyed off that');
    cx(r.classOnceDragging === true,
      'and it does claim it the moment travel makes it a real drag');
    await page.close();
  }


  // ---- the fling -------------------------------------------------------
  // Inertia is the one thing here that genuinely needs frames: it lives in
  // the paint loop. requestAnimationFrame does fire in headless Chromium
  // (checked), which is why this can be tested at all -- it cannot be tested
  // in the desktop app's browser pane, which never paints.
  for (const reduced of [false, true]) {
    const page = await browser.newPage({ viewport: { width: 800, height: 600 } });
    if (reduced) await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.setContent(GLIDE_PAGE);
    await page.addScriptTag({ content: GLIDE });
    await page.waitForTimeout(200);

    const r = await page.evaluate(async () => {
      const row = document.querySelector('[data-glide-row]');
      const track = row.firstElementChild;
      const b = row.getBoundingClientRect();
      const y = Math.round(b.top + b.height / 2);
      const x0 = Math.round(b.left + 400);
      const o = (x) => ({ bubbles: true, cancelable: true, clientX: x, clientY: y,
                          pointerId: 1, pointerType: 'mouse', button: 0, isPrimary: true });
      // The translate in PIXELS, so distances can be compared. Equality was the
      // wrong question and it cost me two false failures: releasing a drag
      // advances `target` and `current` then EASES toward it over many frames,
      // so the transform keeps changing while the wall settles, caught or not.
      const at = () => {
        const m = /translate3d\(([-0-9.]+)px/.exec(track.style.transform || '');
        return m ? parseFloat(m[1]) : 0;
      };
      const frames = (n) => new Promise((res) => {
        let i = 0;
        const t = () => (++i >= n ? res() : requestAnimationFrame(t));
        requestAnimationFrame(t);
      });
      const settle = () => frames(60);
      // THE SAME TOTAL TRAVEL, AT TWO DIFFERENT SPEEDS. This is the only honest
      // way to measure a fling here: the engine ALREADY coasted after release
      // before inertia existed, because `current` eases toward a `target` the
      // drag pushed far ahead. "Did it keep moving?" cannot tell the two apart
      // -- a mutant that removed the fling entirely passed that check. Both
      // drags below commit the same distance to `target`, so the settle is the
      // same; only the release velocity differs, so only the fling differs.
      const drag = (steps) => {
        const step = 300 / steps;
        row.dispatchEvent(new PointerEvent('pointerdown', o(x0)));
        for (let i = 1; i <= steps; i++) {
          row.dispatchEvent(new PointerEvent('pointermove', o(x0 - Math.round(i * step))));
        }
        row.dispatchEvent(new PointerEvent('pointerup', o(x0 - 300)));
      };
      const fling = () => drag(5);          // 60px a move: fast, flings
      const crawl = () => drag(300);        // 1px a move: below the floor

      // 1. a fast release carries further than a slow one of the same length
      await settle();
      fling();
      await frames(3);
      const a1 = at();
      await frames(15);
      const coastDistance = Math.abs(at() - a1);

      await settle();
      crawl();
      await frames(3);
      const c1 = at();
      await frames(15);
      const crawlDistance = Math.abs(at() - c1);

      // 2. the same measurement, interrupted by a press partway through
      await settle();
      fling();
      await frames(3);
      const a2 = at();
      row.dispatchEvent(new PointerEvent('pointerdown', o(x0)));
      await frames(15);
      const caughtDistance = Math.abs(at() - a2);
      row.dispatchEvent(new PointerEvent('pointerup', o(x0)));

      // 3. a tap, from a wall that is genuinely at rest.
      // MEASURED AFTER THE RELEASE, not across the whole gesture: a 3px
      // pointermove legitimately nudges the wall ~3px, because the wall tracks
      // the finger 1:1 while it is down. The question here is only whether a
      // tap leaves the wall COASTING, which it must not.
      await settle();
      row.dispatchEvent(new PointerEvent('pointerdown', o(x0)));
      row.dispatchEvent(new PointerEvent('pointermove', o(x0 + 3)));
      row.dispatchEvent(new PointerEvent('pointerup', o(x0 + 3)));
      await frames(2);
      const a3 = at();
      await frames(20);
      const tapDistance = Math.abs(at() - a3);

      return { coastDistance, crawlDistance, caughtDistance, tapDistance };
    });

    if (!reduced) {
      console.log('THE WALL COASTS AFTER A DRAG');
      cx(r.coastDistance > r.crawlDistance * 1.3,
        `a fast release carries further than a slow one of the same length: `
        + `${Math.round(r.coastDistance)}px vs ${Math.round(r.crawlDistance)}px`);
      cx(r.caughtDistance * 3 < r.coastDistance,
        `a press on the coasting wall arrests it: ${Math.round(r.caughtDistance)}px instead of `
        + `${Math.round(r.coastDistance)}px`);
      // Bounded by the pointer's OWN 3px of travel settling out, against 551px
      // for a real fling. Two orders of magnitude apart, so this still catches
      // a regression that starts flinging on taps; asserting near-zero would
      // only be asserting that easing does not exist.
      cx(r.tapDistance < 5 && r.tapDistance < r.coastDistance / 50,
        `a tap leaves no coast: ${r.tapDistance.toFixed(2)}px after release, `
        + `against ${Math.round(r.coastDistance)}px for a fling`);
    } else {
      console.log('AND NOT FOR SOMEBODY WHO ASKED FOR LESS MOTION');
      cx(Math.abs(r.coastDistance - r.crawlDistance) < 2,
        `under prefers-reduced-motion speed makes no difference: `
        + `${r.coastDistance.toFixed(1)}px vs ${r.crawlDistance.toFixed(1)}px`);
    }
    await page.close();
  }

  await browser.close();
  console.log(`\n${pass + fail} checks, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
