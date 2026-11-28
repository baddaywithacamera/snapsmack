# OPAUDIT 036 — "Fine" means "I did not look"

> **Evidence note.** Written by the repo seat from its own work and the Brain Trust room record of 27 November 2026. Every instance below was measured in source on `Github/dev` at the stated commit, not taken from a report. Nothing here was exercised over HTTP: there was no running site available to the seat, and where that matters it is said so. Four of the instances are the author's own faults, including two in tests written to catch the very thing they missed.

| Field | Value |
|---|---|
| Audit ID | 2026-11-27-036 |
| State | OPEN. Every instance is repaired, filed or reported; the rules below are new, and the two record gaps in §5 are unresolved. |
| Scope | Controls that report success without having checked, and figures in comments and documents that were never true or stopped being true. Found while repairing SECAUDIT 047, reviewing SECAUDIT 061, and building the mosaic endpoint and the CSRF fixes. |
| Tickets | SS-1426, SS-1612, SS-1632, SS-1633, SS-1634, SS-1635, SS-1385, SS-1603, SS-1636 |
| Builds | v0.7.1008D tagged and published; v0.7.1009D tagged at `2d2eab24`, carrying the two #047 repairs and #061 B+C. 1010D not open at time of writing, which currently blocks every branch on dev. |

## The pattern

A guard that cannot do its job is supposed to refuse. Eight controls found in one day did the opposite: they returned success, or stayed silent, when what had actually happened was that they could not look.

1. **The recovery engine's containment check skipped itself and reported success.** `core/recovery-engine.php` wrapped its path-escape test in `if (realpath(dirname($targetPath)) !== false)`. `realpath()` returns false for a directory that does not exist yet — which is every directory a restore is about to create. So restoring into a new directory skipped the check entirely, `mkdir -p` followed any junction on the way, and the run reported `restored 1, blocked 0, no errors`. The comment above it asserted this could not happen because `..` is refused. Codex proved otherwise on `63fb53d1`.

2. **Four more paths in the same file had no check at all** — worse than a guard that fails open. `restoreImages()` took `img_file`, `restoreMediaAssets()` took `asset_path`, `restoreBranding()` took three settings values, and `regenerateAndChecksum()` took `img_file` again to build a thumbnail directory. Each joined a database value onto the site root with no `..` test, no absolute-path test, no NUL test and no containment, then ran `mkdir -p` and `copy`. On the admin kit-import path every one of those values comes out of the kit's own SQL dump. The audit finding described only the fail-open; a repair to that alone would have shipped with four unguarded write paths open.

3. **The updater returned "verified" for any signature.** `updater_verify_signature()` read the release key from a *constant*, and a constant cannot be redefined. With the all-zero placeholder on disk, `require_once` defined it as zeros, the self-heal rewrote the *file* but could not correct the constant, and the function returned `true` with nothing but an `error_log` to show. Two paths followed: an install whose key file is unwritable never escapes, and — the sharper one — **even when the self-heal succeeds, the constant stays the placeholder for the rest of that request, and that request is the one applying the update.** So on every install that ever healed its own key, the healing update went in unverified. BUZZERS had carried this for three months as a "narrow low-risk edge case".

4. **The same file's docblock said the opposite of what the code did.** It documented "false if invalid or verification is skipped". It returned `true` when it skipped. A docblock that contradicts its function is what a reviewer reads instead of the code, and is the most plausible reason this sat deferred.

5. **The PROX VOX rollback does not roll back, and has no tests.** `tools/proxvox/server/apply_signed_release.py` restores a directory with `copytree(..., dirs_exist_ok=True)`, which *merges*: the snapshot is copied back over, and anything the release added inside that directory stays. A directory the release created is only removed if empty. And `restore_files()` has no per-item error handling, so one failure mid-loop leaves the site partially restored with no record of how far it got. No test anywhere imports the module; neither commit added one. This is the code that would put 25 live sites back together if an update failed.

6. **A mosaic update overwrote what it was not told.** The create defaults — `Untitled Mosaic`, gap 4, layout asymmetric — were applied on *update*, so a client sending a new photograph order and nothing else had the owner's title, gap and layout replaced, with a 200 OK. Same shape as the focus-positions loss a week earlier, in the same file.

7. **A flag that appears to do nothing.** `tw sync-forum --hold TICKET` is documented as "keep this ticket out of the whole sync". It leaves the ticket in the printed plan. Whether it would also have delivered it was not established, because the author chose not to run that phase.

8. **A dry run that promises 44 actions and performs 1.** `sync-forum --dry-run` reported 44 closes; the real run reported 1 closed and 43 already in place. The end state was correct. A dry run whose numbers do not survive contact with the board teaches its reader to ignore them.

## The author's own four, including two in the tests written to catch this

Recorded because an audit that only finds other people's faults is not an audit.

- **A comment that miscounted its own callers, twice.** The containment repair's note said "two callers", was corrected to "three", then two more guards were added and the comment was never revisited — so it shipped naming three of five, inside the commit whose message says a comment that miscounts its callers is how the second copy of the hole got missed. Muse caught it.
- **An enumeration that missed the one that mattered.** SS-1633 was filed claiming twenty-two columns can reference a photograph. It is twenty-six. The four missed were each a site a column scan cannot see, and one of them was `snap_comments.img_id` — a comment attaches to a *photograph*, and 1,904 comments on this fleet are still image-keyed. A finder built to that ticket would have offered to delete photographs people had written on.
- **A check that could not fail.** A test asserted `str_contains($field, $token)` with no guard on `$token`. `str_contains(anything, '')` is true, so it passed while the token was empty — written to catch an empty token.
- **Three near-miss false findings from grepping for literals.** Searching a test for a report's five words returned zero for two of them and nearly produced a report that an open HIGH audit was missing two required regression cases; both were covered under different wording. The same technique nearly produced a false data-loss finding against the signature contract, whose column names live in a builder function rather than in the API. The better the code gets at factoring things out, the more a literal grep lies.

## The second pattern: a figure that was never true, or stopped being true

- **BUZZERS** called the updater fail-open narrow and low-risk for three months. It accepted any signature on the normal healing path.
- **`secaudits/2026-10-29-061-remediation-status.md`** is cited in `CHANGELOG.md` as where the owner's three §5.5 options are written up, **and cited again inside `smack-multisite.php`** to justify a trust-boundary ruling about the owner's own machine. `git log --all --diff-filter=A` over that path returns nothing: it has never been committed, on any branch. The decision is recorded; the options decided between are not.
- **The demo-parity record has eroded over three builds.** 1007D: a features file, a worksheet, a rebaseline mapping and eleven captures. 1008D: a worksheet and two captures. 1009D: nothing. 1009D is the build going to 25 sites.
- **The test-strength record on dev is stale on four files** nobody in this session touched — `smackback-alert-all-admins` 21→37, `updater-installer-cleanup` 5→6, `version-epoch-reaches-the-fleet` predicates 1→2, `telegram-mobile-feed` 13→14. All increases, so nothing is lost, but the gate will keep flagging them until somebody adopts the record.
- **408 git worktrees** are registered on this checkout across 12 locations, against a standing rule written when it was 75 across 6. None are prunable. The largest single item is not a worktree at all: the main checkout holds uncommitted edits and 20,934 untracked files.

## Rules from this audit

1. **A control that cannot check must refuse, never return success.** If a guard's input cannot be resolved, its answer is "no", not "fine". `if (cannot_check) { skip }` is the fail-open shape; search for it by looking for a check nested inside a test of whether the check is *possible*.
2. **A guard's answer must be reported, not swallowed.** A refusal that looks identical to "there was nothing to do" is a refusal nobody can act on. Every restore path now returns a `blocked` count for this reason.
3. **One implementation, and the comment states the relationship, never the count.** Five callers of one containment walk is correct; a comment saying "five" is wrong the moment a sixth arrives. Pin the number in an *assertion* that fails, not in prose that drifts. This repository has now been bitten by a miscounting comment three times in one week.
4. **Absence in a grep is not absence in the code.** A grep finds where to read; it never concludes. Before reporting anything *missing*, read the function that would do the work and follow every variable interpolated into the statement. If a detail contradicts the story forming in your head — a variable you cannot account for, a count that will not reconcile — the detail is right and the story is wrong.
5. **A check must not contain its own needle, and an empty needle is the commonest form.** Any `contains`, `match` or `in` whose needle could be empty passes vacuously. Guard the needle explicitly.
6. **A cited document must exist.** A citation in a changelog or a source comment is a promise that a reader can follow it. Before citing, confirm the path is committed. Two citations in this product currently point at a file that has never existed.
7. **The release record is part of the release.** A build that reaches the fleet with less evidence than the one before it has eroded the record whether or not anybody noticed. The parity captures belong to the pilot that is happening anyway, not to a separate task that gets skipped.
8. **The suite's engine is not the fleet's engine.** The PHP suite runs sqlite; all 25 sites run MySQL. `rowCount()` after an UPDATE counts rows *changed* on MySQL and rows *matched* on sqlite, which came within one commit of refusing every no-op save on the entire fleet while the suite stayed green.

## Recovery and what is still open

Repaired and reviewed: the containment walk across five paths (`5136a81c`, Muse PASS), the updater fail-open (`3c32563b`, Muse PASS), both in v0.7.1009D. The mosaic update's defaulting and its conflict guard (`4aec2c7b`, Muse PASS, in 1008D). #061 B+C hub-side provenance (`1cfb7222`, reviewed here). The four CSRF gaps (`1e9b59ad`, `aabce138`). The anonymiser's placeholder blindness (`f353d68e`). All six still await a Muse pass on the last three.

Still open, and none of it is code:

- **1010D is not open**, so the agreed-rules gate correctly refuses every branch aimed at it, including three of the above.
- **The 1009D demo-parity record does not exist** and cannot be produced without a running site. It belongs to the Bad Day pilot.
- **The PROX VOX rollback reaches 24 sites never having run once.** The cheapest correction is to force a failure on the pilot site so the rollback executes under supervision, then confirm the site is on 1008D and not a hybrid — looking inside `migrations/` and the deprecated directories, which is where the merge defect shows.
- **The missing 061 remediation document**, cited twice.
- **SECAUDIT 061 section 6 requirement 5** is unaccounted for by anybody: no requirements list exists in the repository, and the published two-page report carries findings and status but not the numbered requirements.
- **The worktree debt**, with a read-only report delivered and no deletions made.

A note on what this audit cannot say: every finding above is a source finding. Nothing was proved over HTTP, no live site was touched, and the one control whose repair most changes behaviour on the fleet — the updater now refusing a package on a host without libsodium — has not been checked against a single real site's PHP build.

<!-- ===== SNAPSMACK EOF ===== -->
