<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 01 — foundation, contracts and license gate

**Prerequisite:** master spec approved. **Produces no editing UI.**

Deliver a compilable native sidecar skeleton, shared host adapter skeleton, schemas for
job/project/recipe/result/error messages, fixture corpus rules, and architecture/security
decision records. Choose the native language and every initial dependency only after a
transitive license audit. Record exact licenses and reject HEIC/HEIF and RAW at contract
validation. Define stable error codes, cancellation, progress, path approval, memory
budgets, preview limits and atomic-write behaviour.

Acceptance: Windows and Linux sidecars answer `hello`, negotiate one contract version,
reject unknown required versions, reject HEIC/HEIF by extension/signature, cannot write
outside an approved temporary directory, cancel a synthetic job, and shut down without
orphaning a process. Cargo/package metadata must point to SPL 2.1. Commit the dependency
inventory and generated third-party notices. No GPL claim is permitted.

Handoff: document build commands, chosen versions, unresolved risks, schema hashes and
the commit ID. Later packets may extend schemas compatibly but not reinterpret fields.

<!-- ===== SNAPSMACK EOF ===== -->
