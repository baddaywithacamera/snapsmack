# SNAP SLAPPER — Qt (PySide6) rebuild status

Decided 2026-08-27 (Sean): rebuild the editor UI in **Qt/PySide6** because the
Tk UI is not elegant. This overrides `docs/snap-slapper-cross-platform-ui-spec.md`
(which had said stay on Tk). **The image engine is reused unchanged** — this is a
UI-shell swap, not a rewrite.

- **Engine (unchanged):** `tools/hub/editor_engine.py` (pure PIL, no UI). The Qt
  code never does image math; it drives `EditorDocument` and shows `render()`.
- **Old Tk UI (left intact, still runnable):** `tools/hub/editor_ui.py`.
- **Run the Qt app:** `python tools/hub/run_slapper_qt.py`  (needs `pip install PySide6`)
- **Test (headless/offscreen):** `python tests/test_slapper_qt.py`

## Package layout
| File | Role |
|------|------|
| `theme.py` | Midnight Lime palette (from admin) + Qt stylesheet |
| `engine_bridge.py` | PIL → QPixmap; render/original helpers |
| `widgets.py` | ImageView (zoom/pan), SliderRow, Accordion, Histogram |
| `layers_panel.py` | Layers list + add/opacity/blend/reorder/delete + edit-target |
| `editor_window.py` | The editor window; wires everything to the engine |
| `library_window.py` | Folder browser: threaded thumbnail grid → open in editor |
| `app.py` / `__main__.py` | Bootstrap (library is the entry point) |

## Built and headless-verified (`tests/test_slapper_qt.py`, 29 tests)
- Library: folder scan (+subfolders), threaded thumbnails (no freeze), size
  slider, double-click → editor. Plus a **collapsible folder tree** (toggle to
  slide in/out), **sort** by name or EXIF capture date (newest/oldest),
  **filename search**, **click-a-photo → dimensions + file size** in the status
  bar, and larger, readable filenames under each thumbnail.
- Editor: open photo, zoom/pan canvas, dark Midnight-Lime UI.
- Adjustments: LIGHT / COLOUR / PRESENCE / EFFECTS / LEVELS, live preview,
  double-click-to-reset, undo/redo/reset.
- **Black & white colour mixer** — 8 per-hue luminance sliders (Red…Magenta);
  all-zero == the old neutral grayscale (backward compatible).
- Live Luma/RGB histogram.
- **Before/After split** — a draggable divider: original left, edited right,
  each side labelled BEFORE / AFTER; drag anywhere to move the split (the split
  recomposites without re-rendering the engine).
- Layers: adjustment/image/text layers, visibility, opacity, blend (11 modes),
  reorder, delete; editing a layer never touches the base photo.
- **Layer masks** — pick the type first (Radial / Graduated / **Brush**), then
  only that type's controls show. The **Brush** is a paint-on-the-photo window:
  Hide (black) / Reveal (white), adjustable brush, hidden areas tinted red so
  you see the mask (`mask_brush.py`). Invert + Clear apply to any type.
- **Split toning** — colour the shadows and highlights independently (teal/
  orange, warm/cool, etc.); two colour swatches + amount sliders in COLOUR.
  Amount 0 == off, so existing looks are unchanged.
- **Vignette feather** — a slider for how soft or hard the dark-edge fade is
  (50 == the classic look).
- **Darken-only grain** — a checkbox for grain that only darkens (real film
  feel) instead of also brightening.

## Colour engine — the LEWK-underpinning primitives (built this session)
These four are what the Instagram-filter reference (`_spec/Instagram Photo
Filters Technical Reference.md`) showed the engine was missing. All default to
identity, so every existing look/project renders unchanged.
- **Per-colour tone curves** — independent Red / Green / Blue curves plus the
  master RGB, via a draggable **curve editor** (`curve_editor.py`; TONE CURVE
  section). This is what colour-cast / cross-process looks are built from.
- **Colour mix (HSL)** — per-hue **saturation + luminance** (8 bands each,
  mirrors the B&W mixer), `_colour_mix` in the engine; COLOUR MIX section.
- **3-zone split toning** — shadows / **midtones** / highlights, each its own
  colour + amount (COLOUR section).
- **Placed colour glow** — a positioned colour bloom (centre spotlight or
  coloured leak): colour + amount + X/Y + size, `_colour_glow`; GLOW section.
- **Text layers** — edit content, size, and fill colour.
- **Interactive crop** — drag a rectangle on the canvas; cancel restores.
- Geometry: rotate/straighten + Flip H/V + reset.
- **Retouch** — spot heal + red-eye click tool, adjustable size, clear all.
- **Found Textures layer** — search foundtextures.ca, thumbnail grid, add a
  texture as a layer with fit (cover/contain/stretch/tile/original) + blend;
  provenance (id/source/site/licence/date) preserved in the `.slapper`.
  (`found_textures.py` client + `textures_dialog.py`; live fetch verified by
  the user, not in-sandbox.)
- `.slapper` project save/open; recipe save/apply; metadata-preserving export.
- Unsaved indicator (● in title) + close guard.
- **Error/crash logging** via shared `snap_log` → `C:\snapsmack\logs\` (app +
  editor + library); handled dialogs logged too via `snap_errors`.
- **LEWKS gallery** — 14 built-in looks previewed live on the current photo at
  an adjustable strength; applies as a non-destructive layer (engine
  `stack_layers`, never flattens base).
- **Offline Help** (F1) — searchable, 12 Qt-specific topics.
- **Autosave / crash recovery** — recovery copy under
  `C:\snapsmack\snap_slapper\recovery`; restore-on-reopen prompt.
- **Preferences** — export quality, copyright-if-missing, strip-GPS; Export
  honours them. Persisted under `C:\snapsmack\config_files`.
- **Filmstrip** — a toggleable horizontal strip of the current folder's photos
  under the canvas (`filmstrip.py`, threaded thumbnails like the library).
  Click a frame to open it (with the unsaved-edits guard); the Filmstrip
  toolbar toggle shows/hides it and the choice is remembered in prefs.
- **True 100% zoom** — "100%" now re-renders the photograph at its native
  resolution and shows it 1:1, so a focus check shows real pixels (previously
  it showed a window-sized proxy at 1:1, which looked small and was not actual
  pixels). "Fit" keeps the fast viewport-sized proxy for smooth slider drags;
  a freshly opened photo starts fitted. Editing while held at 100% re-renders
  full-resolution, so it is slower than Fit — flip to Fit for fast tuning.

## Added for build 0.7.562
- Persistent catalogue sources, ratings, tags, favourites, albums, filtering,
  recoverable SNAP SLAPPER Trash, duplicate-aware imports, transactional batch
  rename, move/copy history and undo, rotated copies, and selected-photo export.
- Editable Orton, Film Grain, Light Leak, and Pastel filter layers with masks,
  recipes, deterministic seeds, colour controls, direct Light Leak placement,
  project persistence, and selected-photo batch application.
- Colour-range masks and direct canvas movement for text/image layers.
- Slideshow, JPEG contact sheets, printing, and visible Present/Print commands.
- Safe RAW handoff to RawTherapee, darktable, or a chosen external application.
- Hub-profile `Blog Copy` preparation: local-only, collision-safe staging copies
  with an auditable `.snapstage.json` manifest; it never uploads or publishes.
- Real per-run crash logging, readable 12–13px interface defaults, and a bounded,
  private filmstrip thumbnail queue that cannot starve the application.
- Layered PSD export with a full-resolution visible composite and independently
  parseable, named raster checkpoints for the base and every SNAP SLAPPER layer.
  Custom filters are identified honestly as raster checkpoints rather than being
  misrepresented as native Photoshop adjustment layers.

## Not yet built (later phases)
- Found Textures: category/album filter, favourite, local categories (search +
  import done). Rights-based hide/flag needs the gyss/photos API to expose a
  rights field (server-side change).
- 100% loupe polish: render only the visible viewport crop at native res
  (instead of the whole photo) so editing while held at 100% stays fast on
  very large files. Correct-but-slower full-native render ships now.
- Standardise the new shared `snap_log` into the other standalone tools (SUYB,
  scanner, etc.). SNAP SLAPPER now uses it in source and frozen builds.
- ~~Packaging the Qt build into the shipped SNAP SLAPPER.exe~~ — **DONE 2026-08-28**
  via `tools/hub/slapper_qt.spec` (PyInstaller onefile, ~56 MB). Verified: 41/41
  tests pass, exe launches headless without a startup crash, and no credential/
  fleet modules ride along (SECAUDIT 051). Build:
  `python -m PyInstaller tools/hub/slapper_qt.spec`.

<!-- ===== SNAPSMACK EOF ===== -->
