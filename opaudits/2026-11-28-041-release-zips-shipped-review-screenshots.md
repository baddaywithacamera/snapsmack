# OPAUDIT 041 — 1009D/1010D release zips shipped 6.7 MB of _review screenshots

<!-- SNAPSMACK_EOF_HEADER
The last non-empty line must be the canonical SNAPSMACK EOF marker.
-->

## What happened

The `0.7.1009D` (9,838 KB) and `0.7.1010D` (9,839 KB, 646 files) release archives jumped from ~2.7 MB (`0.7.975`, 529 files) to ~9.8 MB. Measuring the `v0.7.1009D` and `v0.7.1010D` git tags vs. the physical release zips showed **79 files in each tag** under `_review/` and **76 shipped** in both zips (the 76 image captures shipped into spoke web roots; the 3 JSON files were present in both git tags and absent from both shipped zips):

- `_review/alfred-launch-readiness` (+3,913 KB, including `old-hitchcock/results.json` at 6,406 bytes)
- `_review/ss1563-navigation-current` (+2,293 KB, including `results.json` at 212,699 bytes)
- `_review/onyx-1024-overflow` (+502 KB, including `after-measurements.json` at 1,929 bytes)

The three tag-only JSON files in `v0.7.1009D` / `v0.7.1010D` (absent from the shipped zips) contain:
- `_review/ss1563-navigation-current/results.json` (212,699 bytes): rendered-geometry measurements across five skins.
- `_review/alfred-launch-readiness/old-hitchcock/results.json` (6,406 bytes): names Sean and records the URL, WordPress theme, and plugin fingerprint of `old.mccormickphotography.com` (no credentials).
- `_review/onyx-1024-overflow/after-measurements.json` (1,929 bytes): layout measurements for ONYX at `1024px`.

Shipping the 76 image captures tripled the update archive size (increasing timeout and disk-quota risk on shared hosting) and extracted internal review artifacts into every spoke's web root.

## Why the review missed it

`smack-central/sc-release.php` (`always_exclude`) did not list `_review` or evidence-capture directories, and no automated size/file-count comparison ran against the previous release before publishing.

## Correction and follow-up

1. Commit `b987bd2d` (merged to `dev` in `9d41eb4c`) excludes `_review`, `tests/evidence`, `*-captures`, and `_continuity` from release packages in `smack-central/sc-release.php`, adds a refuse-guard against shipping review evidence, and adds a file-count/size comparison against the prior release on the build screen (bringing provisional `1011D` down to ~3.2 MB / 570 files).
2. Commit `363b5f62` (merged to `dev` in `a1b9f2b7` for `0.7.1011D`) removes the 76 shipped image captures from already-updated `1009D`/`1010D` sites during update finalization (matched by exact SHA-256 inventory, preserving untracked/modified files and removing only empty known directories). Follow-up commit `bd89c13b` adds the three tag-only JSON hashes (`79/79` total) to `core/shipped-review-cleanup.php` as belt-and-braces before `0.7.1011D` tags.

<!-- ===== SNAPSMACK EOF ===== -->
