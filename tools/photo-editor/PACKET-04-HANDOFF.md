<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 04 handoff — adjustment-layer foundation

**Status:** adjustment layers and editable projects usable; text/image/group work remains.

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

Still required for Packet 04: text layers, image-overlay layers, groups, overlay path
confinement, font fallback and their project round-trip fixtures.

<!-- ===== SNAPSMACK EOF ===== -->
