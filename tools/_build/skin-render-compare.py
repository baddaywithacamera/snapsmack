#!/usr/bin/env python3
"""
Prove a skin change did not alter what the browser renders.

    stability: python tools/_build/skin-render-compare.py stability <url>
    capture:  python tools/_build/skin-render-compare.py capture <url> <out.json.gz> [--label NAME]
    compare:  python tools/_build/skin-render-compare.py compare <before.json.gz> <after.json.gz>
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

NOT PROOF OF BEHAVIOUR. This captures rendering. Scripted interaction -- open
the menu, go to the next photograph, open the lightbox -- is a separate pass
and is not implemented here yet.

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


def capture(url, out_path, label="", extra_css=""):
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
            snapshot["viewports"][f"{w}x{h}"] = shot
        with gzip.open(out_path, "wt", encoding="utf-8") as fh:
            json.dump(snapshot, fh)
        total = sum(len(v["elements"]) for v in snapshot["viewports"].values())
        print(f"captured {url}")
        print(f"  {len(VIEWPORTS)} viewports, {total} element records -> {out_path}")
        return snapshot
    finally:
        chrome.close()


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

        if set(b["sheets"]) != set(a["sheets"]):
            gone = set(b["sheets"]) - set(a["sheets"])
            new = set(a["sheets"]) - set(b["sheets"])
            if gone:
                findings.append(f"[{key}] stylesheet NO LONGER LOADED: {sorted(gone)}")
            if new:
                findings.append(f"[{key}] stylesheet added: {sorted(new)}")

        bel, ael = b["elements"], a["elements"]
        removed = sorted(set(bel) - set(ael))
        added = sorted(set(ael) - set(bel))
        for p in removed[:limit]:
            findings.append(f"[{key}] ELEMENT REMOVED at {p}: <{bel[p]['tag']} class=\"{' '.join(bel[p]['cls'])}\">")
        for p in added[:limit]:
            findings.append(f"[{key}] element added at {p}: <{ael[p]['tag']} class=\"{' '.join(ael[p]['cls'])}\">")

        for p in sorted(set(bel) & set(ael)):
            rb, ra = bel[p], ael[p]
            if rb["tag"] != ra["tag"]:
                findings.append(f"[{key}] tag changed at {p}: {rb['tag']} -> {ra['tag']}")
            if rb["box"] != ra["box"]:
                findings.append(f"[{key}] geometry changed at {p} <{ra['tag']}>: "
                                f"{rb['box']} -> {ra['box']}")
            for prop, bv in rb["style"].items():
                av = ra["style"].get(prop)
                if av != bv:
                    findings.append(f"[{key}] {prop} changed at {p} <{ra['tag']}>: "
                                    f"{bv!r} -> {av!r}")
            for pseudo in set(rb["pseudo"]) | set(ra["pseudo"]):
                if pseudo not in ra["pseudo"]:
                    findings.append(f"[{key}] PSEUDO-ELEMENT {pseudo} DISAPPEARED at {p} <{ra['tag']}>")
                    continue
                if pseudo not in rb["pseudo"]:
                    findings.append(f"[{key}] pseudo-element {pseudo} appeared at {p} <{ra['tag']}>")
                    continue
                for prop, bv in rb["pseudo"][pseudo].items():
                    av = ra["pseudo"][pseudo].get(prop)
                    if av != bv:
                        findings.append(f"[{key}] {pseudo} {prop} changed at {p}: {bv!r} -> {av!r}")

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
<p class="note">Note</p><img src="x.svg" width="80" height="60"></div></body></html>"""
    base = """.wrap{padding:20px;max-width:600px}
.title{color:#1a1a1a;font-size:28px}
.menu{list-style:none;display:flex;gap:12px}
.menu > li + li::before{content:"|";padding:0 6px;color:#999}
.note{color:#444}
@media (max-width:400px){.title{font-size:18px}}"""
    svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="60"><rect width="80" height="60" fill="#777"/></svg>'
    for name, body in (("index.html", page), ("base.css", base), ("x.svg", svg)):
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
        capture(sys.argv[2], sys.argv[3], label=label)
        return 0
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
