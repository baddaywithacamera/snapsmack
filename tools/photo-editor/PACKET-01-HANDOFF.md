<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 01 handoff — foundation, contracts and license gate

**Status:** complete in the commit containing this file.

## Delivered

- Original Rust sidecar library/binary with JSON-lines request IDs, stable errors,
  contract negotiation, synthetic progress/cancellation and clean shutdown.
- Canonical approved-root confinement for existing inputs/directories, including
  symlink resolution; HEIC/HEIF refusal by extension and HEVC-backed file brands.
- Version 1 job, recipe, project, result and error JSON schemas.
- Shared browser host adapter plus Windows/Tauri and Linux/Blink boundary documents.
- Locked dependency inventory, architecture decisions, security boundary and notices.
- Unit and real child-process acceptance tests.

## Reproduce the gate

From `tools/photo-editor/core`:

```text
cargo fmt --check
cargo test --offline
cargo clippy --offline --all-targets -- -D warnings
cargo package --list --allow-dirty --offline
node --check ../web/editor-host.js
```

## Frozen schema hashes (SHA-256)

- `error-v1.schema.json`: `8bfecdaa78319966fe401735a6c84f20bbb4ab7cf1cd8bd923a2d2b441025e17`
- `job-v1.schema.json`: `87af98e47995caee1ad3005223da556a43902efc4147b111df782911ddd75894`
- `project-v1.schema.json`: `c40013fcb10e4f4b3d162327d506f8e0f212a7777fe8b337534fa383251f966a`
- `recipe-v1.schema.json`: `62098c7cda158233c2e5f815b555695f9ac71f0ad3724a0abd5136f102206cea`
- `result-v1.schema.json`: `c51552204d90b7750d368d568a768c80f5ab11cabf76ffa44913437e4a78906a`

## Packet 02 decisions still open

Select and audit image/colour dependencies; set dimension/allocation/preview budgets;
implement decoder-level format validation; decide the linear working representation;
and build the actual Tauri window/sidecar supervisor. The final distributable license
text extractor remains a Packet 08 release gate. Do not add a codec before updating the
dependency inventory and recording its patent surface.

<!-- ===== SNAPSMACK EOF ===== -->
