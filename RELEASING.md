<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line must be the canonical HTML-comment SNAPSMACK EOF marker.
-->

# SnapSmack Release Workflow

This is the authoritative versioning and release policy. If an older continuity
note, handoff, comment, or instruction conflicts with this file, this file wins.

## Development release sequence

- The operator-confirmed deployed baseline is `0.7.836D`. The next permitted development release is `0.7.837D`.
- `0.7.826D` and `0.7.830D` through `0.7.835D` are declared gaps in the append-only release ledger. They must never be retagged, packaged, backfilled, or reused.
- Several fixes may be bundled into one numbered release. The versioned changelog must name every included fix.
- Finish one release before beginning the next: package it, deploy it, and check the declared live acceptance surfaces. A tag or source commit is not a finished release.
- A declared gap is an incident record, not proof of a package or deployment. It retires the identifier without falsifying release history.

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
2. A beta candidate gets the next sequential `D` tag only after the complete
   candidate is ready for Smack Central. Do not tag intermediate commits.
3. Smack Central may package a `D` tag only into the dev manifests.
4. A tag becomes immutable when Smack Central publishes that build. Fixes found
   after publication go back to `dev` and receive the next sequential `D` tag.
   An unbuilt mistaken tag may be corrected or removed before publication.
5. Stable promotion uses the exact commit that was tested under its matching
   `D` tag. `master` must fast-forward to that commit; no release-only code
   changes are allowed during promotion.
6. Only after promotion is the plain stable tag created and the stable
   manifests published.
7. Never create the plain and `D` tags together at the start of testing.
8. Never push implementation work directly to `master`.
9. **Skin rendering is a compatibility contract.** With unchanged saved settings
   and unchanged content, every skin must render identically to its pre-strip
   reference. Any difference is a regression and must be restored; it is not a
   redesign opportunity, and the changed output must not be described as
   "authoritative." A release that changes any skin render without an explicit,
   reviewed reason in its release-gate evidence fails. Silence is not approval.

**On `dev`, EVERYTHING carries the `D`** — the source constant
(`SNAPSMACK_VERSION` / `SNAPSMACK_VERSION_SHORT` = `X.Y.ZD`), the changelog
heading, the tag, the package, the manifest. The plain `X.Y.Z` exists only
after promotion to `master` (rule 6). Sean, 2026-09-19: "we are only working on
dev branch right now, EVERYTHING is D." Do not strip the D from the constant
on `dev` for any packaging reason; fix the packager instead. A Git tag alone
does not mean it was packaged or installed. Check the published manifest and package before calling it shipped.
The `tag-dev` helper rejects a skipped or reused number in the same version
series. An unbuilt mistaken tag can be corrected under rule 4; a published tag
must remain at its original commit.

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

The exact-commit release-gate note must record `skin_render_parity: "pass"` and
`skin_render_changes`. The latter is an array and is normally empty. Every
intentional rendering change must name the skin, state the reason, and identify
the approved pre-strip reference used for comparison. Missing evidence, an
undeclared difference, or a claim that the replacement rendering is newly
"authoritative" fails the release.

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
