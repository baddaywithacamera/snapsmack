<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, a space, the literal
  string 'SNAPSMACK EOF', a space, and five equals.
-->

# SS-1284: false SMACKBACK breach after an update

**Status:** active recovery runbook. The code repair is in 0.7.993D. SS-1284
does not close until the signed build has been deployed to the affected sites
and a second agent has verified the live result.

## Recognise this incident

This runbook applies only when all of the following are true:

1. The site changed version immediately before the breach.
2. Every reported file is part of that signed release's legitimate change set.
3. The reported on-disk bytes match the signed release, except for a documented
   protected per-install file whose exact expected rewrite is independently
   proved.
4. SMACKBACK reports no unexplained missing, truncated, corrupted, or unexpected
   file.

If any condition is not proved, treat the event as a real breach. Do not use
this runbook to make an unexplained file trusted.

## The 11 November 2026 live cases

All three sites are in CT102 under `/var/www/sites/<hostname>`. Their last clean
scheduled verification recorded 0.7.991D. Their next scheduled verification
recorded 0.7.992D and the stale 0.7.991D baseline classified the legitimate
update files as tampered.

| Site | Detected | Reported count | Reported files |
|---|---:|---:|---|
| `crapshoot.photoblogs.fyi` | 2026-11-11 12:23:02 | 4 | `core/constants.php`, `core/updater.php`, `core/release-pubkey.php`, `core/http-security-headers.php` |
| `curbappeal.photoblogs.fyi` | 2026-11-11 12:23:02 | 6 | the four above, plus `smack-help.php` and `snap-in.php` |
| `someshitifound.photoblogs.fyi` | 2026-11-11 12:23:07 | 5 | the four common files, plus `smack-help.php` |

The directly release-managed files matched the 0.7.992D tag byte for byte:

| File | SHA-256 in `v0.7.992D` |
|---|---|
| `core/updater.php` | `40cabdee181d2c9bb23302efbd72d1552f889fb3f37b7c08158987b48423701c` |
| `core/release-pubkey.php` | `bf8f322f9e3474ac345382174773db843c9c84730f0639e574c3445439c55c0b` |
| `core/http-security-headers.php` | `d392fc9c96c5936a3dce9360b085acc22c9fc091a7b58ab3fbdbb5ae6c82504b` |
| `smack-help.php` | `6a204a396a496e1dbb9d0880e29b2db20191d64aa9fa14b60a400fea278ea71f` |
| `snap-in.php` | `c06573488b36d9bf134de73a19019adce430784a6457c617ab707870fd7f3e72` |

`core/constants.php` is protected and is not overwritten wholesale. The
updater rewrites the installed copy's version fields. On all three sites the
installed file reported `SNAPSMACK_VERSION_SHORT` as `0.7.992D`. Its proof is
therefore an exact diff against that site's pre-update protected copy: only the
documented version/codename rewrite may differ. Do not compare its whole-file
hash to a fresh-install copy from the tag and call the mismatch acceptable.

The live SMACKBACK records contained zero missing, truncated, corrupted, or
unexpected files. The release key was the expected rotated `c72e35ff...` key.

## Cause and why only three sites failed

In 0.7.992D every virtual host used the same temporary directory:
`/tmp/snapsmack_update`. Parallel fleet updates could therefore delete or
replace another site's package while that site was finalising. The old code
conditioned the post-update SMACKBACK baseline refresh on that package still
existing and silently skipped the refresh when it was gone.

The version stamp and extracted files still reached 0.7.992D. The affected site
therefore looked successfully updated while its integrity table still described
0.7.991D. The next cron verification compared the two and entered
BREACH/LOCKOUT.

Only three sites lost this race. The other sites either completed the baseline
refresh before another virtual host cleaned the shared directory or did not hit
the failing interleaving. The common 12:23 detection time is the later scheduled
verification, not the time the files were maliciously changed.

0.7.993D fixes both parts of the incident:

- SS-1284 gives every install a stable, install-specific temporary directory,
  never silently skips the baseline refresh, and reports a missing package.
- SS-1286 prevents scheduled SMACKBACK verification from running while the
  updater's maintenance lock is active.

## Live recovery record

### `curbappeal.photoblogs.fyi`

The signed 0.7.993D update was the successful exit. At 2026-11-11 16:00:40
the updater rebuilt the baseline; at 16:00:41 the incident row was resolved
with `resolution=update`. The resulting manifest contains 516 rows whose
`baseline_origin` is `disk` and two protected install-static rows whose origin
is `install`. This is the updater's signed-update re-baseline path, not an
owner-triggered **Re-initialise baseline from disk** fallback.

Read-only verification after the update found:

- PASS: installed version is 0.7.993D.
- PASS: local SMACKBACK status is CLEAN.
- PASS: all 518 manifest rows match the current disk bytes; zero tampered,
  missing, truncated, or corrupted files.
- PASS: the monitored disk set contains zero unexpected files.
- PASS: the public home page returns the real site with HTTP 200.
- FAIL: the hub still carries the old 0.7.992D BREACH row last seen at
  2026-11-11 15:40:02. Found Textures loaded the fleet page at 16:13:00 and
  16:13:47, and Curb Appeal returned HTTP 200 to the corresponding authenticated
  heartbeats at 16:13:01 and 16:13:48. The sweep therefore ran, but the observed
  row did not advance. Duplicate/canonical-row or write-path state still needs
  to be distinguished.
- NOT CHECKED: authenticated owner admin after recovery.
- NOT CHECKED: the next scheduled SMACKBACK verification.

Do not advance to the next affected site until the stale hub state is explained
or refreshed and the remaining acceptance checks are completed.

The hub has two refresh paths with different behaviour in 0.7.993D. The full
fleet-page sweep writes the spoke version, SMACKBACK status, breach time, and
last-seen time. The per-row **Ping** handler writes the version and last-seen
time but does not write the SMACKBACK status or breach time. A successful manual
Ping therefore cannot clear a stale breach marker; use the full sweep when
verifying recovery, and treat a Ping-only green result as incomplete evidence.

## Prove the classification before recovery

1. Preserve the SMACKBACK page, incident record, `logs/updater.log`, and
   `logs/smackback.log`. Do not clear the incident log.
2. Record the installed version, detection time, full file list, and each
   category count.
3. Extract the exact signed release package or exact immutable tag that the site
   claims to run. Do not use a moving branch or the current working tree.
4. Compare every ordinary reported file byte for byte or by SHA-256.
5. For every protected per-install file, compare against that site's pre-update
   copy and prove the diff is exactly the updater's documented transformation.
6. Confirm EOF markers remain intact and that no reported file is missing,
   truncated, corrupted, or unexpected.
7. If even one file remains unexplained, stop. The false-breach classification
   has failed.

## Supported recovery order

### Primary: signed 0.7.993D update, one site at a time

Sean deploys the signed build. Never use **Update All** while any site still
runs the shared-directory updater that caused this incident.

Order:

1. `curbappeal.photoblogs.fyi`
2. Verify it completely using the checklist below.
3. `crapshoot.photoblogs.fyi`
4. Verify it completely.
5. `someshitifound.photoblogs.fyi`
6. Verify it completely.

Do not start the next site until the current site passes. The signed update is
the preferred exit because the updater replaces the affected code, rebuilds the
baseline from the verified extracted disk, and records the recovery path.

### Fallback: reviewed, owner-authorised disk baseline

Use this only if the signed 0.7.993D update leaves the already-proved false
breach standing. Before this control is used:

1. Codex posts per-file hash or exact-diff evidence against the immutable release
   tag.
2. Muse confirms that evidence independently.
3. The PM reviews both posts and explicitly says **go** in Brain Trust.

No PM go means no re-initialise. The PM reviews the posted evidence; this must
not be described as the PM having inspected the server. After approval, Sean
performs **Re-initialise baseline from disk** himself with password and 2FA,
after the hashes have been reconfirmed immediately beforehand.

This is not permission to trust an unexplained file. Preserve the incident and
record the fallback as the resolution.

### Last resort: per-file Re-bless

Per-file **Re-bless** is never recommended. It is owner-only and is a last
resort after the signed update and reviewed re-initialise paths have failed.
Every file still requires the same per-file proof and PM approval described
above. Never use it merely to make a breach indicator disappear.

## Verification after each site

Record each item as PASS, FAIL, or NOT CHECKED:

- SMACKBACK status is CLEAN.
- A manual full verification checks the expected monitored-file count and every
  category is zero: tampered, missing, truncated, corrupted, and unexpected.
- The hub row no longer carries a breach marker.
- An anonymous request returns the real photoblog with HTTP 200, not the neutral
  maintenance page.
- An authenticated owner can reach the normal admin interface.
- No new breach incident appears on the next scheduled verification.

The update author does not perform the independent live acceptance check.

## Do not do these things

- **Do not Restore.** The stored recovery baseline is the stale 0.7.991D state;
  restoring would replace known-good 0.7.992D files with old files.
- **Do not Re-bless blindly.** It is never the recommended false-breach exit;
  it is owner-only and last resort. A matching filename or plausible change is
  not evidence. Hash or exact-diff proof and PM approval come first.
- **Do not use Update All.** Deliver the updater fix sequentially so the old
  shared-directory code cannot race while installing its own replacement.
- **Do not FTP files into an install.** Use the signed updater or the documented
  owner-authorised baseline route.
- **Do not clear the incident log.** It is audit evidence.

<!-- ===== SNAPSMACK EOF ===== -->
