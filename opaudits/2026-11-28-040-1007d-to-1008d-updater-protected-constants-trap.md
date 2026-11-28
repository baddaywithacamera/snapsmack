# OPAUDIT 040 — 1007D to 1008D update failed on 24 of 27 sites (protected constants.php trap)

<!-- SNAPSMACK_EOF_HEADER
The last non-empty line must be the canonical SNAPSMACK EOF marker.
-->

## What happened

The `0.7.1007D` to `0.7.1008D` fleet update failed on 24 of 27 spokes. `core/constants.php` is protected across updates (never overwritten on an existing install because site configuration lived there), so a helper introduced in the new `core/constants.php` was absent on already-installed `1007D` sites when the updated `smack-update.php` / `core/updater.php` ran, causing a fatal error on **CHECK NOW**.

This was the same failure mode recorded earlier on `0.7.464`: updater and runtime code assumed a symbol from `core/constants.php` existed on disk even though `core/constants.php` is not replaced during an in-place upgrade.

## Why the review missed it

Unit checks and fresh-tree runs load the new `core/constants.php` from the checkout rather than an older site's frozen `core/constants.php`. Without an in-place upgrade test starting from the prior shipped build's protected files, the missing helper did not surface until fleet rollout.

## Correction and follow-up

Commit `e78dc49b` (merged via `903bda57`, shipped in `0.7.1009D`) moves the helper / compatibility guard out of the frozen `core/constants.php` assumption so update checks and updater runs succeed with an older `core/constants.php` on disk.

The release gate now requires loopback in-place upgrade receipts starting from actual prior shipped archives (`1009D` and `1010D`) rather than testing only against a fresh checkout. Any site still stranded on `0.7.1007D` (such as `photofri.day`) requires restoring the missing helper once in the owner's PROX VOX window so `smack-update.php` can pull current builds.

<!-- ===== SNAPSMACK EOF ===== -->
