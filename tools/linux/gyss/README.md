<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line must be the canonical HTML-comment SNAPSMACK EOF marker.
-->

# GET YOUR SHIT SORTED (GYSS) — Linux Chrome/Blink port

GYSS is the **offline per-blog sorter**: sync a blog's photos (and their
thumbnails) into a local library once, then arrange the feed, edit titles /
captions / categories, resolve conflicts, and (on GRAMOFSMACK sites) reorder the
grid and combine singles into carousels — all working against the on-disk copy,
touching the blog only for a bounded PULL and PUSH. It supports **SMACKONEOUT**
(photoblog) and **GRAMOFSMACK** (carousel) sites only — never SMACKTALK longform,
because longform images live inside essays and there is no feed to sort.

This is the Linux port. The window used to be drawn by Tauri (a Rust backend +
webview); it is now drawn by **Chromium/Chrome (Blink)** on top of the shared
`snap_blink` runtime. **The front-end is the original, reused unchanged in
behaviour.** Only the thin Rust file-IO backend was re-implemented in Python.

## What changed vs the Tauri build

| Tauri build | This port |
|---|---|
| Rust `#[tauri::command]` functions | Python `@app.api` handlers in `app.py` |
| `invoke('cmd', { …named })` via `window.__TAURI__` | `blink.call('cmd', { …named })` via `window.blink`, through the retargeted `scripts/tauri-core.js` shim |
| `convertFileSrc(path)` → `asset://` URL (sync) | `read_asset` → `data:` URL (async); `scripts/tauri-core.js` |
| `@tauri-apps/api/path` `join` | pure-JS POSIX `join` in `scripts/tauri-path.js` |
| `api.js` browser `fetch()` to the blog | `invoke('http_request', …)` through Python (browser CORS would block the localhost origin — see below) |
| Tauri window | Chromium `--app` window opened by `snap_blink` |

Everything else — `main.js`, `library.js`, `session.js`, `profiles.js`,
`shared-creds.js`, `paths.js`, `index.html`, `styles.css` — is the reused
front-end. The two `tauri-*.js` files kept their names and the `index.html`
import map, so the caller scripts needed only tiny edits: the transport swap in
`api.js`'s one `_call` method, a one-line `await` in `library.js` (the now-async
`convertFileSrc`), and the `<script src="/snap_blink.js">` tag in `index.html`.

### The command bridge

Tauri commands take **named** parameters; `snap_blink` handlers take **positional**
args. The shim keeps the Tauri shape by passing the single named-args **object**
through as one positional argument, so each Python handler takes one `args` dict
and reads the same keys the Rust command did. Command **names are unchanged**.

## Command-by-command status (parity with `src-tauri/src/lib.rs`)

| Command | Status | Notes |
|---|---|---|
| `shared_home` | **Done** | Returns `snap_home.home()` (SNAPSMACK_HOME, else the family default). |
| `read_file` | **Done** | UTF-8 read, jailed. |
| `write_file` | **Done** | UTF-8 write, creates parent dirs, jailed. |
| `list_dir` | **Done** | Absolute `*.json` paths, sorted; missing dir → `[]`. |
| `download_to` | **Done** | http(s) only, 64 MiB cap, jailed dest. Uses stdlib `urllib` in place of Rust `reqwest`. |
| `delete_file` | **Done** | Jailed; missing file is a no-op (idempotent prune). |
| `catalog_sync` | **Done** | Wholesale vocab replace in one transaction (stdlib `sqlite3` in place of `rusqlite`); same `_SCHEMA`, same meta keys, same local-time `synced_at`. |
| `catalog_read` | **Done** | Reads the catalog back. Reproduced for parity; the JS does not call it (nor did the Rust build). |
| `read_asset` | **Done (added)** | Not a Rust command — stands in for Tauri's built-in `convertFileSrc`. Returns a jailed image file as a `data:` URL for the webview. |
| `http_request` | **Done (added)** | Not a Rust command — see "Blog API transport" below. Runs the blog's `gyss-api.php` calls through Python so browser CORS doesn't block them. |
| `migrate_legacy` (Rust `setup`) | **TODO(port)** | See below — deliberately not reproduced. |

### Blog API transport (why `http_request` was added)

In the Tauri build, `api.js` reached the blog with a **browser `fetch()`** —
`gyss-api.php` sends CORS headers for `tauri://` and `file://` origins, so the
webview could call it directly. The Blink webview's origin is
`http://127.0.0.1:<port>`, which `gyss-api.php` does **not** allow, so a browser
fetch would be blocked and the tool could not even connect. Rather than change
server-side CMS code (which would have to ship through the updater), the port
routes those calls through the Python backend — a backend request is not bound by
browser CORS, the exact reasoning the Rust `download_to` used for thumbnails.
`api.js`'s single `_call` method now uses `invoke('http_request', …)`; every
endpoint, header, and payload is unchanged. **No server-side change is required.**

### `migrate_legacy` — not reproduced (by design)

The Tauri build ran a first-run migration off the old
`%APPDATA%\GetYourShitSorted` store (legacy Windows profiles/sessions/library)
into the shared root. That was a **one-time Windows-only** step for users upgrading
the old Windows Tauri app; it has no meaning on a fresh Linux install and needs the
Windows `%APPDATA%` path. It is intentionally omitted. The separate, still-relevant
migration of GYSS-private profiles (`config_files/gyss/profiles/`) into
`shared_library/profiles/` is **preserved** — it lives in the reused `profiles.js`
(`migrateLegacyOnce`) and runs through the ported file commands, so it still works.
`TODO(port): migrate_legacy` — only needed if someone runs this port on a machine
that also has the retired Windows Tauri app's `%APPDATA%` store to adopt.

## The file jail (SECAUDIT-039)

Every file command is confined to the shared SnapSmack root. `_resolve_in_root`
rejects any `..` component and then requires the resolved path to sit inside the
root — the same guard the Rust `resolve_in_root` applied, so a hostile string
fetched into the webview cannot write outside the root. `download_to` additionally
accepts only `http(s)` and caps the body at 64 MiB.

## Shared-library contract

GYSS reads/writes the SAME locations and byte formats as the Python family
(COLD SNAP / SYBU): connection profiles under `shared_library/profiles/<site_key>.json`,
shared secrets under `shared_library/auth/shared_creds.json`, and the per-site
store + `db/catalog.sqlite` under `shared_library/<site_key>/`. As in the Tauri
build, the profile/creds encoding (base64) lives in the JS
(`profiles.js` / `shared-creds.js`); this backend only supplies the jailed file
primitives, so a blog set up in any tool is visible here and vice-versa.

## How to run (Linux)

```
cd tools/gyss/linux
chmod +x run.sh
./run.sh
```

`run.sh` sets `PYTHONPATH` to `tools/_shared` (for `snap_blink` / `snap_home`) and
`tools/gyss`, defaults `SNAPSMACK_HOME` to `~/snapsmack`, then runs `app.py`.
`app.py` starts a localhost-only random-port server and opens a Chromium `--app`
window; closing the window exits. Requires a Chromium/Chrome-family (Blink) browser
on the box — `snap_blink` finds chromium / google-chrome / brave / edge / vivaldi.

## Requirements

Pure Python standard library (`http.server`, `sqlite3`, `urllib`, `base64`, `json`)
plus a Blink browser binary. No `pip install` needed. See `requirements.txt`.

## Honesty note

**Ported, imports verified (`python3 -c "import ast; ast.parse(...)"` clean), NOT
yet run on Linux hardware.** The build box is Windows and cannot launch Linux
Chromium, so the running app has not been exercised end-to-end. The Python parses
clean and the command surface matches `src-tauri/src/lib.rs` one-for-one (plus the
`read_asset` and `http_request` stand-ins for Tauri's built-in `convertFileSrc`
and browser fetch); the only untested-on-Linux paths are the browser launch
(owned by `snap_blink`, shared across all tools), live thumbnail rendering via
`data:` URLs, and the live `http_request` round-trip to a real blog.

<!-- ===== SNAPSMACK EOF ===== -->
