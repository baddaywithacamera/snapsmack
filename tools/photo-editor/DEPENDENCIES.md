<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Photo editor dependency and license inventory

Generated from `cargo metadata --offline` after locking Packet 02. Application code is
SPL 2.1.

## Direct runtime dependencies

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
| image | 0.25.10 | bounded JPEG/PNG/WebP/TIFF decode and encode | MIT OR Apache-2.0 |

`image` is built with default features disabled. The only codec features admitted are
`jpeg`, `png`, `webp`, and `tiff`; AVIF/HEIF is not compiled. Its locked runtime codec
stack is `adler2 2.0.1`, `autocfg 1.5.1`, `bitflags 2.13.1`, `bytemuck 1.25.2`,
`byteorder-lite 0.1.0`, `cfg-if 1.0.4`, `crc32fast 1.5.1`, `crunchy 0.2.4`, `fax 0.2.7`,
`fdeflate 0.3.7`, `flate2 1.1.9`, `half 2.7.1`, `image-webp 0.2.4`, `miniz_oxide 0.8.9`,
`moxcms 0.8.1`, `num-traits 0.2.19`, `png 0.18.1`, `pxfm 0.1.30`, `quick-error 2.0.1`,
`simd-adler32 0.3.10`, `tiff 0.11.3`, `weezl 0.1.12`, `zerocopy 0.8.56`,
`zerocopy-derive 0.8.56`, `zune-core 0.5.3`, and `zune-jpeg 0.5.15`. Every package
offers an MIT, BSD, Apache, Zlib, 0BSD, or Unlicense path. No GPL-only or LGPL-only
package is present.

Test-only `tempfile 3.27.0` and its locked platform support packages are not linked into
release binaries. All offer permissive choices. `r-efi 6.0.0` offers MIT/Apache in
addition to LGPL; SnapSmack selects and distributes it under MIT, and it is test-only.

All are permissive; none applies GPL/LGPL to SnapSmack. Exact selected versions are
frozen by `core/Cargo.lock`. Source package license files are the authority. The release
packager must copy those exact texts into the distributed third-party notices; this list
must be regenerated whenever the lockfile changes.

Dependency admission rule: record purpose, direct/transitive relationship, exact license,
patent/codec surface and removal plan before adding it. GPL-only/LGPL-only, HEVC/HEIC, unclear or
non-commercial terms stop work pending Sean's explicit written decision.

<!-- ===== SNAPSMACK EOF ===== -->
