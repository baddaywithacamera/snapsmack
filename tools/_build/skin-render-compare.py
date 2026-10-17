#!/usr/bin/env python3
"""
Prove a skin change did not alter what the browser renders.

    stability: python tools/_build/skin-render-compare.py stability <url>
    capture:  python tools/_build/skin-render-compare.py capture <url> <out.json.gz> [--label NAME] [--interact]
    compare:  python tools/_build/skin-render-compare.py compare <before.json.gz> <after.json.gz>
    controls: python tools/_build/skin-render-compare.py controls <url>
    selftest: python tools/_build/skin-render-compare.py selftest

WHY THIS EXISTS. Between 28 September and 15 October 2026 skin code was moved
into the CMS and rewritten on the way through. Class names changed, an empty
paragraph was dropped, a class moved off <body>, a hero image lost its size and
alignment modifiers. Each change broke a stylesheet nobody had opened, and
nothing compared the result against anything. Sean found the damage himself,
one page at a time, for a fortnight.

WHY NOT AN HTML DIFF. Removing an inline style attribute IS an HTML change, so
a "diff must be empty" gate is non-empty for every file the migration touches
and would be waived on day one. The invariant has to be what the browser
renders, not the markup that produced it.

WHAT IS COMPARED, and why each one is here:

  computed style, every property      the appearance itself
  ::before ::after ::marker, and      PHOTOGRAM's separator lives in ::before;
    WHETHER each exists                 a vanished pseudo-element is invisible
                                        to an element-only comparison
  :hover :focus :active               static capture misses every state
  bounding box per element            `auto` hides a layout change behind an
                                        unchanged property value
  the element tree itself             style equality over MATCHED elements
                                        passes silently when an element is
                                        deleted or renamed -- which is exactly
                                        the port failure that cost the fortnight
  several widths AND heights          the cut-off navigation bar was a HEIGHT
                                        fault; width-only testing misses it
  the stylesheet list and its hashes  three times in one night a local harness
                                        gave wrong numbers because it was
                                        missing a stylesheet the real page loads

  --* custom properties are EXCLUDED. They are the mechanism the migration uses,
  so they are expected to differ and would drown the signal.

ELEMENT IDENTITY is the structural path (nth-child chain), never the class
list, because class names are among the things being changed. Tag and classes
are recorded as DATA, so a rename shows up as a difference instead of silently
re-matching.

BASELINES NEED FIXED FIXTURE CONTENT, NOT LIVE POSTS. Run `stability <url>`
before trusting any URL as a baseline. Measured on craptasti.ca: two captures
of the SAME unchanged page differ in 330 places, because JIVE TURKEY
randomises its tile colours and sizes per request. A comparison against a
live feed reports differences that have nothing to do with the change.

INTERACTION. `capture --interact` and `controls <url>` add a second pass that
clicks things. It does NOT use a list of selectors per skin -- guessing at a
skin's class names is how an invented asset handle got ten skins blocked by the
packager. It DISCOVERS the controls instead: every button, [role=button],
[aria-controls], [aria-expanded], [data-*toggle*] and in-page anchor, named by
its structural path, the same identity scheme used for elements.

Each control is clicked from a freshly loaded page and the result captured. The
useful part is not that the states match -- it is that a control which changed
NOTHING is reported as dead. SCROLL's FILTER button drew a panel nothing was
listening to, and seven GRAMOFSMACK skins put cursor:zoom-in on an avatar that
no code zooms. Both are controls that look live and are not, and neither is
visible to a rendering comparison of a page sitting still.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import gzip
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time

import requests

try:
    from websockets.sync.client import connect as ws_connect
except Exception:  # pragma: no cover - surfaced at runtime with a clear message
    ws_connect = None


# Width x height. Heights matter: the RATIONAL GEO navigation bar was cut off
# because the page is exactly one viewport tall and the footer did not fit.
VIEWPORTS = [
    (1920, 1030),
    (1920, 800),
    (1280, 650),
    (768, 1024),
    (375, 812),
]

CHROME_CANDIDATES = [
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    "/usr/bin/google-chrome",
    "/usr/bin/chromium",
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
]


# Discover the controls rather than being told them. A per-skin selector list
# would have to be maintained by the same agent whose changes it is checking,
# and would quietly cover nothing the day a class name changed.
CONTROLS_JS = r"""
(() => {
  const path = (el) => {
    const bits = [];
    for (let n = el; n && n.nodeType === 1 && n !== document.documentElement; n = n.parentElement) {
      let i = 1;
      for (let s = n.previousElementSibling; s; s = s.previousElementSibling) i++;
      bits.unshift(n.tagName.toLowerCase() + ':' + i);
    }
    return bits.join('/');
  };
  const SEL = [
    'button', '[role="button"]', '[aria-controls]', '[aria-expanded]',
    'summary', 'label[for]', 'input[type="checkbox"]',
    '[data-lightbox-src]', 'a[href="#"]', 'a[href^="#"]',
  ];
  const extra = Array.from(document.querySelectorAll('*')).filter((el) =>
    Array.from(el.attributes).some((a) =>
      /^data-/.test(a.name) && /toggle|open|close|filter|menu|drawer|tab|panel/.test(a.name)));
  // Anything the cursor says is clickable. This is how the pass sees a FALSE
  // AFFORDANCE: seven GRAMOFSMACK skins put cursor:zoom-in on the avatar and
  // nothing in the repository zooms an avatar. Real links are excluded -- an
  // <a href> that navigates is doing its job, and clicking it would only
  // measure a different page.
  const cursory = Array.from(document.querySelectorAll('*')).filter((el) => {
    // Anything inside a real link, not just the link itself. Every tile on a
    // photoblog is an <img> inside an <a href>, and clicking the image
    // navigates exactly as clicking the anchor does. Those are not controls,
    // they are the feed, and with a cap on how many things get clicked they
    // would crowd out the menu and the filter button -- 27 of the 29 things
    // discovered on craptasti.ca were tile images.
    if (el.closest('a[href]:not([href^="#"])')) return false;
    const c = getComputedStyle(el).cursor;
    return c === 'pointer' || c === 'zoom-in' || c === 'zoom-out';
  });
  const seen = new Set();
  const out = [];
  for (const el of [...document.querySelectorAll(SEL.join(',')), ...extra, ...cursory]) {
    const r = el.getBoundingClientRect();
    // Only things a person could actually click. A zero-size or hidden
    // control is not a dead control, it is a control that is not there yet.
    if (r.width < 4 || r.height < 4) continue;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || cs.pointerEvents === 'none') continue;
    const k = path(el);
    if (seen.has(k)) continue;
    seen.add(k);
    out.push({
      path: k,
      tag: el.tagName.toLowerCase(),
      cls: (el.className && typeof el.className === 'string') ? el.className.trim().split(/\s+/) : [],
      label: (el.getAttribute('aria-label') || el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60),
      cursor: cs.cursor,
    });
  }
  return out;
})()
"""

# Click by structural path, not by selector: the path is what the capture
# records, so the thing clicked and the thing reported are the same element.
CLICK_JS = r"""
((want) => {
  const path = (el) => {
    const bits = [];
    for (let n = el; n && n.nodeType === 1 && n !== document.documentElement; n = n.parentElement) {
      let i = 1;
      for (let s = n.previousElementSibling; s; s = s.previousElementSibling) i++;
      bits.unshift(n.tagName.toLowerCase() + ':' + i);
    }
    return bits.join('/');
  };
  for (const el of document.querySelectorAll('*')) {
    if (path(el) !== want) continue;
    // DO NOT scroll to the element. el.click() is a synthetic DOM click and
    // needs no coordinates, but scrollIntoView moves the page, which changes
    // every bounding box on it -- so every control, dead or alive, would be
    // recorded as having changed something. The first version of this file
    // did scroll, and reported zero dead controls on a page that has one.
    el.click();
    return true;
  }
  return false;
})
"""


# Interaction is run at two viewports, not five. A control that behaves
# differently at 1280 than at 1920 is a thing worth knowing but not worth
# quadrupling a run that reloads the page once per control.
INTERACT_VIEWPORTS = [(1920, 1030), (375, 812)]

# A page can carry a lot of clickable things -- every tile is an anchor. Cap
# the pass so a feed of 200 photographs does not turn into 200 page loads, and
# say so in the snapshot rather than silently truncating.
MAX_CONTROLS = 14


def find_chrome():
    for path in CHROME_CANDIDATES:
        if os.path.isfile(path):
            return path
    found = shutil.which("chrome") or shutil.which("google-chrome") or shutil.which("chromium")
    if found:
        return found
    raise SystemExit("No Chrome found. Edit CHROME_CANDIDATES.")


def free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


# The capture runs in the page. Keep it self-contained: it is injected as one
# expression and must return JSON-serialisable data.
CAPTURE_JS = r"""
(() => {
  const PSEUDOS = ['::before', '::after', '::marker'];

  // Structural identity. Deliberately NOT the class list: class names are what
  // the migration changes, so matching on them would hide renames.
  const pathOf = (el) => {
    const parts = [];
    let node = el;
    while (node && node.nodeType === 1 && node !== document.documentElement) {
      const parent = node.parentElement;
      if (!parent) break;
      parts.unshift(Array.prototype.indexOf.call(parent.children, node));
      node = parent;
    }
    return parts.join('.');
  };

  const styleOf = (el, pseudo) => {
    const cs = getComputedStyle(el, pseudo || undefined);
    const out = {};
    for (let i = 0; i < cs.length; i++) {
      const prop = cs[i];
      if (prop.startsWith('--')) continue;   // the mechanism, not the result
      out[prop] = cs.getPropertyValue(prop);
    }
    return out;
  };

  const elements = {};
  for (const el of document.querySelectorAll('*')) {
    const tag = el.tagName.toLowerCase();
    // style and link are excluded as well as script: the mutation harness
    // injects a <style> element, and counting it would let every mutation
    // test "pass" on the injected element alone rather than on the CSS doing
    // anything. The stylesheet list is captured separately and deliberately.
    if (tag === 'script' || tag === 'noscript' || tag === 'style' || tag === 'link') continue;
    const r = el.getBoundingClientRect();
    const rec = {
      tag,
      cls: (el.getAttribute('class') || '').trim().split(/\s+/).filter(Boolean).sort(),
      id: el.id || '',
      box: [Math.round(r.x * 100) / 100, Math.round(r.y * 100) / 100,
            Math.round(r.width * 100) / 100, Math.round(r.height * 100) / 100],
      style: styleOf(el, null),
      pseudo: {}
    };
    for (const p of PSEUDOS) {
      const cs = getComputedStyle(el, p);
      const content = cs.getPropertyValue('content');
      // 'none' means the pseudo-element does not exist. Record existence
      // explicitly so a vanished ::before is a difference, not an absence.
      if (content && content !== 'none') {
        rec.pseudo[p] = styleOf(el, p);
      }
    }
    elements[pathOf(el)] = rec;
  }

  return {
    url: location.href,
    viewport: [innerWidth, innerHeight],
    scrollHeight: document.documentElement.scrollHeight,
    // The stylesheet witness. A missing sheet silently changes everything.
    sheets: Array.from(document.querySelectorAll('link[rel=stylesheet]')).map(l => l.href),
    inlineStyleAttributes: document.querySelectorAll('[style]').length,
    styleElements: document.querySelectorAll('style').length,
    elements
  };
})()
"""

# Applied before capture so two runs of the same page agree with each other.
FREEZE_JS = r"""
(() => {
  const s = document.createElement('style');
  s.textContent = '*,*::before,*::after{animation-duration:0s !important;' +
                  'animation-delay:0s !important;transition-duration:0s !important;' +
                  'transition-delay:0s !important;caret-color:transparent !important}';
  document.head.appendChild(s);
  // Reveal anything waiting on a fade engine, so a slow script cannot make two
  // runs disagree.
  for (const img of document.images) { try { img.style.opacity = '1'; } catch (e) {} }
  return true;
})()
"""

SETTLE_JS = r"""
(async () => {
  // Every wait is capped. The first version awaited onload/onerror for every
  // image with no deadline, and a lazy image that never loads meant the
  // promise never settled: a capture against a live site hung for over eight
  // minutes and produced no file, no error and no clue. A wait without a
  // timeout is a hang waiting to happen.
  const cap = (p, ms) => Promise.race([p, new Promise(r => setTimeout(r, ms))]);
  if (document.fonts && document.fonts.ready) {
    try { await cap(document.fonts.ready, 5000); } catch (e) {}
  }
  await cap(Promise.all(Array.from(document.images).map(img =>
    img.complete ? null : new Promise(res => { img.onload = img.onerror = res; }))), 15000);
  await new Promise(r => setTimeout(r, 400));
  return true;
})()
"""


class Chrome:
    def __init__(self):
        if ws_connect is None:
            raise SystemExit("python 'websockets' package is required")
        self.port = free_port()
        self.profile = tempfile.mkdtemp(prefix="snap-render-cmp-")
        self.proc = subprocess.Popen(
            [find_chrome(), "--headless=new", "--disable-gpu", "--no-first-run",
             "--no-default-browser-check", "--hide-scrollbars",
             "--force-device-scale-factor=1",
             f"--user-data-dir={self.profile}",
             f"--remote-debugging-port={self.port}", "about:blank"],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.ws = None
        self._id = 0
        for _ in range(100):
            try:
                tabs = requests.get(f"http://127.0.0.1:{self.port}/json", timeout=1).json()
                page = next(t for t in tabs if t["type"] == "page")
                self.ws = ws_connect(page["webSocketDebuggerUrl"], max_size=256 * 1024 * 1024)
                break
            except Exception:
                time.sleep(0.2)
        if self.ws is None:
            raise SystemExit("Could not attach to Chrome")

    def send(self, method, **params):
        self._id += 1
        self.ws.send(json.dumps({"id": self._id, "method": method, "params": params}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get("id") == self._id:
                if "error" in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get("result", {})

    def evaluate(self, expression, await_promise=False):
        res = self.send("Runtime.evaluate", expression=expression, returnByValue=True,
                        awaitPromise=await_promise)
        if res.get("exceptionDetails"):
            raise RuntimeError(res["exceptionDetails"].get("text", "JS error"))
        return res["result"].get("value")

    def close(self):
        try:
            if self.ws:
                self.ws.close()
        finally:
            self.proc.terminate()
            try:
                self.proc.wait(timeout=10)
            except Exception:
                self.proc.kill()
            shutil.rmtree(self.profile, ignore_errors=True)


def capture(url, out_path, label="", extra_css="", do_interact=False):
    chrome = Chrome()
    try:
        chrome.send("Page.enable")
        chrome.send("Runtime.enable")
        snapshot = {"label": label, "url": url, "captured": time.strftime("%Y-%m-%d %H:%M:%S"),
                    "viewports": {}}
        for w, h in VIEWPORTS:
            chrome.send("Emulation.setDeviceMetricsOverride", width=w, height=h,
                        deviceScaleFactor=1, mobile=(w < 768))
            chrome.send("Page.navigate", url=url)
            time.sleep(2.5)
            chrome.evaluate(SETTLE_JS, await_promise=True)
            chrome.evaluate(FREEZE_JS)
            if extra_css:
                chrome.evaluate(
                    "(()=>{const s=document.createElement('style');"
                    f"s.textContent={json.dumps(extra_css)};"
                    "document.head.appendChild(s);return true})()")
                time.sleep(0.3)
            shot = chrome.evaluate(CAPTURE_JS)
            # A capture that produced nothing is a FAILURE, not a baseline.
            # An empty or near-empty snapshot compared against anything later
            # reports "no difference", which is the most dangerous possible
            # answer from a tool whose whole job is to notice differences.
            if not isinstance(shot, dict):
                raise SystemExit(f"capture failed at {w}x{h}: no data returned")
            if len(shot.get("elements") or {}) < 10:
                raise SystemExit(f"capture failed at {w}x{h}: only "
                                 f"{len(shot.get('elements') or {})} elements -- "
                                 f"the page did not render")
            if not shot.get("sheets"):
                raise SystemExit(f"capture failed at {w}x{h}: the page loaded no "
                                 f"stylesheets; a render without CSS proves nothing")
            if do_interact and (w, h) in INTERACT_VIEWPORTS:
                shot["interaction"] = interact(chrome, url)
            snapshot["viewports"][f"{w}x{h}"] = shot
        with gzip.open(out_path, "wt", encoding="utf-8") as fh:
            json.dump(snapshot, fh)
        total = sum(len(v["elements"]) for v in snapshot["viewports"].values())
        print(f"captured {url}")
        print(f"  {len(VIEWPORTS)} viewports, {total} element records -> {out_path}")
        if do_interact:
            for key, v in snapshot["viewports"].items():
                it = v.get("interaction")
                if not it:
                    continue
                ctls = it["controls"]
                dead = [c for c in ctls.values() if c["clicked"] and not c["changed"]]
                miss = [c for c in ctls.values() if not c["clicked"]]
                print(f"  [{key}] {len(ctls)} control(s) clicked, "
                      f"{len(dead)} changed nothing, {len(miss)} could not be clicked")
                for c in dead:
                    print(f"      DEAD CONTROL <{c['tag']}> {c['label']!r} "
                          f"cursor:{c['cursor']} at {c['path']}")
                for c in miss:
                    print(f"      NOT CLICKED (tool failure) {c['path']}")
                if it["truncated"]:
                    print(f"      note: more than {MAX_CONTROLS} controls on the page; "
                          f"only the first {MAX_CONTROLS} were exercised")
        return snapshot
    finally:
        chrome.close()


def interact(chrome, url):
    """
    Click each discovered control from a freshly loaded page and record the
    result. Returns {path: {...}} keyed the same way elements are.

    The important finding is a control that changes NOTHING. That is either a
    dead control -- the button draws a panel nothing is listening to -- or a
    click this tool failed to deliver. The two are distinguished: a click that
    could not be dispatched is recorded as `clicked: false` and is a TOOL
    failure, while a dispatched click that changed nothing is a FINDING about
    the page. Reporting them as one thing would let every broken button hide
    behind "maybe the harness missed it".
    """
    controls = chrome.evaluate(CONTROLS_JS)
    if not isinstance(controls, list):
        raise SystemExit("control discovery returned no list -- the page did not run JS")
    truncated = len(controls) > MAX_CONTROLS
    controls = controls[:MAX_CONTROLS]
    steps = {}
    for ctl in controls:
        chrome.send("Page.navigate", url=url)
        time.sleep(1.5)
        chrome.evaluate(SETTLE_JS, await_promise=True)
        # Whether a click did anything is judged against a shot taken in the
        # SAME page load, not against the base capture from an earlier load.
        # On a page that renders differently per request -- JIVE TURKEY
        # randomises its tile colours and sizes, which is 330 differences
        # between two captures of an unchanged page -- comparing across loads
        # makes every control look alive and hides every dead one. That is the
        # failure mode this whole tool exists to avoid, so it must not be
        # built into the part that looks for dead controls.
        chrome.evaluate(FREEZE_JS)
        pre = chrome.evaluate(CAPTURE_JS)
        if not isinstance(pre, dict) or len(pre.get("elements") or {}) < 10:
            raise SystemExit(f"pre-click capture failed for {ctl['path']}")
        clicked = chrome.evaluate(
            "(" + CLICK_JS.strip() + ")(" + json.dumps(ctl["path"]) + ")")
        if not clicked:
            steps[ctl["path"]] = dict(ctl, clicked=False, shot=None, changed=None)
            continue
        time.sleep(0.8)
        chrome.evaluate(FREEZE_JS)
        shot = chrome.evaluate(CAPTURE_JS)
        if not isinstance(shot, dict) or len(shot.get("elements") or {}) < 10:
            # Same rule as the base capture: nothing captured is a failure, not
            # a baseline. An empty post-click state compared to anything later
            # would report "no difference".
            raise SystemExit(f"post-click capture failed for {ctl['path']}")
        steps[ctl["path"]] = dict(ctl, clicked=True, shot=shot,
                                  changed=bool(_state_diff(pre, shot)))
    return {"controls": steps, "truncated": truncated,
            "discovered": len(controls) + (1 if truncated else 0)}


def _state_diff(b, a, limit=40, key=""):
    """One rendered state against another. Shared by the base comparison and
    by every interaction step, so a step is held to the same standard."""
    findings = []
    if set(b["sheets"]) != set(a["sheets"]):
        gone = set(b["sheets"]) - set(a["sheets"])
        new = set(a["sheets"]) - set(b["sheets"])
        if gone:
            findings.append(f"{key}stylesheet NO LONGER LOADED: {sorted(gone)}")
        if new:
            findings.append(f"{key}stylesheet added: {sorted(new)}")
    bel, ael = b["elements"], a["elements"]
    for pth in sorted(set(bel) - set(ael))[:limit]:
        findings.append(f"{key}ELEMENT REMOVED at {pth}: "
                        f"<{bel[pth]['tag']} class=\"{' '.join(bel[pth]['cls'])}\">")
    for pth in sorted(set(ael) - set(bel))[:limit]:
        findings.append(f"{key}element added at {pth}: "
                        f"<{ael[pth]['tag']} class=\"{' '.join(ael[pth]['cls'])}\">")
    for pth in sorted(set(bel) & set(ael)):
        rb, ra = bel[pth], ael[pth]
        if rb["tag"] != ra["tag"]:
            findings.append(f"{key}tag changed at {pth}: {rb['tag']} -> {ra['tag']}")
        if rb["box"] != ra["box"]:
            findings.append(f"{key}geometry changed at {pth} <{ra['tag']}>: "
                            f"{rb['box']} -> {ra['box']}")
        for prop, bv in rb["style"].items():
            av = ra["style"].get(prop)
            if av != bv:
                findings.append(f"{key}{prop} changed at {pth} <{ra['tag']}>: {bv!r} -> {av!r}")
        for pseudo in set(rb["pseudo"]) | set(ra["pseudo"]):
            if pseudo not in ra["pseudo"]:
                findings.append(f"{key}PSEUDO-ELEMENT {pseudo} DISAPPEARED at {pth} <{ra['tag']}>")
                continue
            if pseudo not in rb["pseudo"]:
                findings.append(f"{key}pseudo-element {pseudo} appeared at {pth} <{ra['tag']}>")
                continue
            for prop, bv in rb["pseudo"][pseudo].items():
                av = ra["pseudo"][pseudo].get(prop)
                if av != bv:
                    findings.append(f"{key}{pseudo} {prop} changed at {pth}: {bv!r} -> {av!r}")
    return findings


def load(path):
    with gzip.open(path, "rt", encoding="utf-8") as fh:
        return json.load(fh)


def compare(before_path, after_path, limit=40):
    before, after = load(before_path), load(after_path)
    findings = []

    for key in before["viewports"]:
        b = before["viewports"][key]
        a = after["viewports"].get(key)
        if a is None:
            findings.append(f"[{key}] viewport missing from the after capture")
            continue

        findings += _state_diff(b, a, limit, key=f"[{key}] ")

        # Interaction, when both captures have it. A capture without the pass
        # is not silently treated as "no controls" -- that would turn a missing
        # pass into a clean bill of health.
        bi, ai = b.get("interaction"), a.get("interaction")
        if bi is None and ai is None:
            continue
        if bi is None or ai is None:
            side = "before" if bi is None else "after"
            findings.append(f"[{key}] the {side} capture has no interaction pass, so the "
                            f"controls were NOT compared at this viewport. Re-capture "
                            f"both with --interact.")
            continue

        bc, ac = bi["controls"], ai["controls"]
        for pth in sorted(set(bc) - set(ac)):
            c = bc[pth]
            findings.append(f"[{key}] CONTROL GONE: <{c['tag']}> {c['label']!r} at {pth}")
        for pth in sorted(set(ac) - set(bc)):
            c = ac[pth]
            findings.append(f"[{key}] control added: <{c['tag']}> {c['label']!r} at {pth}")

        for pth in sorted(set(bc) & set(ac)):
            cb, ca = bc[pth], ac[pth]
            if cb["clicked"] and not ca["clicked"]:
                findings.append(f"[{key}] CONTROL NO LONGER CLICKABLE: <{ca['tag']}> "
                                f"{ca['label']!r} at {pth}")
                continue
            if not cb["clicked"] or not ca["clicked"]:
                continue
            if cb["changed"] and not ca["changed"]:
                findings.append(f"[{key}] CONTROL WENT DEAD: <{ca['tag']}> {ca['label']!r} "
                                f"at {pth} did something before and now changes nothing")
                continue
            if not cb["changed"] and ca["changed"]:
                findings.append(f"[{key}] control came alive: <{ca['tag']}> {ca['label']!r} "
                                f"at {pth} changed nothing before and now does")
                continue
            if cb["shot"] and ca["shot"]:
                findings += _state_diff(cb["shot"], ca["shot"], limit,
                                        key=f"[{key}] after clicking {ca['label']!r}: ")

    return findings


def report(findings, limit=40):
    if not findings:
        print("IDENTICAL: no rendered difference at any viewport.")
        return 0
    print(f"{len(findings)} rendered difference(s):")
    for line in findings[:limit]:
        print("  - " + line)
    if len(findings) > limit:
        print(f"  ... and {len(findings) - limit} more")
    return 1


def selftest():
    """
    Mutation tests. The comparator is not trusted until it proves it FAILS on
    each kind of change it claims to catch. Written because the tests an agent
    writes to check its own work went green all week while certifying drift.
    """
    import http.server, threading, functools

    root = tempfile.mkdtemp(prefix="snap-cmp-selftest-")
    # The viewport meta is required. Without it Chrome's mobile emulation uses
    # a 980px layout viewport, so a max-width media query never matches and the
    # media-query mutation silently passes. Every real skin sets this; the
    # first version of this fixture did not, and the test was meaningless.
    page = """<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="base.css"></head>
<body><div class="wrap"><h1 class="title">Title</h1>
<ul class="menu"><li>One</li><li>Two</li></ul>
<p class="note">Note</p><img src="x.svg" width="80" height="60">
<button class="live" aria-controls="panel">Filter</button>
<div id="panel" class="panel">Panel</div>
<div class="tall">tall</div>
<button class="dead">Looks Clickable</button></div>
<script src="app.js"></script></body></html>"""
    base = """.wrap{padding:20px;max-width:600px}
.title{color:#1a1a1a;font-size:28px}
.menu{list-style:none;display:flex;gap:12px}
.menu > li + li::before{content:"|";padding:0 6px;color:#999}
.note{color:#444}
.live,.dead{cursor:pointer;font:14px sans-serif;padding:6px 10px}
.panel{display:none;height:40px;background:#eee}
.panel.open{display:block}
/* The dead button sits BELOW THE FOLD on purpose. The first version of the
   click helper called scrollIntoView first, which moves the page and so
   changes every bounding box on it -- making a dead control look alive. With
   the page this tall, that mistake fails this fixture instead of shipping. */
.tall{height:2400px}
@media (max-width:400px){.title{font-size:18px}}"""
    # One control that does something and one that only looks like it does.
    # The dead button is the SCROLL FILTER bug and the GRAMOFSMACK avatar
    # cursor in miniature: pointer cursor, no handler, perfect screenshot.
    app = ("""document.querySelector('.live').addEventListener('click',"""
           """ () => document.getElementById('panel').classList.add('open'));""")
    svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="60"><rect width="80" height="60" fill="#777"/></svg>'
    for name, body in (("index.html", page), ("base.css", base), ("x.svg", svg),
                       ("app.js", app)):
        with open(os.path.join(root, name), "w", encoding="utf-8") as fh:
            fh.write(body)

    port = free_port()
    handler = functools.partial(http.server.SimpleHTTPRequestHandler, directory=root)
    httpd = http.server.ThreadingHTTPServer(("127.0.0.1", port), handler)
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    url = f"http://127.0.0.1:{port}/index.html"

    try:
        print("capturing baseline...")
        capture(url, os.path.join(root, "before.json.gz"), label="baseline")

        mutations = [
            ("no change at all", None, False),
            ("a colour changes", ".title{color:#c8102e}", True),
            ("a pseudo-element disappears", '.menu > li + li::before{content:none}', True),
            ("a media query result changes", "@media (max-width:400px){.title{font-size:40px}}", True),
            ("an element is removed", "REMOVE_ELEMENT", True),
            ("geometry changes only", ".wrap{padding:21px}", True),
            ("a hidden element's style changes", ".note{letter-spacing:3px}", True),
        ]
        passed = failed = 0
        for name, css, should_differ in mutations:
            after = os.path.join(root, "after.json.gz")
            if css == "REMOVE_ELEMENT":
                capture(url, after, label=name,
                        extra_css="")  # removal is done in the page below
                # redo with the element actually gone
                with open(os.path.join(root, "index.html"), "w", encoding="utf-8") as fh:
                    fh.write(page.replace('<p class="note">Note</p>', ''))
                capture(url, after, label=name)
                with open(os.path.join(root, "index.html"), "w", encoding="utf-8") as fh:
                    fh.write(page)
            else:
                capture(url, after, label=name, extra_css=css or "")
            diffs = compare(os.path.join(root, "before.json.gz"), after)
            detected = bool(diffs)
            ok = (detected == should_differ)
            print(f"  {'PASS' if ok else 'FAIL'}  {name}: "
                  f"{'detected' if detected else 'no difference'} "
                  f"({len(diffs)} finding(s))")
            if ok:
                passed += 1
            else:
                failed += 1
                for d in diffs[:3]:
                    print("        " + d)
        # ---------------------------------------------------------------
        # The interaction pass gets its own mutations. It is the part most
        # able to pass by doing nothing: a click that never lands produces a
        # post-click state identical to the base, which an unguarded
        # comparison reads as "no difference".
        # ---------------------------------------------------------------
        print("")
        print("interaction mutations (slower: reloads once per control)...")
        ibefore = os.path.join(root, "ibefore.json.gz")
        snap = capture(url, ibefore, label="interaction baseline", do_interact=True)

        # The fixture has exactly one live and one dead control. If the pass
        # cannot tell them apart on a page written to have one of each, nothing
        # it says about a real skin means anything.
        vp = snap["viewports"]["1920x1030"]["interaction"]["controls"]
        live = [c for c in vp.values() if "live" in c["cls"]]
        dead = [c for c in vp.values() if "dead" in c["cls"]]
        base_ok = True
        if len(live) != 1 or not live[0]["clicked"] or not live[0]["changed"]:
            print("  FAIL  the live control was not seen to do anything")
            base_ok = False
        if len(dead) != 1 or not dead[0]["clicked"] or dead[0]["changed"]:
            print("  FAIL  the dead control was not reported as dead")
            base_ok = False
        if base_ok:
            print("  PASS  told the live control from the dead one")
            passed += 1
        else:
            failed += 1

        # The case that caught a real mistake in this file. The first version
        # judged "did the click do anything" against the base capture from an
        # EARLIER page load. On a page that renders differently per request --
        # JIVE TURKEY randomises tile colours and sizes, 330 differences
        # between two captures of an unchanged page -- that makes every control
        # look alive and hides every dead one. So: make the fixture randomise,
        # and require the dead button to still be called dead.
        with open(os.path.join(root, "app.js"), "w", encoding="utf-8") as fh:
            fh.write(app + chr(10) + "document.querySelector('.note').style.color ="
                     " 'rgb(' + [1,2,3].map(() => Math.floor(Math.random()*255))"
                     ".join(',') + ')';")
        rnd = os.path.join(root, "irand.json.gz")
        rsnap = capture(url, rnd, label="randomising", do_interact=True)
        rc = rsnap["viewports"]["1920x1030"]["interaction"]["controls"]
        rlive = [c for c in rc.values() if "live" in c["cls"]]
        rdead = [c for c in rc.values() if "dead" in c["cls"]]
        if (len(rdead) == 1 and rdead[0]["clicked"] and not rdead[0]["changed"]
                and len(rlive) == 1 and rlive[0]["changed"]):
            print("  PASS  still told them apart on a page that randomises per load")
            passed += 1
        else:
            print("  FAIL  randomisation made the dead control look alive -- the "
                  "verdict is being taken across page loads again")
            failed += 1
        with open(os.path.join(root, "app.js"), "w", encoding="utf-8") as fh:
            fh.write(app)

        imut = [
            # (name, new app.js, new index.html, should_differ)
            ("the live control goes dead", "", None, True),
            ("the dead control is removed", None,
             page.replace('<button class="dead">Looks Clickable</button>', ''), True),
            ("nothing changes", None, None, False),
        ]
        for name, newjs, newhtml, should_differ in imut:
            if newjs is not None:
                with open(os.path.join(root, "app.js"), "w", encoding="utf-8") as fh:
                    fh.write(newjs)
            if newhtml is not None:
                with open(os.path.join(root, "index.html"), "w", encoding="utf-8") as fh:
                    fh.write(newhtml)
            iafter = os.path.join(root, "iafter.json.gz")
            capture(url, iafter, label=name, do_interact=True)
            diffs = [d for d in compare(ibefore, iafter)
                     if "CONTROL" in d or "after clicking" in d or "control " in d]
            detected = bool(diffs)
            ok = (detected == should_differ)
            print(f"  {'PASS' if ok else 'FAIL'}  {name}: "
                  f"{'detected' if detected else 'no difference'} "
                  f"({len(diffs)} interaction finding(s))")
            if ok:
                passed += 1
            else:
                failed += 1
                for d in diffs[:3]:
                    print("        " + d)
            # Put the fixture back between mutations, or each one inherits the
            # last. An earlier version of this file did not, and every mutation
            # after the first was testing a page nobody had described.
            with open(os.path.join(root, "app.js"), "w", encoding="utf-8") as fh:
                fh.write(app)
            with open(os.path.join(root, "index.html"), "w", encoding="utf-8") as fh:
                fh.write(page)

        print(f"\nmutation tests: {passed} passed, {failed} failed")
        return 0 if failed == 0 else 1
    finally:
        httpd.shutdown()
        shutil.rmtree(root, ignore_errors=True)


def main():
    if len(sys.argv) < 2:
        print(__doc__.strip().split("\n\n")[1])
        return 2
    mode = sys.argv[1]
    if mode == "stability":
        # Capture the same unchanged URL twice and report whether it renders
        # deterministically. A page that does not is unusable as a baseline.
        if len(sys.argv) < 3:
            print("stability <url>")
            return 2
        url = sys.argv[2]
        tmp = tempfile.mkdtemp(prefix="snap-stability-")
        try:
            a, b = os.path.join(tmp, "a.json.gz"), os.path.join(tmp, "b.json.gz")
            capture(url, a, label="stability A")
            capture(url, b, label="stability B")
            diffs = compare(a, b)
            if not diffs:
                print("")
                print("STABLE: two captures of the unchanged page are identical. "
                      "This URL may be used as a baseline.")
                return 0
            print("")
            print(f"NOT STABLE: {len(diffs)} difference(s) between two captures of "
                  f"the SAME unchanged page.")
            for line in diffs[:6]:
                print("  - " + line)
            print("")
            print("This URL CANNOT be used as a baseline. The page renders")
            print("differently per request -- randomised tile colours, rotating")
            print("content, or a shuffled feed. Point the comparator at fixed")
            print("fixture content instead of live posts.")
            return 1
        finally:
            shutil.rmtree(tmp, ignore_errors=True)

    if mode == "capture":
        if len(sys.argv) < 4:
            print("capture <url> <out.json.gz> [--label NAME]")
            return 2
        label = ""
        if "--label" in sys.argv:
            label = sys.argv[sys.argv.index("--label") + 1]
        capture(sys.argv[2], sys.argv[3], label=label,
                do_interact="--interact" in sys.argv)
        return 0
    if mode == "controls":
        # One capture, interaction only, nothing to compare against. Answers
        # "does every button on this page do something" on its own.
        if len(sys.argv) < 3:
            print("controls <url>")
            return 2
        tmp = tempfile.mkdtemp(prefix="snap-controls-")
        try:
            snap = capture(sys.argv[2], os.path.join(tmp, "c.json.gz"),
                           label="controls", do_interact=True)
            dead = []
            for key, v in snap["viewports"].items():
                it = v.get("interaction")
                if not it:
                    continue
                dead += [(key, c) for c in it["controls"].values()
                         if c["clicked"] and not c["changed"]]
            print("")
            if not dead:
                print("Every control that could be clicked changed something.")
                return 0
            print(f"{len(dead)} control(s) changed nothing when clicked:")
            for key, c in dead:
                print(f"  [{key}] <{c['tag']}> {c['label']!r} cursor:{c['cursor']} "
                      f"class=\"{' '.join(c['cls'])}\"")
            print("")
            print("A control that changes nothing is either wired to code that is")
            print("not loaded, or is pure styling pretending to be a control. Both")
            print("look fine in a screenshot and both are broken for the visitor.")
            return 1
        finally:
            shutil.rmtree(tmp, ignore_errors=True)
    if mode == "compare":
        if len(sys.argv) < 4:
            print("compare <before.json.gz> <after.json.gz>")
            return 2
        return report(compare(sys.argv[2], sys.argv[3]))
    if mode == "selftest":
        return selftest()
    print(f"unknown mode: {mode}")
    return 2


if __name__ == "__main__":
    sys.exit(main())

# ===== SNAPSMACK EOF =====
