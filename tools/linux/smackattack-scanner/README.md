<!--
  SMACKATTACK SCANNER — Linux Chrome/Blink port README.
-->

# SMACKATTACK SCANNER — Linux (Chrome/Blink) port

This is the Linux version of the SMACKATTACK SCANNER desktop tool (the tool's
internal name is GOBSMACKED Scanner). On Windows the window was drawn with tkinter.
On Linux the same tool runs with its window drawn by Chromium/Chrome (the Blink
engine) on top of the shared `snap_blink` runtime.

**The work did not change.** The scanning is the original Python:

- `config.py` — reads/writes the settings INI (same file location as before).
- `db.py` — MySQL access via `pymysql`.
- `scanner.py` — the 25-dimension stylometric vector + cosine similarity.
- `smackattack_core.py` — the scan orchestration, factored out of the old tkinter
  thread so both the Windows and Linux builds run the identical scan.

Only the window is new: `linux/app.py` exposes the tool's actions to an HTML page
(`web/index.html`, `web/app.js`, `web/style.css`).

## What it does

Runs a stylometric scan against a SnapSmack database: it fetches approved comments,
builds a writing-style vector per author, compares every author against every other
author and against any stored banned-user style profiles, and flags pairs whose
writing style is suspiciously similar. Flagged pairs are stored in
`snap_gobsmacked_scan` and shown in the Results tab, where you can mark them reviewed
or upload a report to the hub API.

## How to run on Linux

You need Python 3.10+, a Chromium-family browser installed (chromium, google-chrome,
brave, edge, or vivaldi), and the one pip dependency.

```
cd tools/smackattack-scanner/linux
pip3 install -r requirements.txt
chmod +x run.sh
./run.sh
```

`run.sh` sets `PYTHONPATH` so Python finds both `snap_blink` (in `tools/_shared`) and
the tool's own modules (in `tools/smackattack-scanner`), then starts `app.py`.
`app.py` opens a localhost-only server on a random port and launches a Chromium app
window pointed at it. Close the window to quit.

If no Chromium/Chrome binary is found, `snap_blink` prints a localhost URL and keeps
serving so you can open it in any browser tab.

## Settings and config location

Settings are stored by the original `config.py` in `gobsmacked-scanner.ini` next to
the tool (the same place the Windows build used). Nothing about the config location
changed; the shared-library contract is preserved. `snap_blink` also sets
`SNAPSMACK_HOME` to `~/snapsmack` if it is unset.

## Feature parity vs the tkinter version

Every tkinter control has an equivalent web control wired to a `blink.call`:

| tkinter action | Linux control | Python handler |
| --- | --- | --- |
| Settings: 9 fields (host, port, db, user, password, api_url, api_key, threshold, min_words) | Same 9 inputs (password + API key masked) | `load_state` fills them |
| Settings: SAVE & TEST CONNECTION | Same button | `save_and_test` |
| Scan: RUN SCAN | Same button | `run_scan` |
| Scan: VIEW RESULTS | Same button | switches to Results tab |
| Scan: authors / pairs / flags stats | Same three stat tiles | filled from `run_scan` result |
| Scan: progress bar + log pane | Same bar + log pane | filled from `run_scan` result |
| Results: filter radios (All / Peer / vs Banned / Unreviewed) | Same radios | `get_results` filter arg |
| Results: ⟳ Refresh | Same button | `get_results` |
| Results: matches table (7 columns) | Same table | `get_results` |
| Results: MARK REVIEWED | Same button | `mark_reviewed` |
| Results: UPLOAD TO HUB | Same button | `upload_to_hub` |
| Status bar | Footer status bar | set by each handler |

### TODO(port) — one known gap, wired but not identical

- **Live scan progress streaming.** The tkinter build ran the scan on a background
  thread and updated the progress bar and log line-by-line while it worked. `blink.call`
  is a single request/response, so the Linux build shows the complete log and final
  stats when the scan returns, rather than streaming them as it runs. Nothing is
  dropped — every log line, stat, and flag the original produced is preserved and
  shown. A future version could add a polling handler (e.g. `scan_progress()`) to
  stream percent/log for very large databases. Marked `TODO(port)` in `app.py`
  `run_scan`.

## Status

Ported, imports verified with `ast.parse`. NOT yet run on Linux hardware (no Linux
Chromium available on the build box).

<!-- ===== SNAPSMACK EOF ===== -->
