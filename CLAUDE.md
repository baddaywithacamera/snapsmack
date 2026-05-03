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

### SnapSmack — Alpha 0.7.36 "Perch"
✅ **0.7.36 committed locally. Pending push.**

**Git branch is `master` not `main`** (confirmed 2026-04-29).

**Changes this session (0.7.30–0.7.36):**

**0.7.36** — Tool API key authentication + SYBU 0.7.9e
- **`core/api-auth.php`** (NEW) — Dual auth: accepts `X-Snap-Key` header (tools) or session cookie (browser). Invalid key → 401 JSON. No key → falls through to normal session auth.
- **`smack-settings.php`** — API Access section added: generate/copy/regenerate/revoke 64-char hex tool API key stored as `tool_api_key` in `snap_settings`.
- **`migrations/046_tool_api_key.php`** (NEW) — Seeds `tool_api_key` setting.
- **`migrations/047_patch_htaccess.php`** (NEW) — Reads root `.htaccess`, checks for required named routes (`snap-in`), injects any missing immediately before catch-all slug rule. Atomic tmp+rename write. Idempotent. Fixes old installs that never got `snap-in` because `.htaccess` is in updater's protected paths.
- **`smack-audit.php`, `smack-backfill.php`, `sybu-data.php`, `smack-post-solo.php`** — `auth.php` → `api-auth.php`.
- **`tools/sybu/poster.py`** — `login()`, `is_session_alive()`, `relogin()` removed. `SnapSmackClient` now takes `api_key` arg, sends `X-Snap-Key` header on all requests. `keepalive()` is a no-op.
- **`tools/sybu/main.py`** — USERNAME/PASSWORD fields → API KEY field. Connect calls `client.verify()`. Session timer and keepalive timer removed.
- **SYBU bumped to 0.7.9e.**

**0.7.35** — Fix missing release-pubkey.php (folded into 0.7.36 push)
- **`core/release-pubkey.php`** (NEW) — Placeholder all-zeros Ed25519 pubkey. Prevents smack-update.php 500 on installs that updated via the in-admin updater.
- **`core/updater.php`** — `require_once` made defensive; falls back to zeros key if file missing.

**Changes this session (0.7.30–0.7.34, all pushed):**

**0.7.30** — `parseMosaics()` fatal fix + keyboard shortcut fix
- **`core/parser.php`** — `parseMosaics()` stub method confirmed present (live server had stale file — fixed via updater)
- **`skins/50-shades-of-noah-grey/manifest.php`** — `smack-keyboard` added to `require_scripts` so F1/1/2 shortcuts fire on photo and static page views (was archive-only)

**0.7.31** — FOUC fix: replace `time()` cache busters with version string
- **`core/meta.php`** — `?v=<?php echo time(); ?>` → `?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>` for skin CSS
- **`skins/*/skin-meta.php`** (multiple) — same `time()` → version string fix
- **`skins/*/skin-footer.php`** (12 files) — removed engine CSS `<link>` output; skins now output JS only
- **`core/meta.php`** — added engine CSS loading block in `<head>` (reads skin manifest + inventory, outputs `<link>` for each script that has a CSS file)

**0.7.32** — Image fade race condition fix
- **`assets/css/public-facing.css`** — added `opacity: 0; transition: opacity 0.4s ease-in-out` for `.post-image`, `.fsog-image`, `.pg-post-image`, `.tg-image`, `img[data-lightbox-src]` so images are hidden before JS loads

**0.7.33** — Updater modal UI fixes (CSS/JS class mismatches, admin theme bleed)
- **`assets/css/ss-engine-updater.css`** — `#snap-updater-modal button` override block (admin theme isolation); added `.su-footer-btns`, `.su-uptodate-icon`, single-dash `.su-btn-primary` / `.su-btn-secondary` classes that JS actually generates
- **`assets/js/ss-engine-updater.js`** — added `su-title` class to modal header span

**0.7.34** — Remove updater modal; add DISMISS to update banner
- **`core/admin-footer.php`** — removed `<div id="snap-updater-modal">` and `ss-engine-updater.js` script tag
- **`core/admin-header.php`** — removed `ss-engine-updater.css` link
- **`smack-admin.php`** — VIEW UPDATES → plain link to `smack-update.php`; DISMISS link added with `$_SESSION['update_notice_dismissed']` session suppression
- **`smack-update.php`** — removed `window._snapUpdaterAutoOpen = true` auto-open trigger

**Previous changes (0.7.26 — Cloudflare HTTPS + packager fixes):**
- **`core/constants.php`** — `snap_is_https()` helper added; checks `$_SERVER['HTTPS']`, `HTTP_X_FORWARDED_PROTO`, `HTTP_X_FORWARDED_SSL`
- **`core/auth.php`** — `secure` cookie flag uses `snap_is_https()` (was bare `$_SERVER['HTTPS']`)
- **`core/community-session.php`** — `$secure` uses `snap_is_https()`
- **`core/meta.php`** — `$protocol` uses `snap_is_https()`
- **`core/multisite-api.php`** — BASE_URL bootstrap inlined full three-condition HTTPS check (constants.php not loaded yet at this point)
- **`core/ohsnap-api.php`** — same as multisite-api.php
- **`.htaccess`** — HTTPS redirect now active with two-condition check (`HTTP:X-Forwarded-Proto` + `HTTPS`) — was commented out entirely
- **`install.php`** — HTTPS redirect in heredoc fixed; step '4b' added to normalization; DB-confirmed step split from admin-form step; `snap_is_https()` used for Site URL field
- **`setup.php`** — Restored from git history (was truncated 346→366 lines); file-by-file zip extraction added, skipping setup.php itself to avoid PHP overwriting-running-script issue
- **`smack-central/sc-release.php`** — Restored from git history (was truncated — missing `</script>` and `require sc-layout-bottom.php`; this was why changelog JS never fired); `$always_exclude` hardened: added `secaudits/`, `migrations/`, `database/`, `data/`, `backfill-checksums.php`, `backfill-thumbs.php`, `smack-central-current.zip`, `.well-known/`; galleria and rational-geo REMOVED from exclude list (now in base package); codename placeholder updated
- **`smack-central/sc-config.sample.php`** — GitHub PAT entry documented with instructions
- **`.gitignore`** — `sc-config.php` added (was accidentally committed with live credentials — rotated)
- **`CLAUDE.md`** — Skin registry updated: galleria + rational-geo now `✅ YES` for base release

**Previous changes (0.7.25 — installer + admin polish):**
- **`install.php`** — Step 1 split into two pages: env check (step 1) → edition chooser (step 1b) → DB config (step 2→3); `$total_steps` 4→5; dot mapping updated; equal-height edition cards (`align-items: stretch`)
- **`smack-central/sc-release.php`** — `parseChangelog` CRLF bug fixed; debug console.logs removed
- **`core/sidebar.php`** — "Mosaics" → "Mosaic" in nav label
- **`smack-2fa.php`** — Fixed wrong includes
- **`smack-fingerprints.php`** — Admin theme applied; `is_banned` column reference removed
- **`assets/css/admin-theme-geometry-master.css`** — `.tab-selector` geometry added
- **`smack-multisite.php`** — Completed truncated file
- **`index.php`** — Guarded empty `active_skin` DB value
- **`tools/oh-snap/src-tauri/capabilities/default.json`** — `shell:open` → `shell:allow-open`

**Latest changes (0.7.25 — Reapply loop fix):**
- Version bump: 0.7.24 → 0.7.25 "Lawn Chair"
- **Reapply Current Version loop fixed** — `stage_download` now falls back to session update data when no cached notification exists, so APPLY works after reapply.

**Previous changes (0.7.24 — Dashboard Apply Update fix + SMACKATTACK rename):**
- Version bump: 0.7.23 → 0.7.24 "Lawn Chair"
- **Dashboard "Apply Update" button fixed** — `cron-version-check.php` and `smack-admin.php` fallback check both omitted `download_url`, `checksum_sha256`, and `signature` from the cached `core_update` blob; clicking Apply Update always produced "NO DOWNLOAD URL" error. Both now store the full field set.
- **Smack the Enemy renamed to SMACKATTACK** — all user-facing labels, headings, help topics, settings section, and API messages updated. Internal code/files/DB tables unchanged (`sc-enemy-*`, `ste_*`).
- **Packager changelog fetch fixed** — PHP proxy in `sc-release.php` resolves tag to commit SHA before fetching, bypassing GitHub CDN caching.

**Previous changes (0.7.23 — Security audit 2 fixes):**
- Version bump: 0.7.22 → 0.7.23 "Couch Potato"
- **Email header injection fixed** in `core/contact-form.php` — CRLF stripped from $name and $email before mail() headers
- **Race condition fixed** in `smack-central/sc-enemy-api.php` — flock(LOCK_EX) on rate limit file
- **Weak randomness fixed** in `smack-central/sc-release.php` — bin2hex(random_bytes(16)) for temp filenames

**Previous changes (0.7.22 — Security hardening pass):**
- Version bump: 0.7.21 → 0.7.22 "Couch Potato"
- **Open redirect fixed** in `community-auth.php` — `community_safe_redirect()` helper enforces relative-paths-only
- **Logo/favicon upload hardened** in `smack-settings.php` — extension whitelist + `finfo` MIME validation on both logo and favicon uploads
- **Path traversal closed** in `smack-edit.php` — skin slug and `edit_page` manifest values now validated against `/^[a-z0-9][a-z0-9\-]*$/` before path construction
- **Session fixation fixed** in `login.php` — `session_regenerate_id(true)` now called before writing `totp_pending_user_id` on the 2FA path
- **Rate limiting added** to `password-reset.php` — max 5 admin reset requests per IP per hour via `snap_rate_limits` table
- **Slug validation hardened** in `smack-post-solo.php` and `smack-post-long.php` — consecutive hyphens collapsed, leading/trailing stripped, `untitled` fallback, user-supplied slugs normalised through `long_slugify()`
- **DB error message suppressed** in `install.php` — catch-all branch no longer leaks raw MySQL error
- **Security headers added site-wide** in `core/constants.php` — `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin` on every request

**Previous changes (0.7.21 — Security audit + Privacy Policy + SMACKBACK):**
- Version bump: 0.7.20 → 0.7.21 "Couch Potato"
- **Privacy Policy page** — `smack-privacy.php` (admin) + `privacy-policy.php` (public). Footer link, The Good Shit sidebar.
- **Security audit published** — `secaudits/` directory committed to git.
- **Head scripts moved from DB to filesystem** — `data/custom-head.html`, DB fallback for existing installs.
- **`setup.php` rewritten** — signed release installer, SHA-256 + Ed25519 verification, zip slip protection.
- **SMACKBACK, GOBSMACKED, Privacy Policy help topics** added to `smack-help.php`.

**Previous changes (0.7.19 — GOBSMACKED rename + snapsmack.ca TWIG N BERRIES!):**
- Version bump: 0.7.18 → 0.7.19 "Couch Potato"
- **GOBSMACKED** — renamed from "Smack Style" throughout all user-facing text. Internal code (DB tables, PHP functions, filenames) unchanged.
  - `smack-central/sc-enemy-admin.php` — subtitle, stat label, tab, and run button updated to GOBSMACKED
  - `smack-central/sc-enemy-api.php` — comment updated
  - `smack-central/schemas/sc-enemy-canonical.sql` — comment updated
  - `core/ste-style.php` — docblock updated
  - `_spec/smack-style.md` — title and content updated
- **snapsmack.ca** — TWIG N BERRIES! privacy policy page (`tnb.html`, was privacy.html):
  - Added to nav on all five pages (index, wotcha, bugger, oi, tnb)
  - Nav font-size reduced to 0.8rem site-wide to prevent overflow
  - All "Smack Style" references in tnb.html renamed to GOBSMACKED

**Latest changes (0.7.18 — GOBSMACKED / SMACK THE ENEMY Tier 3):**
- Version bump only — companion tool release (SYBU 0.7.9c) — previously noted
- **GOBSMACKED** — stylometric writing fingerprint system (Tasks #52–#58, all complete):
  - `core/ste-style.php` (NEW) — 25-dimension style vector extraction from comment text
  - `core/ban-check.php` — add_ban() now extracts + transmits style vector at ban time; `_ste_fetch_comment_texts()` added
  - `core/ste-client.php` — ste_client_report() accepts optional $style_vector param
  - `smack-central/sc-enemy-api.php` — report handler stores incoming style vectors into ste_style_vectors
  - `smack-central/sc-enemy-admin.php` — GOBSMACKED tab: run_analysis, cluster display, escalate/dismiss actions; skull emoji removed from heading and nav
  - `smack-central/sc-schema.php` — MySQL 5.7 fix: removed IF NOT EXISTS from ADD COLUMN DDL
  - `smack-central/schemas/sc-enemy-canonical.sql` — ste_style_vectors table added
  - `smack-central/sc-update.php` — sc-db.php added to $protected list (never overwritten by updater)
  - `smack-central/sc-layout-top.php` — skull emoji removed from nav

**Pending — live sites:**
- Push 0.7.36 to Github (commit is local only — run commit command from session)
- FTP `core/release-pubkey.php` to photowalk.ing (fixes smack-update.php 500 — then updater can pull 0.7.36)
- FTP `.htaccess` to photowalk.ing (fixes `/snap-in` 404 immediately — migration 047 will also patch it once 0.7.36 is applied via updater)
- Build 0.7.36 release package from Smack Central after push
- Update all live sites to 0.7.36 via Smack Central update system
- Generate API key in foundtextures.ca Admin → Settings → API Access, paste into SYBU Settings → API Key
- Rebuild SYBU exe (`build.bat` in `tools/sybu/`) after push for 0.7.9e
- FTP `smack-central/sc-release.php` + `setup.php` to snapsmack.ca if not done (truncation fix from 0.7.26 era)
- FTP skins to strathmore.pics: `skins/50-shades-of-noah-grey/`, `skins/new-horizon/`, `skins/galleria/`, `skins/rational-geo/` (fresh install has no skins)
- Complete strathmore.pics install: delete duplicate snap_user 'sean' then re-run step 5
- `projects/snapsmack-ca/` files still need manual FTP to snapsmack.ca server (untracked, not in release package)

**Pending DB on live squir871_enemy (use migration runner, not phpMyAdmin):**
- Apply remaining enemy schema: `coordination_cluster_id` column + `idx_cluster` index on `ste_reports`
- Create `ste_style_vectors` table (full DDL in `smack-central/schemas/sc-enemy-canonical.sql`)

**Pending — other:**
- Enable "Require Download Link" on foundtextures.ca Admin → Settings → Downloads
- Confirm sc-db.php on server has sc_enemy_db() and sc_forum_db() (was overwritten by updater — correct version now in repo)
- CSRF implementation (deferred HIGH severity — SameSite=Lax partial mitigation in place)

**Pending local git hygiene (run from C:\dev\snapsmack):**
```
# Remove the smack-post.php shim (updater handles cleanup on existing installs)
git rm smack-post.php

# Rename the duplicate 030 migration — shim already in place, real file is written
git rm migrations/030_semantic_analysis_tables.php
git add migrations/030_semantic_analysis_tables.php  # picks up the rewritten PDO version
# Then rename it cleanly:
git mv migrations/030_semantic_analysis_tables.php migrations/042_semantic_analysis_tables.php

# Move the impact-printer reference doc out of assets/css/ (new copy already in skins/)
git rm assets/css/impact-printer-image-borders-text-characters.txt
git add skins/impact-printer/image-borders-text-characters.txt

# Remove the underscore-named core shims (real files are layout-logic.php / navigation-bar.php)
git rm core/layout_logic.php core/navigation_bar.php
git add core/layout-logic.php core/navigation-bar.php

# Move SUYB setup guide to its tool directory
git mv suyb-google-drive-setup.docx tools/smack-up-your-backup/google-drive-setup.docx

# Untrack the large zips and error log that slipped into git
git rm --cached snapsmack.zip smack-central-current.zip error_log
```
After running these: update the $migration_name string inside `042_semantic_analysis_tables.php` from `'030_semantic_analysis_tables'` to `'042_semantic_analysis_tables'`.

### Smack Your Batch Up (SYBU) — v0.7.9e "API key authentication"
Tool lives in `tools/sybu/`. All commits on `main`. Push from local: `git push Github main`, then force-move the tag.

**To rebuild the exe:** run `build.bat` in `tools/sybu/`
Output: `C:\SmackYourBatchUp\smackyourbatchup-0.7.9c.exe`
Spec: `tools/sybu/smackyourbatchup-0.7.9c.spec`

**What's new in 0.7.9c:**
- ADV. MATCH tab — two-stage pHash + SIFT visual matching (ported from Fix Your Batch Up)
  - Pick server folder (local FTP copy) + originals folder → Run Matching
  - ProcessPoolExecutor, ≤4 workers, results stream in as MatchRow cards
  - Upload confirmed matches to Drive; write URL back via smack-backfill.php
  - Uses credentials already in Settings — no separate entry required
- `matcher.py` added to SYBU (pHash + SIFT engine)
- `poster.py` — `SnapSmackClient.backfill_update_link()` added
- Repair tab renamed to BASIC REPAIR & MATCH

**Pending:**
- Rebuild exe (run `build.bat` in `tools/sybu/`) — needs cv2, imagehash installed
- Run BASIC REPAIR → Rename Drive Files to {id}.jpg on foundtextures.ca (1,431 files)
- Run BASIC REPAIR → Re-enrich Duplicate Titles on foundtextures.ca (287 posts)
- Fix 3 posts missing Drive links (IDs 883, 1008, 1009) via BASIC REPAIR → Backfill

### Smack Up Your Backup (SUYB) — v0.7.3
All commits on `main`. Push from local: `git push Github main`, then force-move the tag.

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
| Site | Role | Version | Hosting |
|---|---|---|---|
| foundtextures.ca | Multisite Hub | Alpha 0.7.28 | self-hosted, Proxmox |
| snapsmack.ca | Promo + Smack Central | — | self-hosted, Proxmox (moved from shared cPanel) |
| pixhellated.ca | Spoke | needs update to 0.7.28 | shared hosting |
| wateronthebrain.ca | Spoke | needs update to 0.7.28 | self-hosted, Proxmox |
| hekeepsdroningon.ca | Spoke | needs update to 0.7.28 | self-hosted, Proxmox |
| photowalk.ing | Standalone | needs update to 0.7.28 | self-hosted, Proxmox |
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
| `hip-to-be-square` | Hip to be Square | withheld | skin gallery only |
| `a-grey-reckoning` | A Grey Reckoning | withheld | no |
| `in-stereo-where-available` | In Stereo Where Available | withheld | no |
| `kiosk` | Kiosk | withheld | no |
| `52-card-pickup` | 52 Card Pickup | available (in development) | no |
| `photogram` | Photogram | withheld | ✅ YES (mobile skin — always ships) |
| `show-n-tell` | Show-n-Tell | withheld | no |
| `the-grid` | The Grid | withheld | no |

**Base release** includes `50-shades-of-noah-grey`, `new-horizon`, `galleria`, and `rational-geo`.
All other skins are distributed via the skin gallery in Smack Central.
To change which skins are in the base release, edit `$always_exclude` in
`smack-central/sc-release.php` — and update this table.

## Skin Status Values

- `available` — Installable from skin gallery (production ready)
- `withheld` — Not shown in skin gallery (in development or not yet ready)
- `available (in development)` — Installable but actively being worked on
