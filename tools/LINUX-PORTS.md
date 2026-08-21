<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line must be the canonical HTML-comment SNAPSMACK EOF marker.
-->

# SnapSmack desktop tools — Linux (Chrome/Blink) ports

The desktop tool family used to be Windows apps drawn with tkinter. This is the
Linux family: each tool is a **Python app whose window is drawn by Chromium (the
Blink engine)**, on top of one shared runtime. Same work, new window.

## How it works

- **`_shared/snap_blink.py`** — the shared runtime. Starts a localhost-only,
  random-port web server, exposes the tool's Python functions to the page over a
  token-guarded JSON bridge, and opens the UI in a Chromium `--app` window (real
  Blink). Stdlib only — no tkinter, PyQt, CEF, Flask, or Eel.
- **`_shared/PORT-GUIDE.md`** — the exact contract + a copyable skeleton every port
  follows.
- **`linux/<tool>/`** — each tool's port: `app.py` (bridge to the tool's original
  Python logic) + `web/` (HTML/CSS/JS window) + `run.sh` + `requirements.txt` +
  `README.md`.

## Running a tool on Linux

Requirements: `python3` and a Chromium/Chrome/Brave/Edge binary on PATH.

```bash
cd tools
./run-tool.sh                 # list every tool
./run-tool.sh sybu            # launch one in a Blink window
```

Or run a tool directly:

```bash
SNAPSMACK_HOME=~/snapsmack PYTHONPATH=tools/_shared:tools/sybu python3 tools/linux/sybu/app.py
```

`SNAPSMACK_HOME` is the shared data root (creds, config, per-site mirror, logs);
it defaults to `~/snapsmack` on Linux. The shared-library contract
(`snap_home`/`snap_creds`/`snap_profiles`/`snap_prompts`) is unchanged — set a blog
up in one tool and the rest find it.

## Setting up the Linux laptop (do this once)

```bash
sudo apt install -y chromium zenity        # a Blink browser + the native file-dialog
pip3 install -r tools/linux/requirements-all.txt
```

Then `cd tools && ./run-tool.sh <tool>`. GYSS and OH SNAP need no pip deps; the
others need what's in `requirements-all.txt` (a superset of every tool's needs).

**Import readiness (checked, each tool isolated in its own process as it really
runs):** all 14 import clean. Note: each tool uses generic module names (`config`,
`poster`, `fleet`), so tools must run one-per-process — which is exactly the desktop
model (each is its own window). Never import two into one process.

**In-app Help parity gap:** the ports carried each tool's Help across for 7 of 14
(coldsnap, sybu, flkr-fckr, suyb, smack-your-mouth, tyswy, unzucker). Shots Fired,
SmackAttack Scanner, and SmackPress had a Help button in the Windows build that the
port did NOT carry over — restore before calling those three finished. Cronometer,
Hub, GYSS, OH SNAP had no in-app Help to begin with. The CMS help page
(`smack-help.php`) covers SYBU/SMACKPRESS/FLKR/SUYB/SMACKATTACK/OH SNAP well, barely
mentions GYSS + Unzucker, and omits COLD SNAP/Shots Fired/Smack Your Mouth/
Cronometer/Hub.

## Honesty about verification

These ports were written and their Python was syntax-checked on a **Windows** build
box. The `snap_blink` runtime itself was smoke-tested there (static serving, the
token bridge, path-traversal block, Chromium discovery all pass). But **no port has
been run on Linux hardware with a real Chromium window yet** — that verification is
the next step, on the target Linux box. Status words here are literal: *ported and
import-checked*, not *tested live*.

## Tools

| Key | Tool | Source | Type |
|-----|------|--------|------|
| `sybu` | SYBU | `linux/sybu/` | tkinter → Blink |
| `coldsnap` | COLD SNAP | `linux/coldsnap/` | tkinter → Blink |
| `suyb` | SMACK UP YOUR BACKUP | `linux/smack-up-your-backup/` | tkinter → Blink |
| `hub` | THE HUB | `linux/hub/` | tkinter → Blink |
| `flkrfckr` | FLKR FCKR | `linux/flkr-fckr/` | tkinter → Blink |
| `smackpress` | SMACKPRESS | `linux/smackpress/` | customtkinter → Blink |
| `unzucker` | UNZUCKER | `linux/unzucker/` | tkinter → Blink |
| `tyswy` | TAKE YOUR SHIT WITH YOU | `linux/take-your-shit-with-you/` | tkinter → Blink |
| `shotsfired` | SHOTS FIRED | `linux/shots-fired/` | tkinter → Blink |
| `smackyourmouth` | SMACK YOUR MOUTH | `linux/smack-your-mouth/` | tkinter → Blink |
| `cronometer` | CRONOMETER | `linux/cronometer/` | tkinter → Blink |
| `smackattack` | SMACKATTACK SCANNER | `linux/smackattack-scanner/` | tkinter → Blink |
| `gyss` | GYSS | `linux/gyss/` | Tauri front-end + Python bridge |
| `ohsnap` | OH SNAP | `linux/oh-snap/` | Tauri front-end + Python bridge (tool still non-functional) |

Per-tool feature parity and any `TODO(port):` gaps are recorded in each tool's
`linux/README.md`.

## Cross-cutting gaps (true for the whole family, not one tool)

1. **Native file/folder pickers.** A sandboxed Chromium `--app` window can't return a
   filesystem *path* (web file inputs give contents only, and there's no folder
   chooser). The shared fix is `snap_blink.pick_path()` (shells out to
   `zenity`/`kdialog`/`qarma`). Where that program isn't installed, every tool falls
   back to a typed/pasted path field — no feature is lost, only the browse convenience.
   The tools were ported before `pick_path()` landed, so each currently has its own
   copy of the same zenity/kdialog call; a future tidy-up can point them all at the
   shared helper.
2. **Live progress streaming.** `blink.call` is request/response. Long jobs (scans,
   imports, batch posts) either buffer to a queue the page polls (`poll_events`/`op_poll`
   pattern — the good one) or return the full log at the end. Outcome is identical;
   only the intermediate animation differs. Prefer the polling pattern.
3. **No credential encryption without extra deps.** The stdlib-only rule blocks
   `cryptography`/`keyring`, so OH SNAP's vault falls back to a locked-down (chmod 600)
   plaintext JSON instead of the OS keyring. **Decision needed:** allow the shared
   `snap_vault` (scrypt+Fernet, needs `cryptography`) for the tools that hold secrets.

## Findings surfaced during the port (affect the Windows build too)

- **The Hub:** the original `main.py._test_hub` unpacked `snap_discovery.discover()`
  wrong (treated a `(hub_info, spokes)` tuple as a dict) — that path would raise on
  Windows. The Linux port unpacks it correctly; the Windows tool still has the bug.
- **GYSS:** `core/gyss-api.php` only sends CORS headers for `file://`/`tauri://`
  origins, so a real browser window (`http://127.0.0.1`) is CORS-blocked. The port
  routes blog calls through Python to sidestep it (no server change needed). If a
  pure-browser GYSS is ever wanted, the server allowlist needs the localhost origin.

<!-- ===== SNAPSMACK EOF ===== -->
