# OPAUDIT 041 — 1009D/1010D release zips shipped 6.7 MB of _review screenshots

<!-- SNAPSMACK_EOF_HEADER
The last non-empty line must be the canonical SNAPSMACK EOF marker.
-->

## What happened

The `0.7.1009D` (9,838 KB) and `0.7.1010D` (9,839 KB, 643 files) release archives jumped from ~2.7 MB (`0.7.975`, 529 files) to ~9.8 MB. Measuring both zips' central directories showed ~6.7 MB came from `_review/` agent review screenshots that were not excluded by the release packager:

- `_review/alfred-launch-readiness` (+3,913 KB)
- `_review/ss1563-navigation-current` (+2,293 KB)
- `_review/onyx-1024-overflow` (+502 KB)

Shipping those PNGs tripled the update archive size (increasing timeout and disk-quota risk on shared hosting) and extracted agent review captures into every spoke's web root.

## Why the review missed it

`smack-central/sc-release.php` (`always_exclude`) did not list `_review` or evidence-capture directories, and no automated size/file-count comparison ran against the previous release before publishing.

## Correction and follow-up

1. Commit `b987bd2d` (merged to `dev` in `9d41eb4c`) excludes `_review`, `tests/evidence`, `*-captures`, and `_continuity` from release packages in `smack-central/sc-release.php`, adds a refuse-guard against shipping review evidence, and adds a file-count/size comparison against the prior release on the build screen (bringing provisional `1011D` back down to ~3.2 MB / 570 files).
2. Commit `363b5f62` (merged to `dev` in `a1b9f2b7` for `0.7.1011D`) removes the 76 shipped `_review/` files from already-updated `1009D`/`1010D` sites during update finalization (matched by exact SHA-256 inventory, preserving untracked/modified files and removing only empty known directories).

<!-- ===== SNAPSMACK EOF ===== -->
