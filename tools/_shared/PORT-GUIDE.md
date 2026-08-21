<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line must be the canonical HTML-comment SNAPSMACK EOF marker.
-->

# Porting a SnapSmack Windows tkinter tool to the Linux Chrome/Blink runtime

**Goal:** every desktop tool runs on Linux with its window drawn by Chromium (Blink)
instead of tkinter, on top of the shared `snap_blink` runtime. Same behaviour, new skin.

## The rules (do not violate)

1. **Reuse the tool's real work.** The Python that talks to the site, reads/writes
   files, calls Gemini, hits the Hub, builds carousels — keep it. Import the tool's
   existing helper modules where you can. You are replacing the **window** (tkinter
   widgets), not the **work**.
2. **No tkinter in the port.** If logic and tkinter are tangled in one function,
   split the logic into a plain function that returns data and raises on error.
3. **Stdlib + the tool's existing deps only.** `snap_blink` is stdlib-only. Do not
   add PyQt/CEF/Flask/Eel. If the tool already needs `requests`/`Pillow`/etc., list
   those in `requirements.txt`.
4. **Preserve the shared-library contract.** Config, creds, logs, profiles, prompts
   still go through `snap_home`/`snap_creds`/`snap_profiles`/`snap_prompts`. Do not
   invent a new config location. On Linux `snap_blink.App()` sets `SNAPSMACK_HOME`
   to `~/snapsmack` if unset.
5. **Keep every feature.** Count the buttons/menus/fields in the tkinter UI. The web
   UI must expose the same actions. If something can't be ported cleanly, wire it and
   leave a `TODO(port):` note — never silently drop it.
6. **EOF marker.** Every source file ends with the SnapSmack EOF sentinel for its
   comment style (`# ===== SNAPSMACK EOF =====` for .py, `<!-- ===== SNAPSMACK EOF ===== -->`
   for .html/.md, `/* ===== SNAPSMACK EOF ===== */` for .css/.js).
7. **Honesty.** You cannot launch Linux Chromium from the build box. Make imports
   clean and the port faithful; do not claim it was "tested on Linux".

## What to produce, per tool, under `tools/<tool>/linux/`

```
linux/
  app.py            # imports snap_blink + the tool's logic; registers @app.api handlers; app.run()
  web/
    index.html      # the window; <script src="/snap_blink.js"></script> then your app.js
    app.js          # calls blink.call('handler', ...args); renders results
    style.css       # dark, desktop-only look (these tools never serve phones)
  run.sh            # chmod-friendly launcher: sets PYTHONPATH to _shared, runs app.py
  requirements.txt  # only real pip deps (blank if pure stdlib)
  README.md         # what it is, how to run on Linux, feature parity vs the tkinter version
```

## The runtime contract (from snap_blink.py)

- `app = snap_blink.App(tool="<key>", title="<Name>", web_dir=os.path.join(HERE,"web"))`
- `@app.api` on a function exposes it to the page as `blink.call('<fn_name>', ...args)`.
- Handlers get **positional** args in the order JS passed them; return anything
  JSON-serialisable; raise to send an error the page can show.
- `app.run()` starts a localhost-only random-port server, opens a Chromium `--app`
  window, and blocks until the user closes it.
- The page loads `/snap_blink.js` (auto-served) which defines `window.blink.call`.

## Complete worked skeleton — copy this shape

### `linux/app.py`
```python
#!/usr/bin/env python3
"""<TOOL> — Linux Chrome/Blink port. Window is HTML; the work is the original Python."""
import os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.dirname(HERE)                     # tools/<tool>/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")     # tools/_shared/
for p in (SHARED, TOOL_ROOT):
    sys.path.insert(0, os.path.abspath(p))

import snap_blink
# import the ORIGINAL logic modules, e.g.:  from <tool>_core import list_sites, post_photo
# If the logic lives in main.py tangled with tkinter, factor the pure functions out
# into a new <tool>_core.py that both could use, and import from there.

app = snap_blink.App(tool="<tool>", title="<TOOL>", web_dir=os.path.join(HERE, "web"))

@app.api
def load_state():
    """Everything the page needs on open (sites, saved settings, ...)."""
    return {"sites": [], "settings": {}}   # replace with real logic

@app.api
def do_thing(site, value):
    """One user action. Return a JSON result; raise on failure."""
    ...
    return {"ok": True}

if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
```

### `linux/web/index.html`
```html
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><TOOL></title>
<link rel="stylesheet" href="style.css">
</head><body>
  <header><h1><TOOL></h1></header>
  <main id="app">Loading…</main>
  <div id="log" class="log" aria-live="polite"></div>
  <script src="/snap_blink.js"></script>
  <script src="app.js"></script>
</body></html>
<!-- ===== SNAPSMACK EOF ===== -->
```

### `linux/web/app.js`
```javascript
/* <TOOL> — window logic. All Python work is reached through blink.call(). */
const $ = (s) => document.querySelector(s);
function log(msg, kind) {
  const el = document.createElement("div");
  el.className = "line " + (kind || "");
  el.textContent = msg;
  $("#log").prepend(el);
}
async function boot() {
  try {
    const state = await blink.call("load_state");
    render(state);
  } catch (e) { log("Could not load: " + e.message, "err"); }
}
function render(state) {
  // build the same controls the tkinter window had
}
document.addEventListener("DOMContentLoaded", boot);
/* ===== SNAPSMACK EOF ===== */
```

### `linux/web/style.css`
```css
/* <TOOL> — dark desktop UI. Big hit targets (Parkinson's): min 44px, guard SEND/POST. */
:root { --bg:#14161a; --panel:#1d2027; --ink:#e9edf2; --muted:#9aa4b2; --accent:#4f8cff; --danger:#e5484d; --ok:#3fb950; }
* { box-sizing: border-box; }
body { margin:0; font:15px/1.4 system-ui,Segoe UI,Roboto,sans-serif; background:var(--bg); color:var(--ink); }
header { padding:14px 20px; background:var(--panel); border-bottom:1px solid #000; }
button { min-height:44px; padding:0 18px; border:0; border-radius:8px; background:var(--accent); color:#fff; font-size:15px; cursor:pointer; }
button.danger { background:var(--danger); }
.log { position:fixed; bottom:0; left:0; right:0; max-height:30vh; overflow:auto; background:#0c0d10; padding:8px 20px; font:13px/1.5 monospace; }
.log .err { color:var(--danger); } .log .ok { color:var(--ok); }
/* ===== SNAPSMACK EOF ===== */
```

### `linux/run.sh`
```bash
#!/usr/bin/env bash
# <TOOL> — launch the Chrome/Blink port on Linux.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"
export PYTHONPATH="$HERE/../..//_shared:$HERE/..:${PYTHONPATH:-}"
exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
```

## Parity check before you finish
- List every tkinter button, menu item, checkbox, entry field in the original.
- Map each to a control + `blink.call` in the web UI. None missing.
- The tool still reads creds/config/profiles from the shared library, not a new path.
- `python3 -c "import ast; ast.parse(open('linux/app.py').read())"` parses clean.
- README states plainly: ported, imports verified, NOT yet run on Linux hardware.

<!-- ===== SNAPSMACK EOF ===== -->
