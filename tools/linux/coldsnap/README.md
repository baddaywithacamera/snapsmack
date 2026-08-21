<!--
  COLD SNAP — Linux Chrome/Blink port — README
-->

# COLD SNAP — Linux (Chrome/Blink) port

COLD SNAP is a standalone **offline store-and-forward poster** for SnapSmack.
Compose posts with no connection; **SYNC** them to your site when you're online.
This folder is the Linux build: the window is HTML/CSS/JS drawn by Chromium
(Blink) via the shared `snap_blink` runtime, instead of the Windows tkinter window.

Two modes, exactly as on Windows (they are modes, not tabs into other tools):

- **COLD ONE** — one photo per post, for SOLO / SmackOneOut photoblog sites.
- **COLD STACK** — gram posts for GramOfSmack sites: **single**, **carousel**
  (up to 10 images), or **trigram** (one 3:1 cover sliced into three, single
  slices or three carousels), with the full per-image control set (fit/fill,
  size %, focal X/Y, zoom, border, matte, shadow, split).

## What was and wasn't rewritten

**Nothing about the work was rewritten.** All posting/compose logic is imported
unchanged from the existing, already-GUI-free modules:

| Module | Role (unchanged) |
|---|---|
| `sumna_offline.py` | Sessions, drafts, thumbnails, trigram slicer, `SyncEngine` (positive verification) |
| `sumna_post.py` | HTTP transport: `SumnaConnection`, `SoloPoster`, `GramPoster` |
| `config.py` | Config load/save + Gemini prompt presets (shared-library aware) |
| `profile_manager.py` | Per-site connection profiles |

`app.py` is a thin host. It puts `tools/_shared` and `tools/coldsnap` on
`sys.path`, then exposes `@app.api` handlers that map **1:1** to the old tkinter
controls. Two in-memory controllers (`SoloController` / `GramController`) hold
exactly the working state the tkinter panels held (`work_images`, `trig_slots`,
the selected image, the per-image control vars, the editor fields), so every
button/slider/field has a matching `blink.call`.

The shared-library contract is preserved: config, creds, prompts and profiles
still flow through `config.py` / `profile_manager.py` / `snap_creds` /
`snap_prompts` — no new config location is invented. On Linux, `snap_blink`
sets `SNAPSMACK_HOME` to `~/snapsmack` if you don't.

## Run it on Linux

```
cd tools/coldsnap/linux
python3 -m pip install -r requirements.txt
./run.sh
```

`run.sh` sets `PYTHONPATH` (to `_shared` and the tool folder) and launches a
Chromium `--app` window. Any Blink browser works — Chromium, Chrome, Brave,
Edge, Vivaldi. If none is found, `snap_blink` prints a `http://127.0.0.1:PORT`
URL to open in a normal browser tab.

- **Pillow** is required (thumbnails + slicing). Without it the tool won't compose.
- **requests** is only needed for **SYNC**. Compose works fully without it; the
  page shows a banner and SYNC reports the missing dependency.

### File picking

The browser sandbox can't hand Python an absolute file path, so "Choose image…",
"Add images…", "Choose cover & slice", and Export/Import use the desktop's own
native dialog via **`zenity`** (or `qarma`/`kdialog`). If none is installed, each
of those actions falls back to a **paste-an-absolute-path** box in the page, so
no action is ever lost. Install `zenity` for the nicest experience:

```
sudo apt install zenity      # Debian/Ubuntu
```

## Feature parity vs the tkinter version

Every tkinter widget/action has a matching web control + `blink.call`:

### CONNECTION panel
| tkinter | web |
|---|---|
| LOAD PROFILE combobox (`_on_profile_pick`) | `#profile` select → `pick_profile` |
| SITE URL / API KEY entries | `#url` / `#api_key` |
| SAVE / APPLY (`_on_save`) | `save_connection` |
| ? HELP (`_show_help`) | Help modal (same copy) |
| COLD ONE / COLD STACK tab strip (`_switch_tab`) | mode selector |

### COLD ONE (solo) — `sumna_solo.py`
| tkinter | web |
|---|---|
| SESSION combobox / New / Export to USB… / Import… | `solo_select_session` / `solo_new_session` / `solo_export_session` / `solo_import_session` |
| DRAFTS list rows (Edit / Del) | `solo_edit` / `solo_delete` |
| SYNC WITH LIVE (`_sync`, name-the-site confirm) | confirm modal + `solo_sync_target` → `solo_sync` |
| Choose image… | `solo_choose_image` |
| Title / Tags / Caption / ALT | editor fields → `solo_save_draft` |
| ✨ AI Fill (Gemini) | `solo_ai_fill` |
| Category / Album | editor fields |
| Orientation / Status / Colour combos | selects |
| Allow download checkbox / Download URL | editor fields |
| Save draft / ✓ OFFLINE POST / Clear | `solo_save_draft(ready)` / clear |

### COLD STACK (gram) — `sumna_gram.py`
| tkinter | web |
|---|---|
| SESSION combobox / New / Export / Import | `gram_*_session` |
| THE BATCH list (single + trigram rows; Edit / Del) | `gram_edit_single` / `gram_edit_trigram` / `gram_delete` / `gram_delete_group` |
| SYNC WITH LIVE (`_sync`, name-the-site confirm) | confirm modal + `gram_sync_target` → `gram_sync` |
| kind radios single/carousel/trigram (`_on_kind_change`) | `gram_set_kind` |
| Trigram-of radios + orient combo | `gram_set_trig_style` / `gram_set_trig_orientation` |
| Seam A / Seam B sliders + Re-slice | `gram_slice_cover` / `gram_reslice` |
| Band preview | rendered from `compose_state().band` |
| Choose cover & slice | `gram_slice_cover` |
| Add images… / Clear images | `gram_add_images` / `gram_clear_images` |
| thumbnail strip: select / ◀ ▶ move / ✕ remove / + imgs | `gram_select_image` / `gram_move` / `gram_remove` / `gram_add_to_slot` |
| Fit mode radios / Post separately (split) | `gram_write_controls` |
| Image size / Focal X / Focal Y / Zoom / Border / Shadow sliders | `gram_write_controls`, focal/zoom → `gram_recrop` |
| Border color / Background matte | `gram_write_controls` |
| Update crop preview | `gram_recrop` |
| Caption / Tags / Date / Comments / Allow download / Status / Download URL | POST fields → `gram_commit` |
| Save draft / ✓ OFFLINE POST / Clear | `gram_commit(ready)` / `gram_clear_compose` |

### TODO(port) — known, intentional gaps

- **Live per-draft sync badges.** The tkinter version streamed badge updates
  during sync via `after()`. `blink.call` is request/response, so the whole
  batch runs and the **final** per-draft statuses are returned and rendered
  (via `state()`). Outcome is identical; only the intermediate animation differs.
- **Native file dialogs.** Requires `zenity`/`qarma`/`kdialog`; otherwise the
  paste-a-path fallback in the page is used (see *File picking* above).

## Honesty note

Ported for Linux; `app.py` parses clean (`ast.parse`) and `app.js` passes
`node --check`. **Not yet run on Linux hardware** — no Linux Chromium was
available on the build box to launch and click through.

<!-- ===== SNAPSMACK EOF ===== -->
