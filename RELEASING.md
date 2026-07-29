<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line must be the canonical HTML-comment SNAPSMACK EOF marker.
-->

# SnapSmack Release Workflow

This is the authoritative versioning and release policy. If an older continuity
note, handoff, comment, or instruction conflicts with this file, this file wins.

## The two channels

| Channel | Git branch | Tag | Smack Central manifest |
|---|---|---|---|
| BITCHIN' / beta | `dev` | `vX.Y.ZD` | `latest-dev.json` |
| BORING / stable | `master` | `vX.Y.Z` | `latest.json` |

FEDISTRUCTURE uses the same channels:

| Channel | Manifest |
|---|---|
| BITCHIN' / beta | `latest-fedistructure-dev.json` |
| BORING / stable | `latest-fedistructure.json` |

## Rules

1. All ordinary implementation pushes go to `dev` only. They do not receive a
   tag and cannot alter either release manifest.
2. A beta candidate gets one new, immutable `D` tag on `dev`. Never move or
   reuse a published tag.
3. Smack Central may package a `D` tag only into the dev manifests.
4. Fixes found in beta go back to `dev` and receive the next unused numeric
   version and `D` tag. Do not overwrite the previous beta.
5. Stable promotion uses the exact commit that was tested under its matching
   `D` tag. `master` must fast-forward to that commit; no release-only code
   changes are allowed during promotion.
6. Only after promotion is the plain stable tag created and the stable
   manifests published.
7. Never create the plain and `D` tags together at the start of testing.
8. Never push implementation work directly to `master`.

The source constant uses the base version (`X.Y.Z`). Smack Central stamps
`X.Y.ZD` into dev packages and manifests. Stable packages retain `X.Y.Z`.

## Commands

Use the guarded helper rather than hand-written Git release commands:

```text
php tools/release-flow.php status
php tools/release-flow.php push-dev
php tools/release-flow.php tag-dev 0.7.456
php tools/release-flow.php promote-stable 0.7.456 --yes
```

`tag-dev` runs the repository regression checks before pushing. Promotion
refuses a dirty tree, a missing/mismatched `D` tag, a non-fast-forward master,
or an already-used stable tag.

## Smack Central

- During beta, build only from the BITCHIN' panel and select the `D` tag.
- For a FEDISTRUCTURE beta installer, use `fedup.php?track=dev`.
- After promotion, build from the BORING panel using the plain tag.
- Publishing a Git tag does not update sites. Updating a manifest does.

## Recovery from an accidental stable push

If the stable manifest was not published, preserve the commit on `dev`, revert
or otherwise restore `master` with a normal history-preserving commit, and
remove the premature stable tag only after confirming the exact target. If the
stable manifest was published, never reuse that version: fix forward through
`dev` and promote the next unused version.

<!-- ===== SNAPSMACK EOF ===== -->
