<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, a space, the literal
  string SNAPSMACK EOF, a space, and five equals.
-->

# SECAUDIT 070 - 1009D to 1011D updater, alert deduplication, and public write protections

| Field | Value |
|---|---|
| Audit ID | 2026-11-28-070 |
| Audit date | 2026-11-28 |
| Severity | HIGH |
| Components | `core/updater.php`, `smack-update.php`, `smackback`, `process-like.php`, `password-reset.php`, `smack-central/sc-release.php` |
| Read point | Fixes merged into `Github/dev` (`153b44b5`, `3c32563b`, `dec45a06`, `d9e3560b`, `a1b9f2b7`, `9d41eb4c`) across SNAPSMACK `0.7.1009D`–`0.7.1011D`. |
| Method | The public SNAPSMACK security-audit method: read-only source inspection, independent review, executed regressions where available, in-place loopback upgrade verification against prior shipped archives, and explicit separation of source, build, and live evidence. No live exploit was used. |
| Status | **FIXED AND SHIPPED** (`0.7.1009D` / `0.7.1010D`): `3c32563b` (SECAUDIT-047), `e78dc49b` (legacy `constants.php` updater), `3672e364` (SMACKBACK alert deduplication). **FIXED IN DEV, NOT YET SHIPPED** (`0.7.1011D` untagged/unpublished): `dec45a06` (`process-like.php` CSRF), `d9e3560b` (`password-reset.php` CSRF), `9d41eb4c` (packager boundary), `a1b9f2b7` + `bd89c13b` (`_review/` cleanup, 79 in each tag / 76 shipped). |
| Reporters | Sean McCormick and Codex |

## 1. Findings

Across `0.7.1009D` through `0.7.1011D`, independent audits and fleet incidents identified five security and integrity gaps:

1. **Deferred updater signature fail-open (SECAUDIT-047):** When signature verification was deferred or missing a key, the updater path could accept an unverified payload instead of failing closed.
2. **Legacy `constants.php` trap (`0.7.1007D` -> `0.7.1008D`):** Protected `core/constants.php` on existing installs lacked newly added symbols, breaking in-place updater execution on 24 of 27 spokes.
3. **SMACKBACK breach-alert amplification:** Active breach conditions could trigger repeated alert emails instead of one alert per active breach.
4. **Public write CSRF / token-issuance defects (SS-1385, SS-1603):** `process-like.php` (`aaa9c970` / `ad272e83`) and `password-reset.php` (`d9e3560b`, SS-1603) had token-issuer or token-validation mismatches that either bypassed protection or refused legitimate requests.
5. **Release artifact boundary (`_review/`):** Release tags (`v0.7.1009D` / `v0.7.1010D`) held **79** files under `_review/` and **76 shipped** in the release zips (~6.7 MB of image captures); the 3 JSON files (`_review/alfred-launch-readiness/old-hitchcock/results.json` [6,406 B], `_review/onyx-1024-overflow/after-measurements.json` [1,929 B], `_review/ss1563-navigation-current/results.json` [212,699 B]) were in the git tags only and absent from the shipped zips.

## 2. Fixes verified

- **Updater signature fail-closed & legacy compatibility (`3c32563b`, `e78dc49b`, `05e269d5`, `153b44b5`, `0.7.1009D`):** Deferred updater verification now fails closed on missing or invalid signatures, and updater checks no longer depend on newly added helpers inside protected `core/constants.php`.
- **SMACKBACK breach-alert deduplication (`35d5df95` / `3672e364`, `0.7.1009D`):** Alert dispatch records active breach state and sends one alert per active breach episode.
- **Public write CSRF & reset token issuance (`aaa9c970`, `ad272e83`, `dec45a06`, `d9e3560b`, `0.7.1011D` dev):** Rebuilt like/write CSRF token issuance and validation on `process-like.php` so tokens are bound to an issuer and verified consistently; `password-reset.php` now issues the CSRF token it requires on submission.
- **Release boundary & finalizer cleanup (`b987bd2d`, `363b5f62`, `a1b9f2b7`, `bd89c13b`, `9d41eb4c`, `0.7.1011D` dev):** `smack-central/sc-release.php` excludes `_review`, `tests/evidence`, `*-captures`, and `_continuity` and enforces pre-build size comparison; `core/shipped-review-cleanup.php` (`363b5f62` + `bd89c13b`) removes all 79 `_review/` entries (the 76 shipped captures plus the 3 tag-only JSONs) when on-disk SHA-256 hashes match the `1009D`/`1010D` blobs.

## 3. Regression and review evidence

- Focused regressions for SECAUDIT-047, SS-1385, SS-1603, SMACKBACK alert deduplication, and `updater-installer-cleanup-regression.php` passed independent Muse review prior to merge into `Github/dev`.
- Loopback signed-updater receipts from both `0.7.1009D` and `0.7.1010D` archives into provisional `0.7.1011D` passed HTTP 200 finalization, SMACKBACK clean state, pre-update backup creation, and complete `_review/` removal while preserving local files.

## 4. Remaining boundary

- Any spoke still stranded on `0.7.1007D` (e.g. `photofri.day`) cannot self-update until its missing helper is restored once during the owner's live maintenance window.
- `0.7.1011D` fixes (`dec45a06`, `d9e3560b`, `a1b9f2b7`, `bd89c13b`, `9d41eb4c`) are merged/staged for `0.7.1011D`, pending final tag and fleet rollout.

<!-- ===== SNAPSMACK EOF ===== -->
