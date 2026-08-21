<!-- FLKR FCKR — Linux Chrome/Blink port README -->

# FLKR FCKR — Linux (Chrome/Blink) port

Flickr export → SnapSmack photoblog migration, running on Linux with its window
drawn by Chromium (Blink) instead of tkinter. **Same tool, new window.** Every
piece of real work — parsing the export, testing the connection, the throttled
import, the crash checkpoint, the API-key vault, step-up authorization — is the
original FLKR FCKR Python, reused unchanged.

## What it is

The Windows build was a tkinter desktop app. This port keeps the work and
replaces only the window:

- **Work (Python):** `config.py`, `flickr_parser.py`, `poster.py`,
  `checkpoint.py`, `image_prep.py` (all in the parent `flkr-fckr/` folder) plus
  the shared `snap_stepup.py` / `snap_vault.py`. None of these were modified.
- **Orchestration (Python, new, tkinter-free):** `../flkrfckr_core.py` — the
  `Session` object that the old tkinter window's methods were split into, so the
  logic returns data and raises on error with no widgets involved.
- **Window (HTML/CSS/JS):** `web/index.html`, `web/app.js`, `web/style.css`,
  served locally by the shared `snap_blink` runtime and shown in a Chromium
  `--app` window.

## Data safety (unchanged, on purpose)

The importer attaches comments to the **image id** and preserves **GPS/EXIF**
deliberately. This port does **not** add a strip-location toggle and does **not**
"fix" metadata — behaviour is byte-for-byte the same as the Windows tool. The
import also reads from the exact filtered/kept photo list the grid shows, so it
never uploads a photo you can't see and exclude.

## How to run on Linux

```
cd tools/flkr-fckr/linux
python3 -m pip install -r requirements.txt
./run.sh
```

`run.sh` sets `SNAPSMACK_HOME` (defaults to `~/snapsmack`) and puts the shared
library and the tool root on `PYTHONPATH`, then launches `app.py`. `snap_blink`
finds any installed Chromium/Chrome/Brave/Edge/Vivaldi and opens the window; if
none is found it prints a localhost URL to open in a normal browser tab.

Config, the API key, the vault, and logs live exactly where the Windows tool put
them (`flkrfckr.ini`, `vault.meta`, `flkrfckr.<date>.log`) next to the tool
source — the shared-library contract is preserved; no new config location was
invented.

## Feature parity vs the tkinter version

Every tkinter control maps to a web control that calls the same Python:

| tkinter control | web equivalent | Python reached |
|---|---|---|
| Site URL / API Key entries | text/password inputs | `save_settings` |
| Connect | Connect button | `test_connection` → `FlkrDckrClient.ping` |
| Export Folder entry | text input | `save_settings` |
| Browse… (askdirectory) | Browse… button | `pick_folder` (zenity/kdialog — see TODO) |
| Throttle combobox (7 presets) | select | `save_settings` |
| Off-peak only + Peak start/end | checkbox + selects | `save_settings` |
| Private → combobox | select | `save_settings` |
| Unalbumed action combobox | select | `save_settings` |
| Load Export | Load Export button | `load_export` → `flickr_parser.parse` |
| Show: All / Unalbumed radios | radios | client-side filter |
| Album sidebar listbox | album list | client-side filter |
| Photo tile click = exclude | tile click | `toggle_exclude` |
| Lazy square thumbnails | IntersectionObserver | `thumbnail` (PIL square-crop) |
| Start / Pause / Resume | Start Import button | `preflight_import` → `authorize_import` → `start_import` / `pause_import` / `resume_import` |
| Progress bar + summary | progress bar + summary line | `poll_events`, `summary` |
| LOG text + Pop Out ↗ | log pane + Pop Out modal | `poll_events` |
| Resume-after-crash prompt | resume modal | `check_resume` / `resume_accept` / `resume_decline` |
| Key security window (on/off/re-key) | Key modal | `vault_status` / `vault_enable` / `vault_disable` / `vault_rekey` |
| Vault unlock at startup | silent machine-key unlock (+ `vault_unlock` available) | `vault_try_machine_key` |
| Logs button | Logs button | `open_logs` (xdg-open) |
| ? Help window | Help modal | in-page (same text) |
| Window close stops import | (window close exits process; `stop_import` wired) | `stop_import` |

The tkinter after()/queue that fed live progress is replaced by the page polling
`poll_events` on a 250 ms timer.

### TODO(port) items

- **`pick_folder` (Browse…)** — there is no Blink/JS way to hand the server a
  *folder path* (an `<input type=file>` yields file blobs, not a server-side
  path). `pick_folder` shells out to **zenity** or **kdialog** when present; if
  neither is installed the operator types or pastes the export folder path into
  the field (fully functional either way). If you want a guaranteed picker,
  install `zenity`.
- **Startup vault-unlock re-prompt loop** — the tkinter version re-prompted on a
  wrong passphrase in a loop. The web port attempts the silent machine-key unlock
  on boot and exposes `vault_unlock`; a full "prompt again on wrong passphrase at
  startup" modal is wired through the Key modal but not auto-shown on launch when
  the vault is locked. `TODO(port)`: auto-open an unlock modal at boot when the
  vault is encrypted and the machine key fails.

## Status

**Ported, imports verified (`ast.parse` clean on `app.py` and
`flkrfckr_core.py`), NOT yet run on Linux hardware.** This build box is Windows
and cannot launch Linux Chromium, so the window has not been exercised live.

<!-- ===== SNAPSMACK EOF ===== -->
