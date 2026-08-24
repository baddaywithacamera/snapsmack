<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 01 dependency and license inventory

Generated from `cargo metadata --offline` after locking Packet 01. Application code is
SPL 2.1. No image codec or processing library is present yet.

| Package | Version | Purpose | Declared license |
|---|---:|---|---|
| serde | 1.0.228 | typed protocol serialization | MIT OR Apache-2.0 |
| serde_core | 1.0.228 | serde runtime | MIT OR Apache-2.0 |
| serde_derive | 1.0.228 | compile-time derives | MIT OR Apache-2.0 |
| serde_json | 1.0.150 | JSON framing/contracts | MIT OR Apache-2.0 |
| itoa | 1.0.18 | JSON integer formatting | MIT OR Apache-2.0 |
| memchr | 2.8.3 | JSON byte search | Unlicense OR MIT |
| zmij | 1.0.23 | JSON float formatting | MIT |
| proc-macro2 | 1.0.106 | serde build-time macros | MIT OR Apache-2.0 |
| quote | 1.0.46 | serde build-time macros | MIT OR Apache-2.0 |
| syn | 2.0.118 | serde build-time parsing | MIT OR Apache-2.0 |
| unicode-ident | 1.0.24 | Rust identifier tables | (MIT OR Apache-2.0) AND Unicode-3.0 |

All are permissive; none applies GPL/LGPL to SnapSmack. Exact selected versions are
frozen by `core/Cargo.lock`. Source package license files are the authority. The release
packager must copy those exact texts into the distributed third-party notices; this list
must be regenerated whenever the lockfile changes.

Dependency admission rule: record purpose, direct/transitive relationship, exact license,
patent/codec surface and removal plan before adding it. GPL/LGPL, HEVC/HEIC, unclear or
non-commercial terms stop work pending Sean's explicit written decision.

<!-- ===== SNAPSMACK EOF ===== -->
