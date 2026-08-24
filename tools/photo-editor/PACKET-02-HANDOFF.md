<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 02 handoff — usable processing core

**Status:** usable Windows base and Linux host complete; advanced hardening remains.

## Delivered

- Signature-guessed JPEG, PNG, WebP and 8/16-bit TIFF decode/encode with HEIC excluded.
- Decoder metadata collection for orientation and embedded ICC profile; orientation is
  applied before recipes. ICC bytes are preserved in engine state for the colour-managed
  pipeline, without falsely treating browser rendering as colour management.
- Allocation budget and dimension refusal before full decode; corrupt/truncated input
  fails closed.
- Deterministic crop, 90-degree rotation, flip and Lanczos resize operations; bounded
  proxy generation and RGBA histogram/clipping counts.
- Atomic Save Copy that never overwrites an existing destination, plus atomic JSON state
  save primitive for autosave/recovery.
- Sidecar `inspect_image` and `process_export` calls under approved-root confinement.
- Keyboard-safe shared editor shell with undo/redo recipe history and 44px controls.
- Manual and centred ratio crop controls plus original 16-bit bilinear straighten.
- Crash-safe portable autosave with a previous-revision recovery backup.
- Windows Tauri executable, Linux/Blink launcher, portable installers, and exact THE HUB
  roster entries on both platforms.

## Verified

Run from `tools/photo-editor/core`:

```text
cargo fmt --check
cargo test --offline
cargo clippy --offline --all-targets -- -D warnings
node --check ../web/editor-host.js
node --check ../web/editor.js
```

Acceptance tests cover all four formats, 16-bit TIFF, alpha, deterministic geometry,
bounded previews, histograms, corrupt input, memory refusal, and preservation of an
existing output when replacement is not explicitly authorized.

## Remaining Packet 02 hardening

Linux packaging needs an actual Linux hardware run. Full-resolution tiled streaming,
ICC conversion to a defined linear working space, and the integrated calling-app
replacement/return-token workflow are not complete. Do not claim those features yet.

<!-- ===== SNAPSMACK EOF ===== -->
