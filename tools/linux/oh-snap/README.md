<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical HTML-comment SNAPSMACK EOF marker.
-->

# OH SNAP! — Linux Chrome/Blink port

This is the OH SNAP! skin designer running on Linux with its window drawn by
Chromium (the Blink engine) instead of the Tauri/WebView2 shell. The web UI is
reused unchanged from the desktop build; the small amount of native work the Rust
backend did is reproduced in Python and reached through the shared `snap_blink`
runtime.

## Honest status

**OH SNAP! is not a finished tool.** Per the project's own status notes, its AI
assist has no knowledge of the server's shared assets, and its skin-kit contract
(`web/data/skin-kit-contract.js`) is incomplete and not fully wired. This port
carries across exactly what already exists — it does **not** finish the tool. Do
not treat "ported" as "working end to end".

This port has been checked with `python3 -c "import ast; ast.parse(...)"` (imports
and syntax verified). It has **not** been run on Linux Chromium from the build
box (a Windows machine).

## Run it on Linux

```
cd tools/oh-snap/linux
chmod +x run.sh
./run.sh
```

`run.sh` sets `PYTHONPATH` to include `tools/_shared` and `tools/oh-snap`, then
runs `app.py`. `app.py` starts a localhost-only server on a random port and opens
a Chromium `--app` window. A Chromium-family browser must be installed
(`chromium`, `google-chrome`, `brave-browser`, `microsoft-edge`, or `vivaldi`);
`snap_blink` finds it automatically. No pip install is required.

Config, credentials and shared data use the usual SnapSmack layout under
`SNAPSMACK_HOME` (defaults to `~/snapsmack` on Linux).

## What changed vs the Tauri build

- The window is opened by `snap_blink` (a Chromium `--app` window) instead of
  Tauri's WebView. Front-end files under `web/` are copies of `src/`.
- `index.html` now loads `/snap_blink.js` (the auto-served bridge) before the app
  scripts. It no longer relies on `window.__TAURI__`.
- Every `window.__TAURI__.core.invoke('cmd', {args})` became
  `window.blink.call('cmd', ...args)` with the **same command names** and
  positional args. The native `dialog.save` / `dialog.open` plugin calls became
  `dialog_save` / `dialog_open` Blink handlers.

## Command-by-command parity

Tauri command → Python `@app.api` handler in `app.py`:

| Command                     | Status | Notes |
|-----------------------------|--------|-------|
| `save_project(path, content)`        | DONE | Atomic write (temp + fsync + rename), keeps a `.bak` — matches the Rust logic. |
| `load_project(path)`                 | DONE | Reads the file, falls back to `.bak` on failure — matches the Rust logic. |
| `export_shareable_package(path, files)` | DONE | Deflate ZIP with the same path-safety and forbidden-extension (`php/exe/sh/.htaccess/...`) guards; atomic move into place. |
| `vault_set(account, secret)`         | DONE* | *Stored in a JSON map in the shared auth dir (chmod 600), **not** the OS keyring. See TODO below. |
| `vault_get(account)`                 | DONE* | Reads that JSON map. |
| `vault_delete(account)`              | DONE* | Removes from that JSON map. |
| `dialog_save(default_name)` (new)    | TODO(port) | No native file chooser in the stdlib Blink runtime; returns a predictable path under the shared projects dir. |
| `dialog_open()` (new)                | TODO(port) | No native open dialog; returns the most recent `.ohsnap` in the projects dir, or nothing. A future revision should surface a project picker in the UI. |

### TODO(port) items carried in the code

- **`dialog_save` / `dialog_open`** — the Tauri build used native OS file
  dialogs. The Blink runtime is standard-library only and has no file chooser, so
  save/open resolve paths under `~/snapsmack/ohsnap/projects` instead of letting
  the user browse. The features are wired and functional, just without a native
  picker.
- **vault at rest** — the Tauri build kept API/AI keys in the OS keyring
  (machine-bound, encrypted). This port keeps them in a plaintext JSON map in the
  shared auth dir. The faithful upgrade is the shared `snap_vault` module (scrypt
  + Fernet), but that needs the `cryptography` (and optional `keyring`) pip deps,
  which the stdlib-only port rule forbids adding here.

### Unchanged behaviour (no port work needed)

- Connection profiles, settings and project drafts still live in the page's
  `localStorage` exactly as before.
- "Open in browser" uses `window.open` (never a Tauri shell call), so it works
  as-is.
- All skin/preview/AI/validation logic is pure front-end JS and is reused verbatim
  — including the parts that are still incomplete in the tool itself.

<!-- ===== SNAPSMACK EOF ===== -->
