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

### Canonical snapsmack.ca header/footer values (DO NOT change these when editing nav links)

- **Nav HOME link text:** `GAFF!` (all pages, both mini-header and main header navs)
- **Logo tagline:** `PHOTO <em>BLOGGING</em> IS BACK, BITCHEZ` (all pages — matches index.html exactly)
- **Footer:** `&copy; 2026 Sean McCormick · Dedicated to Raymond A. Vanderwoning, photographer and friend. <a href="https://www.serenity.ca/obituaries/Raymond-Anthony-Vanderwoning?obId=30943370" target="_blank" rel="noopener noreferrer">He is missed.</a>` — centered, no HOME link, no CONTACT link in footer on non-index pages

## Version & Headers

**Versioning scheme (as of 0.7.17):** Standard three-part numeric semver — `0.7.17`, `0.7.18`, etc. The old letter-suffix format (`0.7.9P`) is retired; `snap_version_compare()` in `core/constants.php` still handles legacy strings from older installs.

Milestone map: `0.7.x` = Alpha · `0.8.x` = Closed Beta · `0.9.x` = Open Beta · `1.0` = Stable.

Codename convention by milestone:
- `0.7.x` — sitting-related codenames (Hot Seat, Bench Warmer, …)

Version is defined once in `core/constants.php` (`SNAPSMACK_VERSION`, `SNAPSMACK_VERSION_SHORT`, `SNAPSMACK_VERSION_CODENAME`) and `smack-central/sc-version.php`. Git handles per-file versioning — **do not put version numbers in doc-block headers**.

**SYBU versioning:** `BUILD_VERSION` in `tools/sybu/main.py` uses the same `0.7.9x` letter-suffix scheme, incrementing independently per SYBU release. It is NOT SnapSmack's version. When bumping SYBU: increment the letter (`0.7.9b` → `0.7.9c`), add a CHANGELOG entry in `tools/sybu/CHANGELOG.md`, create a new `.spec` file, and update `BUILD_VERSION` in `main.py` to match.

**Smack Up Your Backup versioning:** SUYB uses `0.7.x` where x is a meaningful release count within the SnapSmack milestone era (not a letter suffix, not one-for-one with SnapSmack). When SnapSmack hits 0.8.x, SUYB resets to `0.8.1`. When bumping SUYB: increment x, add a CHANGELOG entry in `tools/smack-up-your-backup/CHANGELOG.md`, create a new `.spec` file, and update `BUILD_VERSION` in `main.py` to match.

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

## CRITICAL — VM Shell Must Never Write to Project Files

**Never use shell commands that write to files in the workspace.** This means:

- **No `sed -i`**, `awk`, `perl -i`, or any other in-place shell edit command
- **No shell redirects** (`>`, `>>`) targeting project files
- **No `cp`, `mv`, `rm`** on project files from the VM bash shell
- **No git commands from VM bash** — the VM shares `.git` with Windows git; VM git ops corrupt index/lock state

**Why:** The VM mounts the workspace over a FUSE/network path. `sed -i` writes a temp file and renames it over the original — on this mount that rename can truncate the file. The original is destroyed. This has happened multiple times and caused 500 errors on live sites.

**The Write tool pads files with null bytes when new content is shorter than the old file, and truncates when new content is longer.** The Edit tool is reliable for surgical in-place changes. For full file creation or replacement, use a Python shell write:

```python
python3 -c "
path = '/sessions/.../mnt/snapsmack/file.php'
content = '''...'''
with open(path, 'w') as f:
    f.write(content)
"
```

**After every file write, check for truncation and null bytes:**

```bash
python3 -c "d=open('path','rb').read(); print(len(d), 'bytes,', d.count(b'\x00'), 'nulls'); print(repr(d[-60:]))"
```

Zero nulls and a clean EOF are required before committing. This has caused repeated 500 errors on live sites when missed.

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
- Entry format: `## 0.7.18 — "Codename" (YYYY-MM-DD)` followed by `### Added`, `### Fixed`, etc. with `- bullet` items.
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
- **`install.php` is self-maintaining** — it reads `snapsmack_canonical.sql` directly for the schema, and auto-scans `migrations/[0-9][0-9][0-9]_*.php` to stamp all migrations as applied. No manual updates to install.php needed when adding tables or migrations.

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

## Runtime-Generated Directories (Not From Package)

These directories appear on live servers but are NOT in the release package and should never be questioned or deleted:

- `data/sessions/` — created by `core/auth.php` line 34 on first authenticated page load. Stores PHP session files so shared hosting cron jobs can't purge them from `/tmp`. A deny-all `.htaccess` is written inside it automatically — session files are not web-accessible. Permissions: `0700`.
- `data/` parent is created as a side effect of the above `mkdir(..., true)` call.

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

### SnapSmack — Alpha 0.7.40 "Warm Bench"
✅ **0.7.40 ready to commit and push (not yet committed).**

**Git branch is `master` not `main`** (confirmed 2026-04-29).

**Signing keypair status:**
- Release private key: in `sc-config.php` on snapsmack.ca server (needs FTP with new key)
- Release public key: `4df51e2c4610a9a34913f7a52a29f8964dc2aec8448abcfe899cc4e2cf45068a` — in `core/release-pubkey.php` (committed in 0.7.40)
- Root public key: `d4c4256853fc046160f0f0028f3b48548eac50defdbd0803ef545d36d100eae5` — hardcoded in `core/updater.php`
- Root private key + full instructions: in Bitwarden (`KEY-ROTATION-INSTRUCTIONS.txt` on disk, gitignored)

**Changes this session (0.7.40):**

**Photogram + Kiosk black image fix**
- **`skins/photogram/manifest.php`** — Added `smack-image-fade-load` to `require_scripts`.
- **`skins/kiosk/manifest.php`** — Same fix.

**Archive calendar (SMACKONEOUT / SMACKTALK skins only)**
- **`assets/js/ss-engine-calendar.js`** — Complete rewrite. Activates only when `archive-layout-croppedwithcalendar` is on body. Two-click date range → `?from=DATE&to=DATE&layout=croppedwithcalendar`, same cell twice = single day, ESC cancels.
- **`assets/css/ss-engine-calendar.css`** — Reworked with CSS variable cascade.
- **`archive.php`** — Added `croppedwithcalendar` layout, `$from_filter`/`$to_filter` date range params.
- **`api-calendar.php`** — `min(3, ...)` → `min(12, ...)` months param.
- **`migrations/048_calendar_layout.php`** (NEW) — Appends `croppedwithcalendar` to `archive_layouts_available`.
- **`skins/50-shades-of-noah-grey/manifest.php`** — Added `smack-calendar`, `croppedwithcalendar`, version 1.0 → 1.1.
- **`skins/rational-geo/manifest.php`** — Same, version 1.0 → 1.1.
- **`skins/true-grit/manifest.php`** — `archive_layout_default` set to `masonry`.

**Updater: pre-migrate stage**
- **`smack-update.php`** — New `stage_premigrate` between backup and extract. Also: `accept_key_rotation` action, rotation detection in verify failure path, enhanced repair panel (shows KEY ROTATION DETECTED with pre-filled key when root-key-signed rotation file is available; falls back to manual paste otherwise).
- **`core/updater.php`** — Added `updater_extract_migrations_only()`, `updater_fetch_key_rotation()`, `SNAPSMACK_ROOT_PUBKEY` constant, `UPDATER_KEY_ROTATION_URL` constants.

**Signing key infrastructure**
- **`core/release-pubkey.php`** — Real public key replacing all-zeros placeholder.
- **`core/updater.php`** — Root pubkey hardcoded; `updater_fetch_key_rotation()` fetches and verifies rotation announcements against root key.
- **`smack-update.php`** — Auto-detects rotation on sig failure; shows amber KEY ROTATION DETECTED panel with ACCEPT button; falls back to manual paste if no rotation file on server.
- **`smack-central/sc-release.php`** — `signing_pubkey` added to `latest.json` manifest; new Key Rotation panel (generate blob → sign offline → paste sig → publish).
- **`smack-central/KEY-ROTATION-INSTRUCTIONS.txt`** — Gitignored. Full rotation procedure + both keypairs. Store in Bitwarden.

**Smack Central: CSS / layout**
- **`smack-central/assets/css/sc-geometry.css`** — Base font 13px→15px, label 0.7→0.8rem, dim 0.75→0.85rem, sidebar 210→230px.
- **`smack-central/assets/css/sc-admin.css`** — `.sc-main` max-width removed (was 1100px).

**Smack Central: Skin Packager delete button**
- **`smack-central/sc-skins.php`** — POST handler for `delete_skin`, delete button per row.

**Other**
- **`.gitignore`** — Added `*.bak`, `.htaccess`, `smack-central/KEY-ROTATION-INSTRUCTIONS.txt`; deduplicated `projects/snapsmack-ca/`.
- **`smack-cats.php`, `smack-albums.php`, `smack-collections.php`** — Featured image picker queries `snap_images` directly.

**Commit command (run from C:\dev\snapsmack in MINGW bash):**
```bash
cd /c/dev/snapsmack
git add core/constants.php smack-central/sc-version.php CHANGELOG.md .gitignore
git add assets/js/ss-engine-calendar.js assets/css/ss-engine-calendar.css
git add archive.php api-calendar.php
git add skins/50-shades-of-noah-grey/manifest.php skins/rational-geo/manifest.php
git add skins/photogram/manifest.php skins/kiosk/manifest.php
git add smack-update.php core/updater.php migrations/048_calendar_layout.php
git add smack-central/sc-skins.php skins/true-grit/manifest.php
git add smack-cats.php smack-albums.php smack-collections.php
git add core/release-pubkey.php
git add smack-central/sc-release.php
git add smack-central/assets/css/sc-geometry.css smack-central/assets/css/sc-admin.css
git rm --cached .htaccess
git commit -m "0.7.40 — archive calendar, date range filter, pre-migrate updater stage, key rotation infrastructure, SC font/layout fix"
git tag -f v0.7.40
git push Github master
git push Github v0.7.40 --force
```

**Pending — FTP to photowalk.ing:**
- `.htaccess` (probe guard + snap-in route)
- `probe-ban.php`
- `smack-cats.php`, `smack-albums.php`, `smack-collections.php`
- `skins/photogram/manifest.php` ships with 0.7.40 update package — update via updater once 0.7.40 is pushed

**Pending — after push:**
- Rebuild 0.7.40 release package from Smack Central (keypair is now wired up — packages will be properly signed)
- Build skin packages for 50-shades-of-noah-grey v1.1 and rational-geo v1.1 via Skin Packager
- Update all live sites to 0.7.40 via Smack Central update system
- Generate API key in foundtextures.ca Admin → Settings → API Access, paste into SYBU
- Rebuild SYBU exe (`build.bat` in `tools/sybu/`)
- strathmore.pics install: delete duplicate snap_user 'sean', re-run step 5
- FTP skins to strathmore.pics: `50-shades-of-noah-grey`, `new-horizon`, `galleria`, `rational-geo`
- FTP `projects/snapsmack-ca/` files to snapsmack.ca (untracked, manual FTP)

**Pending — other:**
- CSRF implementation (deferred HIGH severity — SameSite=Lax partial mitigation in place)
- Calendar for Photogram/GRAM OF SMACK — deferred, not designed yet
- Enable "Require Download Link" on foundtextures.ca Admin → Settings → Downloads
- Apply remaining enemy schema migrations (coordination_cluster_id, ste_style_vectors) via migration runner

**Pending local git hygiene:**
```bash
git rm smack-post.php
git mv migrations/030_semantic_analysis_tables.php migrations/042_semantic_analysis_tables.php
git rm assets/css/impact-printer-image-borders-text-characters.txt
git add skins/impact-printer/image-borders-text-characters.txt
git rm core/layout_logic.php core/navigation_bar.php
git add core/layout-logic.php core/navigation-bar.php
git mv suyb-google-drive-setup.docx tools/smack-up-your-backup/google-drive-setup.docx
git rm --cached snapsmack.zip smack-central-current.zip error_log
```
After running these: update `$migration_name` in `042_semantic_analysis_tables.php` from `'030_semantic_analysis_tables'` to `'042_semantic_analysis_tables'`.

### Smack Your Batch Up (SYBU) — v0.7.9e "API key authentication"
Tool lives in `tools/sybu/`. Pending: rebuild exe (`build.bat`), push, force-move tag.

### Smack Up Your Backup (SUYB) — v0.7.3
Pending: rebuild exe, test B2 credentials, run Audit & Cleanup on foundtextures job.

### Live Sites
| Site | Role | Version | Hosting |
|---|---|---|---|
| foundtextures.ca | Multisite Hub | Alpha 0.7.28 | self-hosted, Proxmox |
| snapsmack.ca | Promo + Smack Central | — | self-hosted, Proxmox |
| pixhellated.ca | Spoke | needs update to 0.7.40 | shared hosting |
| wateronthebrain.ca | Spoke | needs update to 0.7.40 | self-hosted, Proxmox |
| hekeepsdroningon.ca | Spoke | needs update to 0.7.40 | self-hosted, Proxmox |
| photowalk.ing | Standalone | needs update to 0.7.40 | self-hosted, Proxmox |
| strathmore.pics | Standalone | fresh install in progress | self-hosted, Proxmox (Cloudflare Tunnel) |

Updater confirmed: modal working on foundtextures.ca at 0.7.28. All self-hosted sites on Proxmox in Sean's basement.

## Skin Registry

All skins live in `skins/{skin-name}/` and must always remain tracked in git.
Directory names use hyphens only, never underscores.

| Directory | Display Name | Status | In Base Release |
|---|---|---|---|
| `50-shades-of-noah-grey` | 50 Shades of Noah Grey | available | ✅ YES |
| `new-horizon` | New Horizon | available | ✅ YES |
| `galleria` | Galleria | available | ✅ YES |
| `rational-geo` | Rational Geo | available | ✅ YES |
| `impact-printer` | Impact Printer | available | skin gallery only |
| `true-grit` | True Grit | available | skin gallery only |
| `photogram` | Photogram | available | ✅ YES |
| `kiosk` | Kiosk | available | skin gallery only |
