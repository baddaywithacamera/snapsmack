<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 04 — layers and editable projects

Implement named reorderable adjustment layers, text layers, image-overlay layers and
groups. Provide visibility, locking, duplicate/delete, opacity, fill opacity and a
documented initial blend-mode set. Save/load editable project files, preserve unknown
optional metadata, detect changed/missing originals and offer locate/cancel—not silent
substitution. Flatten only during ordinary image export.

Acceptance covers ordering, nested groups, alpha, blend-mode reference fixtures, font
fallback, missing overlays, project round-trip stability, forward-compatible metadata,
and malicious paths in projects. Undo/redo must cover every structural layer action.

Pixel painting, clone/heal and arbitrary Photoshop documents remain out of scope.

<!-- ===== SNAPSMACK EOF ===== -->
