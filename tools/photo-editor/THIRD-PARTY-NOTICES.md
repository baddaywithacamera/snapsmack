<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# PIXEL TICKLER — development third-party notices

The sidecar uses the Rust packages and exact versions recorded in
`DEPENDENCIES.md` and `core/Cargo.lock`. They are distributed under their respective
MIT, Apache-2.0, BSD, Zlib, 0BSD, Unlicense and Unicode-3.0 choices. No dependency is
accepted under GPL or LGPL. The `image` crate is compiled without default features and
with JPEG, PNG, WebP and TIFF only. No HEIC, HEIF, HEVC, or AVIF codec is included.

This development notice is not the final binary notice bundle. The release packager is
required to extract the authoritative license files from every locked source package,
retain copyright notices, and fail if the extracted set differs from `DEPENDENCIES.md`.
Packet 08 owns that generated binary bundle and cannot ship without it.

<!-- ===== SNAPSMACK EOF ===== -->
