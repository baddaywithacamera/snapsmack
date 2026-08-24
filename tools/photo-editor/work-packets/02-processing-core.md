<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 02 — usable processing core

**Prerequisite:** Packet 01 contracts frozen.

Implement decode/encode for approved JPEG, PNG, WebP and TIFF variants; orientation and
colour-profile handling; proxy preview; full-resolution tiled export; crop with common
ratios; rotate, straighten, flip and resize; histogram/clipping data; recipe undo/redo;
before/after; save copy; explicit atomic working-copy replacement; cancellation and
crash-safe autosave. Build the shared editor window with open, navigation, controls,
export and recovery UI. It may be visually plain but must be complete and keyboard-safe.

Acceptance fixtures cover landscape/portrait, alpha, embedded orientation/profile,
8/16-bit TIFF, corrupt/truncated files, maximum supported dimensions and memory refusal.
Preview and export geometry must match pixel-for-pixel. Replacement failure leaves the
original intact. Windows and Linux render the same golden recipes within codec tolerance.

This packet ends with a genuinely shippable basic editor. Do not start tonal controls
until this commit is clean and its recovery test has been demonstrated.

<!-- ===== SNAPSMACK EOF ===== -->
