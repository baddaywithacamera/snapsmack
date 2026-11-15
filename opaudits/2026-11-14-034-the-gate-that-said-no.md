# OPAUDIT 034 — The gate that said no

> **Evidence note.** Written by the repo seat from the Brain Trust room record of 13–14 November 2026 (UTC). Every sha and count below is quoted from the posts of the agent who measured it. Nothing here was re-executed by the author, who has no PHP.

| Field | Value |
|---|---|
| Audit ID | 2026-11-14-034 |
| State | CLOSED. The gate refused a release, the cause was found and explained, the fixtures were corrected through review, and the gate then passed. |
| Scope | The 0.7.994D release gate: a hand-written test list that disagreed with the real gate, a skin-render snapshot drift across 11 skins, and a missing test-strength record. |
| Commits | `402c790b` (refused candidate), `2cace8e6` (SS-1318, drift source), `fdd79343` → `a66be4ef` (strength record), `2c3c66d5` → `bd6f4376` (snapshot re-pin), `v0.7.994D` = `bd6f4376` |

## What happened

The 0.7.994D candidate was dev `402c790b`. The engineering seat (Code) reported "the fourteen-test release-flow matrix, 14 of 14 PASS". The PM ruled the candidate SAFE to tag on the strength of that number, and the owner said yes.

Codex then ran the real gate: `php tools/release-flow.php tag-dev 0.7.994`. **It exited 1 and refused to tag.** `tests/skin-render-snapshot-regression.php` reported 34 `text_sha256` drifts across 11 skins. Codex stopped, confirmed that no local or remote tag existed, and did not re-pin the fixture to make it green.

That refusal is the point of this audit. A ruling of SAFE, an owner's yes and a passing report all existed, and the gate still said no, because it measured something none of them had.

## Why the report and the gate disagreed

The "fourteen-test matrix" was not the gate's test selection. It was a list written by hand from test files whose names contain "release" or "gate". The real gate (`rf_tests()`) does not use a list at all: it globs the directory, **406** `tests/*.php` plus **22** `tests/*.cjs` and `tools/browser-tests/run-regressions.cjs`. The snapshot test was one of the 392 the hand list never ran.

The gate's own source already carried the lesson, in a comment written after an earlier version of the gate missed twenty files: *"A filename pattern is not a test selection. The directory is the suite."* The hand list repeated that mistake one level up.

Code deleted the list and ran the directory instead: **404 of 406 pass**. The second failure was `tests/test-strength-regression.php`. A test file added by SS-1145 had been merged without an entry in the strength record, and the test refuses to adopt a new file by itself, by design.

## Finding the cause, not hiding it

The PM's ruling after the refusal: nobody re-pins the snapshot to make it green. A re-pin is allowed only if the drift is explained as intended, shown to match the owner's rule that skin output changes are deliberate, and reviewed.

- **Bisect (Codex):** the first bad commit was `2cace8e6`, SS-1318, "put next navigation on the left". Its parent passed.
- **What changed (Codex):** on every one of the 34 pages, the only text difference was the nav row, from `« PREV|FIRST|INFO|COMMENTS (0)|LAST|NEXT »` to `« NEXT|FIRST|INFO|COMMENTS (0)|LAST|PREV »`. 34 changed, 34 marker-only, 0 with any other difference.
- **Was it deliberate? (Code):** a left-pointing guillemet on NEXT reads as a mistake, and a re-pin would freeze it. It is not one: SS-1318 shipped `photo-navigation-order.cjs`, which asserts that exact sequence and the `rel=next`/`rel=prev` placement, and Muse had confirmed that tip.

## The repairs, each reviewed before merge

1. **Strength record** — `fdd79343`: five lines, one new entry, no existing number moved. Muse reproduced the counter's own counts (36 / 21) independently. Merged as `a66be4ef`.
2. **Snapshot re-pin** — `2c3c66d5`: regenerated with the test's own `--update`, never by hand. Compared key by key: 175 entries both sides, 0 added, 0 removed, 34 changed, and `text_sha256` the only field that moved, on exactly the 11 skins Codex listed. Muse repeated the comparison her own way and confirmed it. Merged as `bd6f4376`.
3. **Full gate** — on `bd6f4376`, including the browser rail that nobody had run on the candidate: exit 0. `v0.7.994D` was tagged on that sha. The tagged sha differs from the candidate the owner approved by these two fixture commits only, with no product code.

## What the gate could not see

The gate passed, and 994D still broke something. On the oldest install in the fleet, the multisite dashboard stopped at the first spoke, because a new helper lived in a `core/constants.php` that the install's own stale `protected_paths.json` had frozen. That fault is outside what a source-tree gate can measure. Its release note carried the line "rendered dashboard on a long-standing install: NOT CHECKED", and that line rode into the tag. It was repaired in 0.7.995D (SS-1337), whose full gate also passed, and the root cause is a separate ticket. The PM's rule since: a NOT CHECKED that names a rendered admin page on a long-standing install blocks the tag until someone opens it or the owner accepts it in writing.

The snapshot has its own blind spot, found the same night: it hashes text, so an icon drawn as inline SVG, such as the like heart, is invisible to it. A green snapshot says nothing about icons.

## What to check in your own project

1. Is the number you report the number your gate actually runs, or a list someone picked?
2. Does your gate select tests by directory, so a new test cannot be silently left out?
3. When a fixture drifts, does anyone have to explain the drift before regenerating it, and does a second reviewer check the regenerated diff key by key?
4. Can anyone tag past a red gate because a person said yes? Here nobody could.
5. What does your gate structurally not see (installed builds, old installs, icons, rendered geometry), and is that written next to the release rather than assumed covered?

## What this audit does not claim

- It does not claim the gate covers installed builds or old installs; 0.7.995D exists because it does not.
- It does not claim the snapshot proves visual correctness; it hashes text.
- It does not claim independent re-execution of any test by the author.
- Ticket numbering: "SS-1335" was used for two different branches that night (the strength record and the manifest reconciliation). This audit identifies them by commit, and the collision is reported for correction.

Prepared by the repo seat (Claude) with AI assistance, from the room record. Status: **CLOSED**.

<!-- ===== SNAPSMACK EOF ===== -->
