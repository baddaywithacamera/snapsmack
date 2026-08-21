<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the HTML-comment EOF marker. -->

# TAKE YOUR SHIT WITH YOU — Linux (Chrome/Blink) port

The same tool as the Windows build, with its window drawn by Chromium instead of
tkinter. It connects to your SnapSmack site with a read-only export key, walks
every table, downloads every image, builds a WordPress "courtesy" import
package, verifies the whole lot, and tells you plainly whether everything came
across.

**Every image. Every scrap of data. Pack it up and leave.**

## What is new here (and what is not)

- **New:** the window. `web/index.html` + `web/app.js` + `web/style.css`, served
  locally and shown in a real Chrome window through the shared `snap_blink`
  runtime (`tools/_shared/snap_blink.py`). No tkinter, no PyQt, no pip GUI.
- **Not new:** the work. `app.py` imports and runs the ORIGINAL Python —
  `config.py`, `tyswy_client.py`, `export_engine.py` — with no changes. The two
  pure helpers that were stuck behind tkinter in `main.py` (`human_bytes`,
  `open_in_file_manager`) were lifted into `tyswy_core.py` so this window can
  import them too. Nothing about how an export is fetched, written, or verified
  changed.

## How to run it on Linux

You need Python 3 and a Blink browser (Chromium, Chrome, Brave, Edge, or
Vivaldi). Then:

```
cd tools/take-your-shit-with-you/linux
python3 -m pip install -r requirements.txt
chmod +x run.sh
./run.sh
```

`run.sh` sets `SNAPSMACK_HOME` (defaults to `~/snapsmack`) and `PYTHONPATH`, then
starts `app.py`. `app.py` opens a localhost-only server on a random port and
launches a Chromium app window pointed at it. Close the window and the tool
exits.

If no Chromium/Chrome is found, `snap_blink` prints a `http://127.0.0.1:PORT/`
URL you can open in any browser and keeps serving until Ctrl-C.

The **Browse…** folder picker shells out to the desktop's own dialog (`zenity`,
then `kdialog`). If neither is installed you type the destination path into the
box instead — nothing is blocked.

## Feature parity vs the tkinter version

Every control from `main.py` is present and wired to the same Python:

| tkinter (main.py) | Blink port |
| --- | --- |
| Site address / Export key fields | `#f-url` / `#f-key` inputs |
| **Key security** dialog (`KeySecurityDialog`) | modal: on / off / change passphrase / unlock → `vault_*` handlers |
| **CONNECT** → preflight → WHAT IS THERE | `connect()` → `#manifest` |
| Folder field + **Browse…** | `#f-dest` + `pick_folder()` (zenity/kdialog) |
| free-space line | `disk_free()` → `#space` |
| WordPress / thumbnails / zip checkboxes | `#o-wp` / `#o-thumbs` / `#o-zip` |
| Downloads-at-once 1–4 | `#o-conc` select |
| **PACK MY SHIT** | `start_export()` (background thread) |
| progress stage / detail / bar / LOG | `poll_events()` → progress screen |
| **STOP** (with confirm, resume-safe) | `cancel_export()` |
| finished report screen | `finished` event → `renderDone()` |
| **OPEN FOLDER** / **VIEW REPORT** | `open_folder()` / `view_report()` |
| **COMPRESS LOCALLY** | `compress()` (background thread) |
| **START ANOTHER** | `reset()` |
| **Delete this incomplete export** (two confirms, `.tyswy/state.json` guard) | `delete_incomplete()` — both confirms in the page, the guard in Python |
| Quit-while-running warning | closing the Chrome window sets the daemon worker adrift; the on-disk export is resume-safe by design, so nothing is lost |

### TODO(port)

- **Browse… folder picker** depends on `zenity` or `kdialog` being installed. If
  neither is present the user types the path into the destination field (always
  live). No native file dialog is available to a Blink window otherwise.
- **Quit-while-running confirmation.** The tkinter build intercepts the window's
  close button (`WM_DELETE_WINDOW`) to warn "an export is running." A Chromium
  `--app` window's close button is not interceptable from the page, so there is
  no pop-up. This is safe rather than lossy: every completed, verified file is
  already on disk and the folder resumes where it stopped — but the courtesy
  warning could not be ported. Left as a note here so it is not silently dropped.

## Honesty note

Ported, imports verified with `ast.parse`, **not yet run on Linux hardware** — a
Windows build box cannot launch Linux Chromium. The window logic and the Python
handlers are complete and faithful to `main.py`; a Linux smoke test is the
remaining step.

<!-- ===== SNAPSMACK EOF ===== -->
