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

- `error-v1.schema.json`: `37bbd395624aa71674c0d90bb332f515593e1ad4a81d5fb1d210d6842d648663`
- `job-v1.schema.json`: `3d60825fab9fc598622dc884add8a3d8f82417f9a1bafe1784c878d84ce483aa`
- `project-v1.schema.json`: `8b9da9d52ea235b4dfcaa5cdd3013b3f6ae08545ee30ebac612f3dd02629b26c`
- `recipe-v1.schema.json`: `1452cca37c91611bad9c487ceadcd07fdf4c76491ce3c0218f9ed7c63a4a3983`
- `result-v1.schema.json`: `025339ac79f4a8c354e3e188b3898f06afc67d242ceac83327b68c9faa856369`

## Packet 02 decisions still open

Select and audit image/colour dependencies; set dimension/allocation/preview budgets;
implement decoder-level format validation; decide the linear working representation;
and build the actual Tauri window/sidecar supervisor. The final distributable license
text extractor remains a Packet 08 release gate. Do not add a codec before updating the
dependency inventory and recording its patent surface.

<!-- ===== SNAPSMACK EOF ===== -->
