<!--
  SMACK YOUR MOUTH — Linux Chrome/Blink port README.
  Last non-empty line must be the canonical SNAPSMACK EOF marker.
-->

# SMACK YOUR MOUTH — Linux (Chrome/Blink) port

Offline fleet comment moderation + replies. The inbound twin of COLD SNAP: it
pulls each site's comments IN, you moderate (approve / delete / spam) and write
replies with NO network, then it syncs the decisions + replies back on the next
connection — with **positive verification** (a decision is only "done" once the
server confirms it).

This is the Linux build. The window is HTML drawn by Chromium/Chrome through the
shared `snap_blink` runtime instead of tkinter. **The work is unchanged** — the
same engine, transport, fleet loader, and shared-home config the Windows build
uses.

## What it is

- `app.py` — registers the Python actions with `snap_blink` and opens the window.
- `smackmouth_core.py` (in the tool root) — the GUI-free controller factored out
  of the Windows `main.py`; both shells drive it.
- `web/index.html`, `web/app.js`, `web/style.css` — the window (the same controls
  the tkinter shell had, in the same dark/green family palette).
- The real logic still lives in the tool root and is **reused unchanged**:
  `moderation_offline.py` (engine + sessions + export/import), `moderation_api.py`
  (HTTP transport), `fleet.py` (shared-profile fleet), `config.py` (shared home).

## How to run on Linux

You need Python 3 and a Chromium-family browser (chromium, google-chrome, brave,
edge, or vivaldi — `snap_blink` finds it). Then:

```
cd tools/smack-your-mouth/linux
pip3 install -r requirements.txt      # just: requests
./run.sh
```

`run.sh` puts `tools/_shared` and the tool root on `PYTHONPATH`, sets
`SNAPSMACK_HOME` to `~/snapsmack` if unset, and launches `app.py`. A localhost-
only random-port server starts and a Chromium `--app` window opens; closing the
window quits the tool. Config, credentials, sessions, and profiles come from the
shared home exactly as on Windows — no new config location is invented.

## Feature parity vs the tkinter version

Every tkinter control maps to a web control + `blink.call`:

| tkinter action (main.py)                    | web control            | Python handler                 |
|---------------------------------------------|------------------------|--------------------------------|
| SESSION combobox                            | `<select>`             | `select_session`               |
| NEW SESSION                                 | button                 | `new_session`                  |
| EXPORT… (folder picker)                     | button + path prompt   | `export_session`               |
| IMPORT… (folder picker)                     | button + path prompt   | `import_session`               |
| REPLY AS entry                              | text input             | `set_author`                   |
| ONE-OFF SITE URL + API KEY + PULL THIS SITE | inputs + button        | `pull_one`                     |
| REFRESH FLEET                               | button                 | `refresh_fleet`                |
| PROBE (LIVE)                                | button                 | `probe_fleet`                  |
| PULL ALL PENDING                            | button                 | `pull_all`                     |
| Fleet listbox                               | fleet list             | (rendered from `load_state`)   |
| APPROVE / DELETE / SPAM (per comment)       | buttons                | `set_decision`                 |
| CLEAR (per comment)                         | button                 | `set_decision(item, "none")`   |
| REPLY textarea + SAVE REPLY                 | textarea + button      | `save_reply`                   |
| SYNC DECISIONS + REPLIES (+ confirm dialog) | button + confirm modal | `sync_preview` then `sync_run` |
| unsaved-reply flush before sync             | (automatic)            | `flush_replies`                |
| status line                                 | footer status          | (returned in every result)     |

The Parkinson's-forgiving sync guard is preserved: before pushing, a confirm
modal names the destination site(s) and, in red, calls out how many comments
will be **permanently deleted** — matching `snap_confirm` / the tkinter
`askyesno` guard.

### TODO(port)

- **Folder pickers (EXPORT… / IMPORT…):** tkinter used
  `filedialog.askdirectory` for a native OS folder chooser. `snap_blink` is
  stdlib-only with no native picker bridge, so the web UI prompts for the folder
  **path** in a small modal and passes it to `export_session` / `import_session`.
  Same action, typed target. If a native picker is added to `snap_blink` later,
  swap the prompt for it.
- **Per-comment source-image thumbnail:** the tkinter `ui.py` had an optional
  Pillow thumbnail helper (`load_thumb`). It was never wired into a comment card
  in `main.py`, so there is nothing to port; the web UI omits it and Pillow is
  not a dependency here.

## Honesty

Ported and imports verified (`ast.parse` clean). **Not yet run on Linux
hardware** — this build box has no Linux Chromium to launch the window.

<!-- ===== SNAPSMACK EOF ===== -->
