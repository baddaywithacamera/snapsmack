# SnapSmack Architecture Conventions

## Version & Headers

Version is defined once in `core/constants.php` (`SNAPSMACK_VERSION`, `SNAPSMACK_VERSION_SHORT`, `SNAPSMACK_VERSION_CODENAME`) and `smack-central/sc-version.php`. Git handles per-file versioning — **do not put version numbers in doc-block headers**.

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
index corruption (bad signature 0x00000000). The repo has been moved to a local
path to fix this. If index corruption ever recurs, the fix is:

```bash
rm .git/index
git read-tree HEAD
git add <files>
git commit
```

## Current Work State (as of session end)

### SnapSmack — Alpha 0.7.9k
All commits are on `master`, tagged `v0.7.9k`. Push from local:
```
git push Github master
git push Github v0.7.9k --force
```

**Pending on live servers (FTP these files):**
- `smack-central/sc-release.php` → snapsmack.ca (release exclusions updated)
- Full 0.7.9k release to all live sites via Smack Central after above

**0.7.9k fixes:**
- Multisite hub sub-pages (Signals, Posts, Backup Dock, Stats, Cross-Post, Blogroll)
  all redirected to dashboard — `$settings` not loaded before hub guard. Fixed in all 6 files.
- Hub spoke post count wrong — was counting `snap_posts`, fixed to `snap_images`.
- Last seen time always stale — MySQL/PHP timezone mismatch, fixed with `UNIX_TIMESTAMP()`.
- Registration token COPY button was tiny orphaned grey button, fixed to `btn-smack`.
- All 14 skins committed to git for the first time. Skin registry documented above.
- Release package exclusions: only `50-shades-of-noah-grey` + `new-horizon` in base release.
  Skin screenshots, SA key files, docs/, screenshots/, media_assets/ all excluded.

### Smack Up Your Backup — v0.2.3
All commits on `master`. Push from local: `git push Github master`

**To rebuild the exe:** run `build.bat` in `tools/smack-up-your-backup/`

**Status:**
- Cloud backup to Google Drive working — SA key configured at pixhellated.ca
- OAuth authenticate button added (Settings → Global Cloud Config)
- Crash recovery checkpoints working
- Scheduled backup tab working (Schedule tab)
- Single instance enforcement added
- Session log files written to `{backup_dir}/logs/`
- File dialogs use PowerShell subprocess (bypasses tkinter parent issues on Windows)
- Profiles and config persist next to the exe in `C:\SmackUpYourBackup\`

**Pending cloud upload issue:**
- pixhellated backup completes locally but cloud upload status unclear
- SA key: `C:\SmackUpYourBackup\suyb-drive-key-5e7a5909f75e.json`
- Drive folder ID: `12UFKgvSNtM9uKCjtttyhFPvD45wjXVv9`
- Drive folder shared as Editor with: `smack-up-your-backup@snapsmack-backups.iam.gserviceaccount.com`
- Check `C:\Staging\logs\` after next backup run for exact cloud error

### Live Sites
| Site | Role | Version |
|---|---|---|
| foundtextures.ca | Multisite Hub | Alpha 0.7.9j (needs 0.7.9k FTP update) |
| pixhellated.ca | Spoke | Alpha 0.7.9j (needs 0.7.9k FTP update) |
| wateronthebrain.ca | Spoke | Alpha 0.7.9j (needs 0.7.9k FTP update) |

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
