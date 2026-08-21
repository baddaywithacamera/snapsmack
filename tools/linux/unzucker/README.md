<!-- UNZUCKER — Linux Chrome/Blink port README -->

# UNZUCKER — Linux (Chrome/Blink) port

Same tool as the Windows tkinter UNZUCKER: it takes a **Meta / Instagram data
export** and migrates the posts into a SnapSmack site (The Grid / Carousel mode).
Images upload over HTTPS and posts are created through the SnapSmack admin API —
no FTP. This port replaces the **tkinter window** with an **HTML window drawn by
Chromium (Blink)** on top of the shared `snap_blink` runtime. The **work is
unchanged**: it runs the tool's own `config`, `ig_parser`, `job_state`, `poster`
and `exif_writer` modules — the exact code the Windows build ships.

Importers preserve the original photo metadata on purpose (EXIF, timestamps).
This port keeps that behaviour; it adds no stripping.

## How it is put together

```
tools/unzucker/
  main.py            # original Windows tkinter app (untouched)
  config.py ig_parser.py job_state.py poster.py exif_writer.py   # the WORK (untouched)
  unzucker_core.py   # NEW: tkinter-free Session that holds all state + logic
  linux/
    app.py           # imports snap_blink + unzucker_core; registers each handler; app.run()
    web/index.html   # the window
    web/app.js       # every control → blink.call(...)
    web/style.css    # dark, desktop-only, neon-lime theme
    run.sh           # launcher (sets PYTHONPATH, runs app.py)
    requirements.txt
    README.md
```

`unzucker_core.py` is the only genuinely new logic file. It was factored out of
`main.py` so the Windows build could keep using tkinter while the Linux build
uses Chromium — both drive the same functions. It imports **no tkinter**.

## Run it on Linux

You need Python 3, a Chromium/Chrome-family browser, and the pip deps.

```
cd tools/unzucker/linux
python3 -m pip install -r requirements.txt   # requests, Pillow, piexif, keyring
chmod +x run.sh
./run.sh
```

`run.sh` opens a Chromium **app window**. Closing that window quits the tool.
For the OS keyring (secure API-key storage) install a Secret Service backend —
on GNOME that is `gnome-keyring`; otherwise the key falls back to base64 in
`unzucker.ini` (encoding, not encryption) and the header shows "⚠ no keyring".

Config (`unzucker.ini`) and job files (`jobs/…`) are written next to the tool
modules in `tools/unzucker/`, exactly like the Windows build — the port does not
invent a new config location.

## Feature parity vs the tkinter version

Every tkinter button / menu / field is wired to a web control + `blink.call`:

| tkinter action | port |
| --- | --- |
| Site URL, API key (+ show/hide) | fields in CONNECTION box |
| Connect (+ insecure-transport guard) | `connect` — https sends silently; non-https asks a confirm before sending the key |
| Export folder, Copyright string | fields in IMPORT SETTINGS |
| Server throttle (8 radios) | `throttle` radio group |
| Off-peak only + peak start/end | checkbox + two hour selects |
| Config collapse/expand | CONFIGURATION drawer toggle |
| Keyring / connection indicators | header badges |
| Parse Export | `parse_export` + `begin_job` |
| Resume-job prompt, Job-name prompt | in-page modals |
| 3-column square grid, virtualised thumbs | CSS grid + IntersectionObserver lazy thumbs (`thumb`) |
| Cell click → detail (preview, strip, caption, tags, date, prev/next/back) | `detail` + `preview` |
| Right-click exclude/include | right-click → `toggle_exclude` |
| Ctrl+click trigram select (3) | Ctrl+click → `trigram_select` |
| Trigram slot panel (reorder L/M/R, Lock) | modal with L·M / M·R swap buttons → `lock_trigram` |
| Remove trigram | Ctrl+click a locked cell → `remove_trigram` |
| Validate | `validate` |
| Transfer & Post (+ confirm naming the real site) | `migration_preview` confirm → `start_migration` |
| Progress bar / status line / live cell status | `poll` loop |
| Unload Job | `unload_job` |

### `TODO(port)` — known gaps

- **`TODO(port)` folder picker.** Chrome's `--app` window can't return a real
  folder path from a native picker (browser security), so the export folder is a
  text field you paste the path into. The tkinter "…" browse button has no direct
  equivalent. Everything downstream is identical.
- **`TODO(port)` window geometry.** tkinter saved window size/position and
  maximised state to `unzucker.ini`; a Chromium app window does not hand its
  geometry back to Python, so those keys are written blank/normal. The ini format
  is otherwise unchanged.
- **Trigram reordering** uses adjacent-swap buttons (L·M, M·R) rather than the
  tkinter drag-to-reorder, which is friendlier for imprecise clicks and reaches
  every one of the 6 orderings. Same `lock_trigram` result.

## Honesty

This port was written and its Python **imports/AST were verified**, but it has
**not been run on Linux hardware** with Chromium from this build box (Windows,
no Linux Chromium available). Parse, upload and API calls should be exercised on
a real Linux install before relying on it.

<!-- ===== SNAPSMACK EOF ===== -->
