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

- Restored source checkpoint: `1c1635af`.
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

## 0.7.596D integration

- Integrated on top of GitHub `dev` release `0.7.595D` without touching
  Claude's separate dirty worktree.
- Release source checkpoint: `1f43fad3`.
- Integrated Qt suite: 56 tests passed.
- Integrated original-preservation/photo-manager suite: 28 tests passed.
- Packaged QA opened a real image, exported a layered PSD, and exited cleanly.
- 0.7.596D packaged EXE SHA-256:
  `C3DDCD07F873BC41D2A5F4C4F243F3AB4CF34666BD88E458BB74BFE19F753F55`.

## Portable project work after 0.7.596D

- The approved `docs/slapper-portable-format-recovery-spec.md` is the governing
  authority. Do not reinstate the rejected `source.json` plus root `preview.jpg`
  archive layout.
- Portable project schema is version 2. Legacy version-1 project documents remain
  readable and are never silently rewritten in place.
- A version-2 archive embeds the byte-identical original, full-resolution TIFF
  composite, JPEG thumbnail, manifest, project document, stable UUID layer records,
  readable EXIF/provenance/dependency metadata, JSON schema, and SHA-256 checksums.
- Saving writes a sibling temporary ZIP64 archive, closes it, independently validates
  required entries, paths, JSON, layer identity/order, checksums, and original hash,
  and only then atomically replaces the destination.
- Independent `tools/slap-back/slap_back.py` QA verified a newly saved package,
  extracted the original, and reproduced its exact SHA-256 hash.
- Qt suite after these changes: 56 tests passed.
- This is not release-ready under the portable-format release gate. SLAP BACK still
  needs its specified GUI and layered PSD, OpenRaster, and layered-TIFF recovery/export
  paths plus public fixtures before the specification may be marked implemented.

<!-- ===== SNAPSMACK EOF ===== -->
