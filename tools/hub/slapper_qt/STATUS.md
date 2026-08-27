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

## Built and headless-verified (Phases 1–5)
- Library: folder scan (+subfolders), threaded thumbnails (no freeze), size
  slider, double-click → editor.
- Editor: open photo, zoom/pan canvas, dark Midnight-Lime UI.
- Adjustments: LIGHT / COLOUR / PRESENCE / EFFECTS / LEVELS + Black & White,
  live preview, double-click-to-reset, undo/redo/reset.
- Live Luma/RGB histogram; Before/After toggle.
- Layers: adjustment/image/text layers, visibility, opacity, blend (11 modes),
  reorder, delete; editing a layer never touches the base photo.
- Geometry: rotate/straighten + Flip H/V + reset.
- `.slapper` project save/open; recipe save/apply; metadata-preserving export.
- Unsaved indicator (● in title) + close guard.

## Not yet built (next phases)
- Interactive crop (drag rectangle on the canvas). Geometry crop field exists in
  the engine; only rotate/flip are wired so far.
- Masks: brush/gradient/colour-range mask painting per layer (engine supports
  masks; no Qt painting UI yet).
- Filters: the four-filter foundation from `docs/snap-slapper-filter-spec.md`.
- LEWKS UI (recipe apply exists; the LEWK browser/gallery does not).
- Text layer editing UI (text layers can be added and render, but content/font/
  colour are not yet editable from Qt).
- Library depth: ratings, tags, albums, filtering, Trash (all in the Tk
  `photo_library.py`, not yet ported).
- Spot/red-eye retouch UI, filmstrip in the editor, slideshow.
- Autosave/crash recovery, Preferences, offline help.
- Found Textures integration.
- Packaging the Qt build into the shipped SNAP SLAPPER.exe.

<!-- ===== SNAPSMACK EOF ===== -->
