# OPAUDIT 038 — PIXELFED CLIENT nav entry added without a request

<!-- SNAPSMACK_EOF_HEADER
The last non-empty line must be the canonical SNAPSMACK EOF marker.
-->

## What happened

Commit `6fe29d848831d1fd699a68ff08f8bbb4ef16a22c` (`SS-1589 Clarify admin navigation labels and links`, shipped in `0.7.1005D`) added a **Pixelfed Client** link (`smack-pixelfed.php`) directly beneath **Pixelfed Gateway ↗** / **Mastodon Gateway ↗** (`pixel.php`) in `core/sidebar.php` and `core/sidebar-photochallenge.php`.

Sean did not request the **Pixelfed Client** entry. On live sites (`photofri.day` on `0.7.1007D` and `baddaywithacamera.ca`), the **Set up by the Feds** sidebar section displayed two entries for the same Fediverse client/gateway surface (`PIXELFED GATEWAY ↗` + `PIXELFED CLIENT`). Sean ruled: `"client is extra. it is supposed to be gateway."`

## Why the review missed it

SS-1589 was reviewed for adding the missing Pixelfed link and standardizing labels. The check verified that `pixel.php` kept its **Pixelfed Gateway** / **Mastodon Gateway** label and did not flag that a second, unrequested `smack-pixelfed.php` item was inserted alongside it in both `core/sidebar.php` and `core/sidebar-photochallenge.php`.

## Correction and follow-up

Commit `c301bdc7985ed88a445010937a1c1148aeed3bff` (merged to `dev` in `16ff3909` / `d9e3560b` for `0.7.1011D`) removes the **Pixelfed Client** link from both `core/sidebar.php` and `core/sidebar-photochallenge.php`, leaving **Pixelfed Gateway ↗** and **Mastodon Gateway ↗** (`pixel.php`) untouched.

`smack-pixelfed.php` itself was kept in place (still referenced by `core/auth-smack.php`, active-section maps, `smack-help.php`, and regression tests) so no route breaks while unlinked from the sidebar.

For future navigation changes, every added or moved `<li>` in `core/sidebar.php` and `core/sidebar-photochallenge.php` must map explicitly to a ticket requirement, and duplicate-surface links must be rejected at review.

<!-- ===== SNAPSMACK EOF ===== -->
