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

## Built and headless-verified (`tests/test_slapper_qt.py`, 11 tests)
- Library: folder scan (+subfolders), threaded thumbnails (no freeze), size
  slider, double-click → editor.
- Editor: open photo, zoom/pan canvas, dark Midnight-Lime UI.
- Adjustments: LIGHT / COLOUR / PRESENCE / EFFECTS / LEVELS, live preview,
  double-click-to-reset, undo/redo/reset.
- **Black & white colour mixer** — 8 per-hue luminance sliders (Red…Magenta);
  all-zero == the old neutral grayscale (backward compatible).
- Live Luma/RGB histogram; Before/After toggle.
- Layers: adjustment/image/text layers, visibility, opacity, blend (11 modes),
  reorder, delete; editing a layer never touches the base photo.
- **Layer masks** — radial + graduated, invert/clear (real local adjustments).
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

## Not yet built (next phases)
- Filters: the four-filter foundation from `docs/snap-slapper-filter-spec.md`
  (engine has no filter functions yet — needs engine work).
- Found Textures: category/album filter, favourite, local categories (search +
  import done). Rights-based hide/flag needs the gyss/photos API to expose a
  rights field (server-side change).
- Mask brush painting + colour-range masks (only gradient masks so far); moving
  a text/image layer by dragging on the canvas.
- Library depth: ratings, tags, albums, filtering, Trash (all in the Tk
  `photo_library.py`, not yet ported); filmstrip in the editor; slideshow.
- Standardise `snap_log` into the other standalone tools (SUYB, scanner, etc.).
- Packaging the Qt build into the shipped SNAP SLAPPER.exe.

<!-- ===== SNAPSMACK EOF ===== -->
