<!--
  SHOTS FIRED — Linux Chrome/Blink port README.
  Last non-empty line must be the canonical HTML-comment SNAPSMACK EOF marker.
-->

# SHOTS FIRED — Linux (Chrome/Blink) port

Fleet-wide scheduled-post calendar. Pulls the FUTURE-dated (scheduled) posts from
every fleet site into one day-grouped agenda so you can SEE the whole lineup and
SHUFFLE it — move a post to a new day/time. It does **not** create posts (that's
SYBU / COLD SNAP); it only surfaces and reschedules what is already queued.

This is the Linux version of the Windows tkinter tool. The **window** is now
HTML/CSS/JS drawn by Chromium through the shared `snap_blink` runtime; the
**work** is the original Python, imported unchanged.

## What was reused, not rewritten

The pure logic was already free of tkinter, so the port imports it directly from
the tool root — no `shotsfired_core.py` was needed:

- `fleet.py` — loads the fleet from the shared cross-tool profile store
  (`snap_profiles`).
- `schedule_client.py` — the per-spoke HTTP client (`list_scheduled`,
  `reschedule`, `ApiStatus`, `ScheduledPost`). Auth headers unchanged.
- `config.py` — the shared-home prefs file (look-ahead window).

Only the tkinter files (`main.py`, `ui.py`, `agenda.py`) were replaced by the web
UI. `app.py` registers three `@app.api` handlers that call straight back into the
modules above.

## Run on Linux

Needs Python 3 and a Chromium-family browser on PATH (chromium, google-chrome,
brave, edge, vivaldi — `snap_blink` finds it). Then:

```
cd tools/shots-fired/linux
pip install -r requirements.txt   # just: requests
./run.sh
```

`run.sh` sets `PYTHONPATH` to reach `tools/_shared` and the tool root, sets
`SNAPSMACK_HOME` to `~/snapsmack` if unset, and launches `app.py`, which opens a
Chromium `--app` window. Closing the window exits the tool. If no Chromium binary
is found, `snap_blink` prints a localhost URL to open in any browser.

The fleet, creds, config and logs all still come from the shared library
(`snap_home` / `snap_profiles`) — no new config location was invented.

## Feature parity vs the tkinter version

| tkinter control | web equivalent |
|---|---|
| LOOK AHEAD combobox (14/30/60/90/180/365 days) | `#lookahead` `<select>`, seeded from `load_state`, saved via `refresh` |
| REFRESH button | REFRESH button → `blink.call('refresh', days)` |
| Auto-load on open (`after(150, refresh)`) | `boot()` calls `doRefresh()` after `load_state` |
| Day-grouped agenda (`AgendaView.set_posts`) | `renderAgenda()` groups posts by calendar day |
| TODAY / TOMORROW / in N days day headers | `relWhen()` / `fmtDayHeader()` |
| Per-site colour swatch (round-robin) | same palette + round-robin `swatch()` in `app.js` |
| Per-site status notes (no key / not deployed / …) | rendered as `.note` rows atop the agenda |
| Empty-window message | `.empty` message |
| MOVE… button per post | MOVE… button → `openMoveDialog()` |
| Reschedule Toplevel (new date + new time) | `#move-backdrop` modal, CANCEL apart from MOVE POST |
| MOVE POST confirm | `blink.call('reschedule_post', ...)` |
| "no scheduling API yet" messagebox | `window.alert` + status line, same wording |
| Enter/Escape in the dialog | keydown handler in `boot()` |
| Status summary line | `#status` footer |

### Not-yet-portable gaps

None functional. Two cosmetic tkinter-only touches were intentionally dropped
(no server work, no feature): the `.ico` taskbar icon and the manual
cut/copy/paste clipboard bindings (`ui.install_clipboard_bindings`) — Chromium
gives Ctrl+C/V and a right-click menu natively, so those bindings are moot in the
web UI.

## Honesty note

Ported and imports verified (`app.py` parses clean with `ast.parse`). **Not yet
run on Linux hardware** — this was built on the Windows box, which cannot launch
Linux Chromium. Run `./run.sh` on a Linux install to smoke-test the window.

<!-- ===== SNAPSMACK EOF ===== -->
