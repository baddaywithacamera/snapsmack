<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 04 handoff — adjustment-layer foundation

**Status:** complete.

Delivered:

- named adjustment layers containing nested non-destructive operations;
- visibility, locking, opacity and fill opacity;
- normal, multiply, screen, overlay and soft-light modes in linear light;
- duplicate, delete and reorder controls;
- snapshot undo/redo for every recipe and structural layer change;
- 32-level nesting and 4,096-operation safety limits;
- refusal when an adjustment layer attempts to change canvas geometry;
- deterministic compositing with base alpha preserved.
- portable `.spt` editable project save/load on Windows and Linux;
- full-source content fingerprints that refuse silently changed originals;
- locate/cancel flow for missing originals, accepting only the exact original content;
- atomic project replacement with recovery backup;
- preservation of unknown optional metadata for forward compatibility;
- project safety limits and rejection of relative source paths.
- non-destructive multiline text layers with generic Sans Serif, Serif and Monospace
  families, deterministic safe fallback, size, colour, position and alignment;
- text visibility, locking, opacity, fill, blend modes, ordering, duplication, deletion,
  undo/redo, preview/export parity and `.spt` persistence.
- self-contained JPEG, PNG, WebP and TIFF image-overlay layers for logos, watermarks,
  borders and textures, with scale, expanded-canvas rotation, signed positioning,
  opacity, fill and blend modes;
- 8 MB overlay admission limit, decoder dimension/memory checks and strict rejection of
  SVG, HEIC/HEIF and disguised unsupported payloads;
- embedded overlay assets that remain portable on USB and cannot escape through a
  malicious project path; edit/reorder/duplicate/lock/hide/delete and undo/redo included.
- nested groups built from selected layers, with visibility, locking, opacity, fill,
  blend modes, ordering, duplication, ungrouping and undo/redo;
- groups accept layers only, cannot hide geometry changes, and obey the existing
  32-level nesting and 4,096-operation safety ceilings;
- group structure survives stable `.spt` project round trips.

Packet 05 may now build styles and masks on this frozen layer/project foundation.

<!-- ===== SNAPSMACK EOF ===== -->
