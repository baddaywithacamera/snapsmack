<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 08 — release, packaging and regression gate

Freeze schemas and migration policy; run dependency/license and patent-surface audits;
place exact third-party notices in `licenses/`; produce Windows and Linux packages;
integrate signed update metadata; and write user help covering formats, RawTherapee RAW
workflow, HEIC refusal, originals, projects, recovery, colour and export.

QA includes golden images, performance/p95 memory, corrupt and adversarial inputs,
decompression bombs, path/symlink attacks, atomic-write interruption, autosave recovery,
keyboard-only use, scaling/high-DPI, screen-reader labels, multi-hour editing and clean
uninstall. Test real Windows and Linux hardware. Do not ship on import-only verification.

Release is blocked by any GPL claim, missing license notice, unexplained dependency,
HEIC/HEIF acceptance, preview/export mismatch, original-data loss or orphaned sidecar.

Handoff records versions, checksums, test machines, known limitations and exact commits.

<!-- ===== SNAPSMACK EOF ===== -->
