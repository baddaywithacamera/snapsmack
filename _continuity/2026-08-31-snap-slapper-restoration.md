# SNAP SLAPPER Qt restoration ledger — 2026-08-31

## Rule

Do not replace SNAP SLAPPER files from an older branch or rebuild the installed
executable without comparing against this ledger and the later Qt line ending at
`3cfcb706`. Preserve unrelated changes. A requested change is not permission to
remove, simplify, or redesign any other behaviour.

## Known-good Qt source

- Later Qt feature line: `dev`, through `3cfcb706`.
- Existing handoff: `_continuity/2026-08-31-snap-slapper-ui-handoff.md` on `dev`.
- Current restoration starts from those SNAP SLAPPER-only files and then keeps
  newer directory preferences and fixes made after the branch diverged.

## Behaviour that must not regress

- Editor and library remember whether they were maximized. Closing while
  minimized must not save a small/restored window as the intended state.
- Opening/maximizing an editor must not manufacture a second editor window.
- A newly opened photograph starts fitted and automatically refreshes its fit
  render after the real window layout is known; it must not leave a tiny startup
  proxy stretched across the canvas. `100%` renders native pixels.
- Colour-picker controls use black chrome with a lime outline and lime text.
  The selected colour appears only in the small colour chip; pastel full-button
  fills are forbidden.
- Portrait and square library thumbnails reserve a filename area below the image.
- The folder tree, selected folder, subfolder option, splitter widths, folder font
  size, project folder, and export folder remain persistent.
- The library remains responsive, does not hydrate online-only OneDrive files,
  supports right-click actions and Delete, and prevents duplicate editor windows.

## Recovered later-Qt feature set

- Persistent catalog sources, albums, metadata, saved folders, filters, and
  multi-selection.
- Duplicate-aware import, batch rename/export, operation history/undo, and recipe
  application to library selections.
- Exact duplicate and blurry/dark diagnostics, recoverable Trash, backup,
  slideshow, contact sheet, and printing.
- RAW handoff, layered PSD export, perspective correction, PANOMERGE, editable
  filter layers, colour-range masks, movable layers, TEACH ME, and LEWK AGAIN.
- Normal/Advanced mode corrections and the complete Normal-mode effects set.
- Black/lime colour controls with small swatches.

## Release gate

Before installing a build:

1. Run the full Qt editor tests and original-preservation/photo-manager tests.
2. Run focused tests for window state, duplicate editors, initial fit resolution,
   colour-control styling, and filename cell height.
3. Build to a staged path and pass the packaged real-image startup/render test.
4. Preserve the installed executable as a rollback copy.
5. Replace only `C:\snapsmack\snap_slapper\SNAP SLAPPER.exe`, verify its hash,
   then record the commit and build hash here.

## Verified restoration build

- Qt suite: 56 tests passed.
- Original-preservation/photo-manager suite: 28 tests passed.
- Packaged QA: editor opened a real image, wrote its completion marker, exported
  a layered PSD, and exited cleanly.
- Installed executable SHA-256:
  `AD182E81FCCBA8F7FAC3866F5B8E63A4EE032F4E6A9AAD62ADD4833B8E655394`.
- Rollback executable:
  `C:\snapsmack\snap_slapper\SNAP SLAPPER.before-continuity-restore.exe`.
- Rollback SHA-256:
  `6038587290086B3AC4DB564620B6C12C6441A46F3A47CE4F367D739C370178CA`.

<!-- ===== SNAPSMACK EOF ===== -->
