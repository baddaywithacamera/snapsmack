# SnapSmack Architecture Conventions

## CRITICAL — Promotional Site (projects/snapsmack-ca/)

**Only change what you are explicitly asked to change. Nothing else.**

The promo site files are NOT tracked by git and have no backup. Every uninvited change destroys work the user cannot recover. This has caused repeated rework and wasted sessions.

- Do not touch styling, layout, or content unless the user names it specifically.
- Do not "fix" things that look wrong to you. Ask first.
- When making a surgical change (e.g. update one href), touch only that attribute. Do not rewrite the surrounding block.
- `index.html` is the canonical reference for header/nav/tagline styling. Match it exactly on other pages — do not invent variations.
- These files are not in git. There is no undo. Every mistake requires manual reconstruction.
- **Before editing any file in `projects/snapsmack-ca/`, create a `.bak` copy first** using `cp filename.html filename.html.bak`. Do this without exception, every time, before the first edit in a session. The .bak files live alongside the originals and are not uploaded to the server.

## Version & Headers

Version is defined once in `core/constants.php` (`SNAPSMACK_VERSION`, `SNAPSMACK_VERSION_SHORT`, `SNAPSMACK_VERSION_CODENAME`) and `smack-central/sc-version.php`. Git handles per-file versioning — **do not put version numbers in doc-block headers**.

**Smack Up Your Backup versioning:** SUYB uses the same `0.7.9x` scheme as SnapSmack, where the letter increments independently per SUYB release. `BUILD_VERSION` in `tools/smack-up-your-backup/main.py` is SUYB's version — it is NOT SnapSmack's version and is NOT a separate 0.1.x/0.2.x series. When bumping SUYB: increment the letter (e.g. `0.7.9c` → `0.7.9d`), add a CHANGELOG entry in `tools/smack-up-your-backup/CHANGELOG.md`, and update `BUILD_VERSION` in `main.py` to match.

Every PHP file opens with a standardised doc-block:

```php
<?php
/**
 * SNAPSMACK - [Module Name]
 *
 * [Description of what this file does.]
 */
```

Follow the existing header and commenting style exactly. Do not invent new formats.

## CRITICAL — Skins Must Never Be Deleted

**Never delete, remove, or `git rm` any skin directory.** Skins are the primary
differentiator for SnapSmack installs. If a skin is missing from git it will be
lost from all future releases and cannot be recovered without going back to a
live server. If a skin needs to be hidden from users, remove it from the skin
gallery — do not delete the files.

This rule has no exceptions.

## CRITICAL — Release Checklist (Do Not Skip Any Item)

**Every single release, without exception, requires all of the following. Claude has repeatedly missed these. Check each one before declaring work done or suggesting a git push.**

### 1. Version strings — bump in BOTH places
- `core/constants.php` — `SNAPSMACK_VERSION`, `SNAPSMACK_VERSION_SHORT`, `SNAPSMACK_VERSION_CODENAME`
- `smack-central/sc-version.php` — `SC_VERSION`, `SC_CODENAME`
- Both must match. Missing one = admin shows wrong version. **This has been missed multiple times.**

### 2. CHANGELOG.md — add the release entry
- Entry format: `## 0.7.9X — "Codename" (YYYY-MM-DD)` followed by `### Added`, `### Fixed`, etc. with `- bullet` items.
- The release packager reads CHANGELOG.md **from the git tag** via raw GitHub URL. If the entry isn't there, or the tag points to an old commit, the packager shows nothing.
- After editing: check for null bytes — `python3 -c "d=open('CHANGELOG.md','rb').read(); print(d.count(b'\x00'), 'nulls')"` — strip if any found.
- After pushing master, **move the tag**: `git tag -f vX.X.XY && git push Github vX.X.XY --force`

### 3. Help system (smack-help.php) — add or update topics
- Every new admin page or user-facing feature needs a help topic.
- Topics go in `$help_topics['key']` with `section`, `title`, `icon`, `content` (heredoc HTML).
- **This has been missed multiple times.** Do not push without checking.

### 4. Canonical schema (database/schema/snapsmack_canonical.sql)
- Every new table must be added here or schema-sync won't find it.
- Every new table also needs a numbered migration in `/migrations/`.

### 5. HTML structure — validate any touched PHP layout files
- Unclosed tags (missing `>`, missing `</div>`) cause catastrophic layout failures.
- Any file that includes or outputs HTML structure should be spot-checked at EOF.
- **sidebar.php lost its closing `</div>` and `>`, swallowing the entire main content area into the sidebar. This shipped.**

### 6. SYBU companion tool checklist (when SYBU changes ship)
- `BUILD_VERSION` in `tools/sybu/main.py` — bump the letter
- `tools/sybu/CHANGELOG.md` — add entry
- Spec file `tools/sybu/smackyourbatchup-X.X.Xb.spec` — update `name=` field if version changed
- Run `build.bat` to produce new exe
- `git tag -f vSYBU-X.X.Xb && git push Github vSYBU-X.X.Xb --force` (or equivalent)

## Absolute Rules

1. **No inline `<script>` blocks.** All JavaScript lives in `/assets/js/`. Engine scripts follow the naming convention `ss-engine-{name}.js`. Skins declare which scripts they need via `require_scripts[]` in their manifest. The only exception is the existing `<style>` blocks in `skin-header.php` for conditional CSS overrides (bevel, wood grain, square crop) that require PHP logic.

2. **No inline `<style>` blocks for new CSS.** All skin styling goes in the skin's own `style.css`. Dynamic overrides (colours, fonts, sizes, textures) are compiled into the `custom_css_public` blob by `smack-skin.php` at save time. Use the manifest's `selector`/`property` system or `custom-`/`css` block pattern — never inject CSS from PHP.

3. **Skins never get their own JS or fonts.** They check out what they need from the CMS via their `manifest.php`. Available resources are declared in `core/manifest-inventory.php`.

## Directory Structure

Everything that deploys to a web server lives at **repo root**. Non-web projects live under `projects/` or `tools/`. Nothing else belongs in the root.

```
/assets/css/          — Global and engine CSS (ss-engine-*.css, public-facing.css)
/assets/js/           — All JavaScript (ss-engine-*.js, formatting-toolbar.js, etc.)
/assets/fonts/        — TTF fonts (all open-use), one folder per family with license copy
/core/                — Shared PHP: auth, meta, sidebar, header, footer, manifest-inventory
/licenses/            — Consolidated licensing (fonts, libraries, SnapSmack itself)
/skins/{skin-name}/   — One directory per skin (HYPHENS only, never underscores)
    manifest.php      — Declares options, features, required scripts, fonts
    style.css         — All skin CSS (defaults via :root variables)
    skin-header.php   — PHP conditional CSS overrides + header HTML
    skin-footer.php   — Footer HTML
    layout.php        — Single image view template
    landing.php       — Landing page template
    archive-layout.php
    skin-meta.php
    help.php
    assets/           — Skin-specific images (wall textures, etc.)
/migrations/          — SQL migration files
/smack-central/       — Hub admin system (deploys to hub only, not standard installs)

/tools/               — Companion desktop applications (NOT deployed to web servers)
    oh-snap/          — Tauri skin designer desktop app
    sybu/             — Sync your blog up
    smack-some-shit-up/
    fix-your-batch-up/
    smack-up-your-backup/
    unzucker/

/projects/            — Separate web projects (NOT part of blog install)
    forum-server/     — SnapSmack community forum server
    snapsmack-ca/     — snapsmack.ca landing page site
```

## Manifest System

- `core/manifest-inventory.php` is the **single source of truth** for available fonts, Google Fonts, and JS engines.
- Skin `manifest.php` presents what the skin uses back to the CMS.
- Options with `selector`/`property` generate CSS automatically at save time.
- Options with `'property' => 'custom-*'` use the `'css'` key on each select option to emit full CSS blocks.
- Options with no `selector`/`property` are saved to DB but handled by PHP (e.g. bevel style, wood grain).
- Font options should merge local fonts: `foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $fonts[$_k] = $_f['label'];`
- Exception: skins using `allowed_fonts` (like impact-printer) deliberately restrict the font list.

## Database Conventions

- All skin settings use the `htbs_` prefix (shared between Galleria and Hip to be Square).
- Settings stored in `snap_settings` as key-value pairs.
- `INSERT IGNORE` for initial seeding; `ON DUPLICATE KEY UPDATE` for saves.
- Changing a skin's defaults in the manifest does NOT require a migration — the manifest `default` is used as fallback when no DB value exists.
- **Every new table or column added to `snapsmack_canonical.sql` must have a corresponding numbered migration file in `/migrations/`.** Name it `NNN_description.php` using the next available number. The migration must be idempotent (check before applying). This is what the in-admin update runner uses — schema changes without a migration file will not reach existing installs.

## CSS Architecture

- `style.css` provides defaults via `:root` custom properties.
- The compiled CSS blob (`custom_css_public`) loads AFTER `style.css` and overrides defaults.
- Load order in `core/meta.php`: public-facing.css → @font-face → style.css → dynamic compiled CSS.
- Wall textures use the `custom-wall-texture` property pattern with per-option `css` blocks.
- Wall background colour targets `--wall-bg` CSS variable on `:root`.

## Design Decisions

- Do not ask for design decisions. Just make them. The answer is yes.
- Proxy blocks `git push` from the Cowork VM — user pushes from their local machine.
- `database/schema/snapsmack_canonical.sql` is maintained here and committed with every schema change. Do not ask the user to update it.
- Git workflow: Claude stages and commits. User pushes.

## Git Index Corruption

The repo was previously on an OneDrive-synced path which caused repeated git
index corruption (bad signature 0x00000000). The repo now lives at `C:\dev\snapsmack`.
In MINGW bash, use `cd /c/dev/snapsmack` (not `cd C:\dev\snapsmack`).
If index corruption ever recurs, the fix is:

```bash
rm .git/index
git read-tree HEAD
git add <files>
git commit
```

## Current Work State (as of session end)

**CRITICAL — Update this section at the end of every session.** If this section is stale, the next session starts with wrong assumptions. At minimum: current version number, what just shipped, what's pending FTP, and any version bumps to companion tools.

### SnapSmack — Alpha 0.7.9P "Spam Blocker"
All commits are on `master`. Push from local:
```
git push Github master
```

**Latest changes (0.7.9P):**
- Akismet API key field restored to Admin → Settings → GLOBAL COMMENTS
- `core/spam-check.php` fixed: URL no longer hardcoded, uses HTTPS curl instead of fsockopen
- snapsmack.ca anti-spam section renamed and rewritten (SMACK DAB / SMACK DOWN / SMACK UP)
- `smack-settings.php` — new DOWNLOADS box: Require Download Link + Default Download Mode settings (hub installs)
- `smack-appearance-solo.php` — updated YES label for stricter require-link behaviour
- `smack-post.php` — validation fix: download_link_required now fires for any published post without a URL, not just when allow_download is also ticked
- `smack-help.php` — added "Require Download URL" help subsection
- `smack-audit.php` (NEW FILE) — JSON endpoint: GET summary, GET list, POST update_title
- `projects/snapsmack-ca/index.html` — FYBU section replaced with SYBU (Audit & Repair)

**Pending on live servers (FTP these files):**
- `smack-settings.php`
- `smack-post.php`
- `smack-appearance-solo.php`
- `smack-help.php`
- `smack-audit.php` (NEW — also needs FTP to pixhellated.ca and wateronthebrain.ca)
- `core/spam-check.php`
- `projects/snapsmack-ca/index.html` (to snapsmack.ca server)

**After FTP:**
- Enable "Require Download Link" on foundtextures.ca Admin → Settings → Downloads

### Smack Your Batch Up (SYBU) — v0.7.9b "Audit & Repair"
Tool lives in `tools/sybu/`. All commits on `master`. Push from local: `git push Github master`

**To rebuild the exe:** run `build.bat` in `tools/sybu/`
Output: `C:\SmackYourBatchUp\smackyourbatchup-0.7.9b.exe`

**What's new in 0.7.9b:**
- POST / AUDIT / REPAIR tab strip in header
- AUDIT tab: live summary stats, duplicate title groups, missing Drive links list, Go to Repair button
- REPAIR tab — three actions:
  - Rename Drive Files to {id}.jpg (150ms rate-limit delay, resumable stop/start)
  - Re-enrich Duplicate Titles (download from Drive → Gemini → unique title → blog, 4× retry, 500ms delay)
  - Backfill Missing Drive Links (inline per-post URL entry using smack-backfill.php)
- `drive.py` — added `rename()` and `download_to_temp()` functions
- `poster.py` — added `audit_summary()`, `audit_list()`, `audit_update_title()` to SnapSmackClient

**Pending:**
- Rebuild exe (run `build.bat` in `tools/sybu/`)
- Run Repair → Rename Drive Files to {id}.jpg on foundtextures.ca (1,431 files)
- Run Repair → Re-enrich Duplicate Titles on foundtextures.ca (287 posts)
- Fix 3 posts missing Drive links (IDs 883, 1008, 1009) via Repair → Backfill

### Smack Up Your Backup (SUYB) — v0.7.9d
All commits on `master`. Push from local: `git push Github master`

**To rebuild the exe:** run `strip_nulls.py` then `build.bat` in `tools/smack-up-your-backup/`

**Status:**
- Cloud backup to Google Drive working — SA key configured at pixhellated.ca
- B2 credentials in Edit Sync Job now save correctly (save moved inside dialog._save())
- SA key: `C:\SmackUpYourBackup\suyb-drive-key-5e7a5909f75e.json`
- Drive folder ID: `12UFKgvSNtM9uKCjtttyhFPvD45wjXVv9`
- Drive folder shared as Editor with: `smack-up-your-backup@snapsmack-backups.iam.gserviceaccount.com`
- Profiles and config persist next to the exe in `C:\SmackUpYourBackup\`

**Pending:**
- Rebuild exe from `tools/smack-up-your-backup/` (run `strip_nulls.py` then `build.bat`)
- Test: enter B2 credentials → Save → reopen → fields persist
- Run Audit & Cleanup on foundtextures job once credentials confirmed saved

### Live Sites
| Site | Role | Version |
|---|---|---|
| foundtextures.ca | Multisite Hub | Alpha 0.7.9j (needs FTP update to 0.7.9P) |
| pixhellated.ca | Spoke | Alpha 0.7.9j (needs FTP update to 0.7.9P) |
| wateronthebrain.ca | Spoke | Alpha 0.7.9j (needs FTP update to 0.7.9P) |

Both spokes are ACTIVE and heartbeating correctly after key exchange fix.

## Skin Registry

All skins live in `skins/{skin-name}/` and must always remain tracked in git.
Directory names use hyphens only, never underscores.

| Directory | Display Name | Status | In Base Release |
|---|---|---|---|
| `50-shades-of-noah-grey` | 50 Shades of Noah Grey | stable | ✅ YES |
| `new-horizon` | New Horizon | stable | ✅ YES |
| `galleria` | Galleria | stable | skin gallery only |
| `rational-geo` | Rational Geo | stable | skin gallery only |
| `impact-printer` | Impact Printer | stable | skin gallery only |
| `true-grit` | True Grit | stable | skin gallery only |
| `hip-to-be-square` | Hip to be Square | beta | skin gallery only |
| `a-grey-reckoning` | A Grey Reckoning | development | no |
| `in-stereo-where-available` | In Stereo Where Available | development | no |
| `kiosk` | Kiosk | development | no |
| `52-card-pickup` | 52 Card Pickup | development | no |
| `photogram` | Photogram | development | no |
| `show-n-tell` | Show-n-Tell | development | no |
| `the-grid` | The Grid | development | no |

**Base release** includes only `50-shades-of-noah-grey` and `new-horizon`.
All other skins are distributed via the skin gallery in Smack Central.
To change which skins are in the base release, edit `$always_exclude` in
`smack-central/sc-release.php` — and update this table.

## Skin Status Values

- `stable` — Production ready
- `beta` — Functional but not fully tested
- `development` — Work in progress, not installable from gallery
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   