<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  (Authoritative byte sequence: tools/check-eof.py EOF_MARKERS.)
  Missing or different = truncated/corrupted. Restore before saving.
-->


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


## CRITICAL — EOF Markers, Header Tags, and Pre-Commit Scan

**Every tracked source file (PHP/JS/CSS/HTML/HTM/MD/SQL/PY/SH) carries two truncation sentinels: a `SNAPSMACK_EOF_HEADER` block near the top, and a long-form `SNAPSMACK EOF` marker on the last non-empty line.** Either missing = file is truncated/corrupted and must be restored before saving.

### Bottom marker (last non-empty line)

| Extension | Marker |
|---|---|
| `.php` (logic — ends in PHP block) | `// ===== SNAPSMACK EOF =====` |
| `.php` (template — ends after `?>`) | `<?php // ===== SNAPSMACK EOF =====` |
| `.js` | `// ===== SNAPSMACK EOF =====` |
| `.css` | `/* ===== SNAPSMACK EOF ===== */` |
| `.html`, `.htm`, `.md` | `<!-- ===== SNAPSMACK EOF ===== -->` |
| `.sql` | `-- ===== SNAPSMACK EOF =====` |
| `.py`, `.sh` | `# ===== SNAPSMACK EOF =====` |

PHP mode: if the file ends with HTML output (after `?>`), use the `<?php // …` form; if it ends inside a PHP block (`}`, `;`, a comment), use the plain `// …` form. Do not change a file's mode (logic vs HTML) without updating both the bottom marker and the header.

### Top header (within first 8KB)

Every file also carries a `SNAPSMACK_EOF_HEADER` block near the top. The header names (or, where the comment syntax can't safely embed it, structurally describes) the expected bottom marker. Purpose: a stressed-context Claude reading any one file knows what to look for at the bottom — no recall of external rules required, no guessing.

Placement (set by `tools/migrate-eof-marker.py`):
- `.php`: after the existing `/** SNAPSMACK - … */` docblock
- `.js`: after the existing top docblock
- `.css`: after `@charset` or first `/* … */` block
- `.html`/`.htm`: after `<!DOCTYPE>`
- `.md`/`.sql`: at top
- `.py`: after shebang + module docstring
- `.sh`: after shebang

**Why long form (vs the legacy `// EOF`):**
- Greppability: `SNAPSMACK EOF` is collision-free; `EOF` alone matches shell heredocs, error strings, and incidental comments.
- Anti-forgery: a partial-write that happens to leave `// EOF` in the file would silently pass a short-form check; the long form is hard to forge accidentally.
- Visibility: visually obvious when tail-ing a file.

The marker must be the very last non-empty line (no trailing blank line after it counts). If it's missing, the file is truncated.

### Pre-commit scan

**Before every commit, scan ALL tracked files — not just files staged in the current session.** Prior-session truncations have shipped to git undetected multiple times (`updater.php`, `smack-help.php`, `install.php`). Session-scoped scans don't catch those.

`tools/check-eof.py` checks every tracked file in scope for:
1. Long-form bottom marker on the last non-empty line.
2. `SNAPSMACK_EOF_HEADER` tag within the first 8KB.
3. Null bytes (`\x00`) anywhere in the file.
4. Structural `\r\n` corruption (literal backslash-r backslash-n outside string/comment contexts).

Excluded paths (third-party / build artifacts): `smack-central/`, `licenses/`, `vendor/`, `node_modules/`, `*.min.{js,css}`, `assets/js/fjGallery*`. Authoritative list: `EXCLUDED_PATTERNS` in `tools/check-eof.py`.

**Run before every commit:**
```bash
python3 tools/check-eof.py
```

Any file that fails must be fixed before the commit proceeds. Do not declare a commit ready without running this scan.

### Migration tool (one-shot, retained for reference)

`tools/migrate-eof-marker.py` is the script that converted the repo from the legacy `// EOF` short-form to the current long-form + header convention. Default is dry-run; `--apply` writes. Useful again only if the convention is ever extended (new file types, new sentinels). Don't run on already-migrated files — the skip rule makes that a no-op anyway.

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

## Claude Code Task Handoff

A `.claude-tasks/` folder (gitignored) at the repo root contains work orders for Claude Code.
Cowork sessions reason and spec; Claude Code executes git-touching work.

**Convention:** Each file is `NNN-description.md` with Status, Context, numbered Steps, and
a ready-to-paste commit message. Do task 000 first (it fixes the git index and commits
the Cowork session changes). Tasks are independent after that unless noted.

**Current tasks:**
- `000-commit-cowork-session.md` — fix git index, commit 5 Cowork fixes, READY
- `001-nav-menu-wire-and-upgrade.md` — wire nav menu (migration/sidebar/header/CSS) + 3-level upgrade, READY (replaces old 001)
- `002-search-placeholder-setting.md` — configurable search field label, READY
- `003-wall-all-skins.md` — enable wall in remaining skins (kiosk + true-grit still pending), READY

**Git index note:** The index is corrupt again (bad signature 0x00000000). Fix before any git op:
```bash
cd /c/dev/snapsmack
rm .git/index
git read-tree HEAD
```

---

## Current Work State (as of session end)

**CRITICAL — Update this section at the end of every session.** If this section is stale, the next session starts with wrong assumptions. At minimum: current version number, what just shipped, what's pending FTP, and any version bumps to companion tools.

### SnapSmack — Alpha 0.7.45 "Chaise"
✅ **0.7.45 committed and ready to push.**

**What 0.7.43 shipped (Claude Code):**
- `smack-settings.php` — API key UI fixed
- `smack-appearance-archive.php` — dead TILE BORDER & SHADOW box removed
- `skins/50-shades-of-noah-grey/archive-layout.php` — toggle data-driven
- `skins/rational-geo/archive-layout.php` — same toggle fix + wrong settings key corrected
- `skins/50-shades-of-noah-grey/manifest.php` — archive_frame_style moved to ARCHIVE section
- `smack-menu.php`, `ss-engine-menu-builder.js`, `ss-engine-nav-dropdown.js` — restored from git history
- `migrations/050_search_placeholder.php` — search placeholder setting
- `supports_wall: true` flipped in rational-geo, photogram, impact-printer manifests
- **NOT done in 0.7.43:** nav menu wiring (migration 049, sidebar link, header.php renderer, skin CSS) — deferred by Code

**Uncommitted Cowork session changes (2026-05-05):**
- `migrations/049_nav_menu_json.php` — seeds nav_menu_json + dropdown appearance settings
- `core/sidebar.php` — Menu Manager link added to Pimp Your Ride
- `core/footer.php` — loads ss-engine-nav-dropdown.js when nav_menu_json is active
- `core/meta.php` — injects --nav-dropdown-bg/text CSS vars when nav configured
- `core/header.php` — JSON nav renderer (3-level recursive) with flat nav fallback + _snap_nav_resolve_url()
- All 8 skins `style.css` — .nav-has-children / .nav-submenu dropdown CSS added
- `smack-menu.php` — container type, album/category/collection pool, 3-level hint, new CSS
- `assets/js/ss-engine-menu-builder.js` — full rewrite: 3-level drag-and-drop, container type, active toggle, album/cat/coll pool items
- `assets/js/ss-engine-nav-dropdown.js` — fixed openMenu() to not close ancestor submenus (3-level mobile fix)
- `smack-settings.php` — removed blogroll_enabled nav toggle + entire NAVIGATION SLOT ASSIGNMENTS box
- `smack-appearance-archive.php` — relabelled show_wall_link as "ENABLE FLOATING GALLERY" (feature toggle, not nav control)
- `CLAUDE.md` — work state updated

**Git branch is `master` not `main`** (confirmed 2026-04-29).

**Signing keypair status:**
- Release private key: in `sc-config.php` on snapsmack.ca server ✅
- Release public key: `b9955f78c835f964b980a104d231be8253fce59db89cb12367c9ccbbafcea297` — in `core/release-pubkey.php` ✅
- Root public key: `3287b9b29257da6a307fc85b949c9dc52bc99c08a66db21e6fcbaab0fb324652` — hardcoded in `core/updater.php` ✅
- Root private key + full instructions: in Bitwarden (`KEY-ROTATION-INSTRUCTIONS.txt` on disk, gitignored) ✅

**snapsmack.ca server notes:**
- Snapsmack files now on SATA bulk storage via bind mount: host `/mnt/bulk-storage/snapsmack-ca` → CT101 `/var/www/snapsmack.ca`
- `sc-assets/` directory created at `/var/www/snapsmack.ca/sc-assets/` ✅
- Cloudflare is in front of snapsmack.ca — purge cache after FTPing CSS files

**Changes shipped in 0.7.42:**
- Key rotation infrastructure (root key + release key two-tier system)
- Smack Central CSS: font 13px→15px, sidebar 210→230px, max-width 1400px, padding 32px 48px
- Smack Central CSS: added missing classes (`sc-page-head`, `sc-card`, `sc-card-title`, `sc-btn--dim`, `sc-warn`, `sc-muted`, `sc-help-*`, `sc-step-log`)
- `core/release-pubkey.php` — real public key replacing all-zeros placeholder
- `core/updater.php` — fixed literal `\r\n` corruption that caused 500 on photowalk.ing after update
- `admin-theme-geometry-master.css` — IP Smacker tab permanently blank fixed (CSS/JS class name mismatch)
- `archive.php` + `smack-appearance-archive.php` — Cal layout button missing fixed; croppedwithcalendar added to whitelist in both files; archive layout state now persists correctly via localStorage
- `smack-help.php` — restored from truncation, updated with new topics (Archive Calendar, Probe Guard, API Keys, Key Rotation)
- `install.php` — r4_exec recovery tail restored from truncation
- EOF markers added to all 454 PHP/JS/CSS files; `tools/check-eof.py` pre-commit scanner added

**Pending — after 0.7.43:**
- Build 0.7.43 release package from Smack Central → Release Packager
- Build skin packages for 50-shades-of-noah-grey v1.1 and rational-geo v1.1 via Skin Packager
- Update all live sites to 0.7.43 via Smack Central updater
- FTP `.htaccess` (with Probe Guard routes) to each server manually — gitignored, server-specific
- Generate API key in foundtextures.ca Admin → Settings → API Access, paste into SYBU
- Rebuild SYBU exe (`build.bat` in `tools/sybu/`)
- strathmore.pics: delete duplicate snap_user 'sean', re-run install step 5
- FTP skins to strathmore.pics: `50-shades-of-noah-grey`, `new-horizon`, `galleria`, `rational-geo`
- FTP `projects/snapsmack-ca/` files to snapsmack.ca (untracked, manual FTP)
- wall config move to Global Vibe (low priority, still in Archive Appearance)
- wall in kiosk + true-grit (task 003 covers 5 skins; Code only did 3 — check which remain)
- calendar months slider in Archive Appearance (deferred, not specced yet)

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
| pixhellated.ca | Spoke | needs update to 0.7.42 | shared hosting |
| wateronthebrain.ca | Spoke | needs update to 0.7.42 | self-hosted, Proxmox |
| hekeepsdroningon.ca | Spoke | needs update to 0.7.42 | self-hosted, Proxmox |
| photowalk.ing | Standalone | needs update to 0.7.42 | self-hosted, Proxmox |
| strathmore.pics | Standalone | fresh install in progress | self-hosted, Proxmox (Cloudflare Tunnel) |

Updater confirmed: modal working on foundtextures.ca at 0.7.28. All self-hosted sites on Proxmox in Sean's basement.

**NOTE: All core PHP files ship in the release package via the updater. The only file requiring manual FTP per-server is `.htaccess` (gitignored, server-specific). Do not list in-package files as pending FTP.**

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
<!-- ===== SNAPSMACK EOF ===== -->
