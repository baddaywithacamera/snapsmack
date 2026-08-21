<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the HTML-comment EOF marker. -->

# SMACK YOUR BATCH UP — Linux (Chrome/Blink) port

This is the Linux build of SYBU. The window is drawn by Chromium (the Blink
engine) instead of Windows tkinter; the **work is unchanged** — it imports the
tool's own `poster` / `gemini` / `drive` / `manifest_parser` / `profile_manager`
/ `recovery` / `matcher` modules and calls the same functions the desktop app
called. Nothing about network posting, Gemini enrichment, Drive upload, or the
shared credential/profile store was rewritten.

## What it is

- `app.py` — registers every action as a `blink.call` handler and starts the
  Chromium `--app` window via the shared `snap_blink` runtime.
- `sybu_core.py` (in the parent `tools/sybu/` folder) — the headless engine:
  all of SYBU's real behaviour, factored out of the tkinter window so it can run
  with no widgets. This is the only new logic; it wires the existing modules.
- `web/index.html`, `web/app.js`, `web/style.css` — the window. `app.js` reaches
  the Python side only through `blink.call(...)`.

Config, credentials, profiles and Gemini prompt presets still go through
`snap_home` / `snap_creds` / `snap_profiles` / `snap_prompts` via the tool's
existing `config.py` and `profile_manager.py` — no new config location. On Linux
`snap_blink` sets `SNAPSMACK_HOME` to `~/snapsmack` if it is unset.

## Running on Linux

```
cd tools/sybu/linux
python3 -m pip install -r requirements.txt      # first time only
./run.sh
```

`run.sh` puts `tools/_shared` (snap_blink + shared library) and `tools/sybu`
(the work modules) on `PYTHONPATH`, then launches `app.py`. A Chromium/Chrome
(or Brave/Edge/Vivaldi) binary must be on the box — `snap_blink` finds it. With
no Blink browser installed it prints a localhost URL to open manually.

Folder / file boxes take a typed path. If `zenity` or `kdialog` is installed the
`…` buttons open a native picker; otherwise type or paste the path.

## Feature parity vs the tkinter version

Every button, menu, checkbox and field from the desktop window is present:

| Desktop (tkinter) action | Web control → handler |
|---|---|
| Tabs: SMACKONEOUT / GRAMOFSMACK / AUDIT / BASIC REPAIR / ADV. MATCH / SETTINGS | Top tab strip → `switchTab` |
| LED bar: Site / Drive / AI status | `#ledbar` panels, `setLed` |
| Load profile (POST tab dropdown) | `#postProfile` → `profile_apply_to_post` |
| Site URL / API key / Remember / Connect | `#url` `#apiKey` `#remember` → `connect` |
| Image folder + browse | `#folder` + `browse_folder` |
| Manifest file + browse / Load Manifest | `#manifest` + `browse_manifest` / `load_manifest` |
| Scan Folder | `scan_folder` |
| Default category / album / orientation / colour | `#defCat` `#defAlbum` `#defOrient` `#defColor` |
| Enrich with Gemini (top + bottom) | `#enrichTopBtn` / `#enrichBtn` → `enrich_start` |
| Google Drive enable / creds + browse / folder id / Auth Drive | `drive_toggle` / `browse_creds` / `auth_drive` |
| Gemini key + Test Connection | `#gemKey` / `gemini_test` |
| Prompt presets: pick / Save As… / Delete | `preset_text` / `preset_save` / `preset_delete` |
| Prompt text | `#gemPrompt` (sent with `enrich_start`) |
| Copyright string | `#copyright` |
| Queue: thumbnail, filename, title, tags, caption, ALT | per-row inputs → `update_entry` + `thumb` |
| Queue: category, album, orientation, colour swatches | per-row meta → `update_entry` |
| Per-row select checkbox / Select all | `set_selected` / `set_all_selected` |
| Drag-to-reorder rows | HTML5 drag → `reorder` |
| Randomize | `shuffle` |
| Status badge per row (pending/enriched/posting/posted/failed) | `.badge` classes |
| Validate | `validate` |
| POST BATCH (solo + gram) with all guards | `post_preflight` + `post_start` |
| Cancel (POST turns to CANCEL) | `cancel_post` |
| Clear (keeps failed rows) | `clear_queue` |
| Clear AI Warning | `clear_enrichment_warning` |
| Resume saved enrichment offer | `apply_resume` |
| AUDIT: Refresh, summary, duplicates, missing links, Go to Repair | `audit_refresh` |
| REPAIR: Start/Stop Rename Batch + log | `rename_start` / `rename_stop` |
| REPAIR: Start/Stop Re-enrich duplicates + log | `reenrich_start` / `reenrich_stop` |
| REPAIR: Backfill list + auto-search + manual Save | `backfill_list` / `backfill_auto` / `backfill_save` |
| ADV. MATCH: server + originals folders, Run/Stop | `match_start` / `match_stop` |
| ADV. MATCH row: previews, confidence, Upload / Pick Different / Skip | `match_preview` / `match_upload` / `match_pick` / `match_skip` |
| SETTINGS: profile list, New / Duplicate / Delete / Load Site | `profile_new/duplicate/delete`, `profile_apply_to_post` |
| SETTINGS: profile form + Save Profile | `profile_get` / `profile_save` |
| SETTINGS: Test Connection (site + Gemini) | `sp_test` / `sp_gemini_test` |
| Insecure-transport (non-https) warn-and-confirm | returned as `needs_insecure_ack`, confirmed in the page |
| Help ("?") | `#helpBtn` |

### TODO(port) notes

- **Native folder/file pickers** depend on `zenity` or `kdialog` being on the
  box (`browse_*` handlers in `app.py`). Where neither is present the `…` buttons
  are inert and you type the path into the text field — the field is the real
  control, so no feature is lost, only the convenience dialog.
- **Session countdown / keepalive LEDs** from the desktop app are not drawn.
  API-key auth (0.7.9e+) has no server session to keep alive — `client.keepalive()`
  is already a no-op — so the countdown was cosmetic. The connection LED still
  reflects connect state.
- **First-run Google Drive OAuth** opens a browser tab for consent (via
  `drive.authenticate` → `run_local_server`), exactly as on the desktop; after
  that `token.json` is silent.

## Status

Ported, imports verified (`python3 -c "import ast; ast.parse(...)"` clean on
`app.py` and `sybu_core.py`). **Not yet run on Linux hardware** — I cannot launch
Linux Chromium from the build box, so this has not been exercised live.
<!-- ===== SNAPSMACK EOF ===== -->
