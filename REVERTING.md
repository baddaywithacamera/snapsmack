<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line must be the canonical HTML-comment SNAPSMACK EOF marker.
-->

# Getting Back To Where It Worked

Ordered before 0.7.978D ships, because the fixes in it touch admin controls
across the fleet and the owner's words were: make the fixes, but make sure we
back up and can revert if there is a regression.

**Everything below was checked against the running servers and the shipping
code on 5 November 2026, not assumed.** Two of the three things the plan was
expected to rest on turned out not to be true, and those are stated first,
because a revert plan that is wrong is worse than knowing you have none.

---

## What is NOT true today

**1. Old release zips are NOT kept forever. Only the last three exist.**

Checked by asking the server for each one:

| Build | `snapsmack-<version>.zip` |
|---|---|
| 0.7.976D | present, 2,860,629 bytes |
| 0.7.975D | present, 2,860,452 bytes |
| 0.7.974D | present, 2,859,829 bytes |
| 0.7.973D and older | **404, gone** |

So a core install can be put back by zip **three builds deep and no further**.

**2. Old skin packages are NOT kept at all. Only the current one exists.**

| Package | Result |
|---|---|
| `impact-printer-2.1.22.zip` | present, 15,432,415 bytes |
| `impact-printer-2.1.21.zip` | **404** |
| `impact-printer-2.1.20.zip` | **404** |
| older | **404** |

The registry lists exactly one version per skin, with one `download_url` and no
history array. **There is no way to put a skin back to its previous version
from SMACK CENTRAL.** The moment a new package is published the old one is
gone. This is the biggest hole in the plan and it is not a code change away: it
needs the packager to stop deleting, or to publish under a retained name.

**3. What IS true:** the 0.7.977D tag is a known-good git point, and git has
every version. `v0.7.977D` is `c8de6d400bb67ff3de5c0e2fb3e7d1f174860bee` on
origin, and the version constant at that tag reads `0.7.977D`. Source recovery
is never the problem. Getting signed, installable artefacts back is.

---

## Reverting a core release on a site

A core update changes two things and **the automatic safety net covers only
one of them.**

### The database half — automated, and retained

`core/updater.php` forces a backup before it extracts anything. It is a
**database dump only**: `updater_create_backup()` writes
`backups/pre-update_<version>_<timestamp>.sql` by walking `SHOW TABLES`.
`updater_rollback($backup_file)` restores one. `updater_prune_backups()` keeps
the **last three**, so the dumps survive a successful update rather than being
cleaned up.

So the database can be put back, three updates deep, from the site's own
`backups/` directory.

### The file half — NOT automated

The updater does not keep the code it overwrote. To get the previous files back
you re-install the previous release zip — which exists only for the last three
builds, per the table above.

### The order that matters

Restore **files first, database second**. A newer schema under older code is
the state nothing is tested against: the old code does not know about columns
the migration added, and whether it tolerates them is luck. Older schema under
newer code at least fails in the place the migrations run.

### What to do, concretely

1. Take a fresh database dump **before touching anything**, so the broken state
   is recoverable too. A revert that destroys the evidence makes the next
   diagnosis impossible.
2. Install the previous release zip from
   `https://snapsmack.ca/releases/snapsmack-<version>.zip`, checking the
   `checksum_sha256` and `signature` from the release manifest before unpacking
   anything.
3. Restore the matching `backups/pre-update_<version>_<timestamp>.sql`.
4. Confirm the public footer reports the version you expect, and that the
   public pages render, before calling it done.

**If the build you need is older than three, there is no zip.** Say so rather
than improvising: the next step is a fresh package cut from the git tag, which
is the owner's to build and sign, and it will not be byte-identical to what was
originally installed.

---

## Reverting a skin version

**There is no supported route today.** The previous package does not exist on
the server and the registry offers no history.

What is actually available:

- The skin's source at any version, from git.
- Re-packaging that version through the Skin Packager, which the owner does.
  That produces a NEW package with a new signature, not the original artefact.

So "revert the skin" currently means "re-publish the old version as a new
package". Until packages are retained, treat every skin publish as one-way and
decide accordingly.

---

## What is backed up, and where

| Thing | Where | How many kept | Automatic? |
|---|---|---|---|
| Site database, before a core update | `backups/pre-update_*.sql` on the site | last 3 | yes, forced |
| Site database, on demand | `smack-backup.php` | operator's choice | no |
| Core release zips | `snapsmack.ca/releases/` | **last 3 only** | yes, by deletion |
| Skin packages | `snapsmack.ca/releases/skins/` | **current only** | yes, by deletion |
| Source, every version | git, tags on `dev` | all | yes |
| Site files, before a core update | **nowhere** | — | **no** |

The last row is the one to remember. Nothing backs up the site's files before
an update.

---

## The part that is NOT done

**The revert has not been tested end to end.** The order asked for a proof on a
scratch install, and this document does not contain one. Everything above is
read from the shipping code and measured against the live servers; none of it
is a demonstration that a restore produces a working site.

That test needs an install to break and put back, and it is the difference
between a plan and a procedure. Until it is run, treat this as a map rather
than a guarantee, and do not let it be cited as evidence that reverting works.

Smallest honest version of that test: install the current build on a scratch
site with real content, update it, confirm the forced dump exists, deliberately
revert files and database by the steps above, and record whether the site
renders and the version reports correctly. One run, written down.

---

## For 0.7.978D specifically

Every fix is its own commit with its own before/after test, never bundled, so
any one can be reverted alone — a single `git revert` on one commit, rather
than unpicking a batch. That is the only part of revert safety that is free,
and it is free only if the commits stay separate.

<!-- ===== SNAPSMACK EOF ===== -->
