<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  Missing or different = truncated/corrupted. Restore before saving.
-->

# 0.7.978D — release readiness

Written by Claude Code at the coordinator's request. **Nothing here is a
decision.** Sean builds, packages and deploys; the tag is Codex's. This is the
list of what is in the open build, what is verified and by what, and what is
not verified — so nobody has to take a claim on trust.

Measured on commit `e018b408`, 6 November 2026.

## Where things actually stand

| | |
|---|---|
| **Shipped to the fleet** | **0.7.977D**, released 2026-11-05 — read from `https://snapsmack.ca/releases/latest-dev.json`, not from a tag |
| **Open build** | **0.7.978D**, codename HEISENBERG DECOMPENSATOR |
| **Tagged?** | **No.** There is no `v0.7.978D`. Latest tag is `v0.7.977D` |
| **Commits in it** | 29 since `v0.7.977D` |
| **Branch** | `dev` |

The fold rule holds: 978D is open and unbuilt, so everything below belongs to
it and does **not** get a new number.

## What ships in the core zip

29 commits. Grouped by what an owner would notice.

**Controls that did nothing and now do**

- The GUTTER slider on the archive appearance page. It was read by nothing; the
  roughly 50px gaps Sean measured at a 4px setting were the stylesheet's own
  fallback. Owner's value now wins on the 12 skins that read it.
- GALLERIA's FORCE SQUARE CROP. It emitted a CSS variable nothing read.
- The blogroll can be put in the owner's order, and the owner's own sites go
  last, with each group flagged so no skin has to recognise the words
  "MY BLOGS".
- The owner's header logo reaches skins that ask for it.
- THUMB STYLE stops claiming it applies to every skin.

**Skins**

- 52-CARD-PICKUP's archive tiles carried a class nothing styled.
- IMPACT PRINTER archive thumbnail insets.
- NEW HORIZON archive thumbnail style (Codex).
- Scroll-time placement declared for every skin.

**GLIDE's shared interface** — paging in both directions with the filter kept,
per-tile comment state and photo link, page size.

**Fleet and release machinery**

- A fleet skin push now includes the site running it (SS-1072).
- The release gate refuses with the exact command when a dependency is missing.
- **The release gate now runs every test file.** It matched names against
  `tests/*regression.php` and so never ran 20 of them, including the two that
  were red in 977D while 977D was being served.
- `REVERTING.md` records how to get back, including where we cannot.

**Tests and honesty**

- Thomas and the help/consent engines were never missing; two tests were
  reading a function that had moved. No skin output changed.
- Two of my own tests were holding fourteen skin updates off Sean's sites.

## Skins changed — these ship through the Skin Packager, NOT the core zip

17 manifests moved since 977D. Each needs packaging and a registry push, or an
installed site stays on the old package under a current-looking core version.

| Skin | 977D | now |
|---|---|---|
| 52-card-pickup | 1.4.17 | 1.4.18 |
| aurora | 1.0.56 | 1.0.57 |
| galleria | 1.3.22 | 1.3.23 |
| game-on | 0.1.40 | 0.1.41 |
| heuristic | 0.1.18 | 0.1.19 |
| impact-printer | 2.1.22 | 2.1.23 |
| instant-camera | 1.0.58 | 1.0.59 |
| jive-turkey | 0.1.44 | 0.1.45 |
| new-horizon | 1.3.26 | 1.3.28 |
| parade | 1.2.54 | 1.2.55 |
| photogram | 2.0.31 | 2.0.32 |
| scroll | 0.1.77 | 0.1.78 |
| slickr | 1.0.50 | 1.0.51 |
| sliders | 0.1.17 | 0.1.18 |
| sudden-impact | 1.0.23 | 1.0.24 |
| the-grid | 1.3.58 | 1.3.59 |
| true-grit | 1.3.24 | 1.3.25 |

NEW HORIZON moved twice inside this build — 1.3.27 in the scroll-time commit,
1.3.28 in Codex's thumbnail commit. That is two steps, not a skipped number.

## Verified, and by what

- **PHP suite: 0 failing of 343.** Whole directory, `tests/*.php`.
- **Skin version/content contract: PASS**, 30 skins, one version one
  file-state. No skin's files changed without its version moving.
- **Skin render snapshots: PASS**, 175 pages across 25 skins.
- **Test-strength gate: holds** across 343 files. Every decrease in this build
  was counted and justified rather than accepted with `--update`.
- **Privileged-wrapper inventory: PASS.** No new `__snapsmackCmsOnly()` call
  site was introduced.
- **EOF markers:** the new files in this build pass. The repo-wide check
  reports 458 pre-existing failures across 1,923 files; that is the standing
  state, not something this build changed.

## NOT verified — read this part

- **Nothing in 978D is built, packaged, signed or deployed.** No zip, no
  registry push, no site updated.
- **No browser measurement of anything in this build, by me.** Every skin and
  control change above is verified by rendered markup and emitted CSS. I drove
  no browser. The geometry — square crops, card shadows, gutters, tile sizes —
  is NOT measured.
  - Codex has Chrome captures for NEW HORIZON at
    `C:/dev/snapsmack-staging/new-horizon-cropped` and
    `new-horizon-thumb-style`.
  - GALLERIA and 52-CARD-PICKUP are assigned to Codex for headed verification
    after landing: `b7a20e64` and `f7b69d15`.
- **The browser half of the release gate cannot run here.**
  `tools/browser-tests/node_modules` is missing, so `rf_browser_tests()` will
  refuse with the `npm ci` line. That refusal is correct and is itself part of
  this build, but it means the node tests are UNRUN on this machine.
- **No installed-build verification of any control.** Every "the owner can now
  set X" above is code and render verified only. Nobody has typed a number into
  a live admin and watched a page change.
- **A spoke learns the blogroll group name on the next hub push**, not before.
- **HIP TO BE SQUARE still carries the same dead `--force-square` control**
  GALLERIA had. Not fixed, not mine.
- **A shortlist of other skins may style archive tile classes their pages never
  emit** — alfred, stanley, writing-with-impact, slickr, rational-geo, chaplin,
  glide, sliders, telegram, tilez. Shortlist only; none verified. My harness
  renders `layout.php`, so wrapper-component skins may be mis-measured.
- **SS-1205** (test-strength threshold artefacts, 11 files), **SS-1206**
  (waiting on a real gate run) and **SS-1207** (THUMB STYLE capability flag,
  and the border-style tooltip claim is still unmeasured) are open.

## What has to happen, and whose it is

1. **Codex** — headed-browser verification of GALLERIA `b7a20e64` and
   52-CARD-PICKUP `f7b69d15`; captures where Sean can see them.
2. **Codex** — the tag, once the gate passes on a machine where the browser
   tests can run.
3. **Sean** — look at the captures, then build, package the 17 skins, sign and
   deploy. All four are his alone.

<!-- ===== SNAPSMACK EOF ===== -->
