<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be: <!-- ===== SNAPSMACK EOF ===== -->
-->

# SMACKBACK — File Integrity Monitoring Spec

**Version:** 0.7.170  
**Status:** Approved for build  
**Date:** 2026-05-22  
**Codename:** TBD (suggest: Wingback)

---

## 1. What SMACKBACK Does

SMACKBACK is automated sentinel software that ships in every SnapSmack install. It generates cryptographic SHA-256 hashes of all monitored source files at install/update time, re-verifies them on defined triggers, and responds to confirmed tampering with graduated alerts up to full admin lockout.

This delivers on the Layer 6 claim on snapsmack.ca exactly as written. No asterisks.

---

## 2. Threat Model

**Primary threat: FTP or hosting panel credential compromise.**  
Attacker obtains FTP/cPanel credentials, uploads a modified PHP file — typically a backdoored `index.php`, a webshell in a core include, or a credential stealer patched into `core/db.php`. They want persistence and they want it quiet. They do not typically restore mtimes. They drop the file and walk away.

**Secondary threat: Shared hosting lateral movement.**  
A compromised neighbour site on the same server reads or writes files in the webroot due to misconfigured permissions. Same result: modified files on disk.

**Out of scope for SMACKBACK:**  
Supply chain attacks are already mitigated by Ed25519 signature verification on release packages. Brute force admin login is already handled by existing security layers. Database compromise (SQL injection, stolen credentials) does not produce file modification without a separate exploit chain and is out of scope here.

---

## 3. Why Full Scanning Is Viable

SnapSmack's base package is approximately 1.1MB of PHP/JS/CSS. Full SHA-256 verification across the entire install is bounded in milliseconds, not seconds. Memory pressure is negligible. There is no performance argument for reduced scope on any tier of shared hosting.

This is a genuine competitive advantage over WordPress integrity plugins (Wordfence et al.), which are heavy precisely because WordPress is heavy. SnapSmack scanning its own 1.1MB is a categorically different scale of problem.

**Consequence:** No tiered performance tiers. Full scan everywhere. User-selectable settings govern **response**, not scope.

---

## 4. Files to Monitor

### Included
- All `.php` files in the webroot and all subdirectories
- All `.css` files (`assets/css/`, `skins/**/`)
- All `.js` files (`assets/js/`) — excluding third-party (see below)
- All skin files — PHP templates and CSS. Skins contain no JS. CSS customisation via Oh Snap! writes to the database, not to disk files. Skin PHP templates execute on every page load and are prime backdoor real estate.

### Excluded
- `uploads/` — user content, changes constantly
- `smack-central/` — build staging, not a deployed webroot concern
- `reference/` — reference copies, not in release
- `*.min.js`, `*.min.css` — third-party minified assets
- `assets/js/fjGallery*` — third-party Flickr Justified Gallery library
- `node_modules/`, `vendor/` — third-party dependencies
- `.htaccess` — legitimately modified by hosting configurations
- `*.sql`, `*.zip`, `*.json`, `*.pdf` — non-executable data files
- Session files, log files
- `tools/` — development utilities, not deployed to production sites
- `CHANGELOG.md`, `README.md`, `CLAUDE.md` — documentation, not executable

### Skin handling
Skin files are included in the monitored set. The Skin Packager must refresh hash manifest entries for a skin when it installs or updates that skin (same pattern as the core updater refreshing core entries). See §8 for integration details.

---

## 5. Baseline Hash Source

**Option chosen: Build-time manifest (Option B).**

Per-file SHA-256 hashes are generated at release build time by `tools/_build/build-release.php` and written into a `smackback-manifest.json` file inside the release ZIP. This manifest is covered by the package-level Ed25519 signature — the expected hashes are cryptographically guaranteed before they reach any install.

On install/update, the updater reads `smackback-manifest.json` from the verified package and UPSERTs all entries into `snap_file_manifest`.

**Fresh install fallback:** `install.php` cannot read from the ZIP post-extraction (the ZIP may not be retained). After extraction completes, `smackback_init_from_disk()` hashes all monitored files directly. Files on disk at that point came from a package that passed signature verification milliseconds earlier — clean baseline.

**Skin packages:** `tools/_build/build-skin-package.php` and `tools/_build/package-skin.php` are updated identically — per-file hashes + `smackback-manifest.json` inside each skin ZIP.

### smackback-manifest.json format
```json
{
    "smackback_version": 1,
    "package_version": "0.7.170",
    "generated_at": "2026-05-22T21:00:00Z",
    "files": {
        "index.php":            { "hash": "abc123...", "size": 4821 },
        "core/auth-smack.php":  { "hash": "def456...", "size": 9344 },
        "core/db.php":          { "hash": "ghi789...", "size": 2156 }
    }
}
```

Paths are relative to the webroot. Forward slashes on all platforms.

---

## 6. Database Schema

### New table: `snap_file_manifest`

```sql
CREATE TABLE IF NOT EXISTS snap_file_manifest (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_path     VARCHAR(500)  NOT NULL,
    expected_hash CHAR(64)      NOT NULL,
    file_size     INT UNSIGNED  NOT NULL,
    expected_mtime INT UNSIGNED DEFAULT NULL,
    skin_id       VARCHAR(64)   DEFAULT NULL,
    baseline_set  DATETIME      NOT NULL,
    last_verified DATETIME      DEFAULT NULL,
    last_status   ENUM('ok','tampered','missing','pending')
                  NOT NULL DEFAULT 'pending',
    UNIQUE KEY uq_path (file_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`expected_mtime` is populated on first successful verification (not from build time, which reflects the build machine's clock). After the first full verify, mtime is stored per file and used for the fast pageload stat check.

`skin_id` is NULL for core files, set to the skin slug for skin files. Used when a skin update needs to refresh only that skin's entries.

### New `snap_settings` entries

| Key | Default | Notes |
|-----|---------|-------|
| `smackback_enabled` | `'0'` | Master switch |
| `smackback_mode` | `'lockout'` | `'alert'`, `'lockout'`, `'paranoid'` |
| `smackback_status` | `'clean'` | `'clean'` or `'breach'` |
| `smackback_breach_files` | `''` | JSON array of tampered paths |
| `smackback_breach_at` | `''` | Datetime string of detection |
| `smackback_breach_resolved_at` | `''` | Datetime string of resolution |
| `smackback_last_full_verify` | `''` | Datetime of last full hash pass |
| `smackback_alert_email` | `''` | Defaults to site admin email if empty |
| `smackback_pageload_check` | `'0'` | Enable stat() check on public page loads |

These are inserted by the migration with INSERT IGNORE so existing installs aren't disrupted.

---

## 7. Core Module: `core/smackback.php`

### Function reference

```php
/**
 * Read smackback-manifest.json from a verified release or skin ZIP.
 * UPSERTs all entries into snap_file_manifest.
 * Called by core/updater.php after successful update.
 * Called by skin-registry.php after skin install/update.
 *
 * $skin_id: null for core release, skin slug for skin packages.
 */
function smackback_init_manifest(string $zip_path, ?string $skin_id = null): bool

/**
 * Hash all currently monitored files on disk.
 * Used on fresh install where no ZIP is retained.
 * Called by install.php after file extraction.
 */
function smackback_init_from_disk(): bool

/**
 * Full verification pass: hash every file in snap_file_manifest.
 * Compares against expected_hash. Updates last_verified, last_status.
 * Stores mtime if expected_mtime is not yet set.
 * Returns structured result array.
 */
function smackback_verify_all(): array
// Returns:
// [
//   'status'   => 'clean' | 'breach',
//   'tampered' => ['path/to/file.php', ...],
//   'missing'  => ['path/to/missing.php', ...],
//   'ok'       => 142,
//   'checked'  => 145,
//   'duration' => 0.34,  // seconds
// ]

/**
 * Fast check: stat() all monitored files, compare mtime to expected_mtime.
 * If any mtime differs, triggers smackback_verify_file() on changed files only.
 * Used on public page loads when pageload_check is enabled.
 * Very cheap: no file reads, just filesystem metadata.
 */
function smackback_verify_quick(): bool

/**
 * Hash a single file and compare to its expected_hash.
 * Used internally by verify_quick() on mtime-changed files.
 */
function smackback_verify_file(string $relative_path): string
// Returns: 'ok' | 'tampered' | 'missing'

/**
 * Record a breach: update settings, fire email.
 * If mode === 'paranoid' AND multisite hub configured:
 *   POST breach report to hub (Phase 2 — stub only in this build).
 */
function smackback_handle_breach(array $tampered, array $missing): void

/**
 * Fetch a single file from the update server (using existing updater HTTP client).
 * Extract from current release ZIP. Verify hash before writing.
 * Write to disk. Re-verify. Clear breach flag for that file if clean.
 * Returns true on success, false with reason on failure.
 */
function smackback_restore_file(string $relative_path): array
// Returns: ['ok' => bool, 'message' => string]

/**
 * Restore all currently breached files in one pass.
 */
function smackback_restore_all_breached(): array

/**
 * Clear breach state. Log resolution.
 * Called after all tampered files are resolved.
 */
function smackback_resolve_breach(): void

/**
 * Quick breach check for admin page gatekeeping.
 * Reads only from snap_settings — no disk access.
 */
function smackback_is_breach(): bool

/**
 * Return list of all absolute file paths that should be monitored.
 * Applies inclusion rules (PHP/CSS/JS) and exclusion rules (uploads, minified, etc.).
 */
function smackback_get_monitored_files(): array

/**
 * Determine if a given absolute path should be monitored.
 */
function smackback_should_monitor(string $abs_path): bool

/**
 * Render the BREACH lockout page and exit.
 * All CSS is inline — no file reads. Cannot be neutralised by file tampering.
 * Called from smack-admin.php when is_breach() is true.
 */
function smackback_render_breach(array $settings): void

/**
 * Send tamper alert email.
 * Uses existing SnapSmack email infrastructure.
 */
function smackback_send_alert(array $tampered, array $missing): void

/**
 * Add or refresh hash manifest entries for a specific skin's files.
 * Called by skin-registry.php after skin install or update.
 */
function smackback_init_skin_manifest(string $zip_path, string $skin_id): bool

/**
 * Remove all manifest entries for a skin.
 * Called by skin-registry.php on skin uninstall.
 */
function smackback_remove_skin_manifest(string $skin_id): void
```

---

## 8. Integration Points

### 8.1 Build pipeline — `tools/_build/build-release.php`
After assembling the ZIP contents, before signing:
- Iterate all files to be included in the ZIP
- Apply `smackback_should_monitor()` rules (replicated in PHP, not the runtime function)
- Generate SHA-256 hash for each monitored file
- Write `smackback-manifest.json` to the ZIP root
- The manifest is then covered by the Ed25519 signature pass

### 8.2 Build pipeline — `tools/_build/build-skin-package.php` + `tools/_build/package-skin.php`
Same pattern: generate per-skin-file hashes, write `smackback-manifest.json` into the skin ZIP.

### 8.3 Updater — `core/updater.php`
After successful file extraction, before returning success:
```php
smackback_init_manifest($zip_path);
```
The ZIP path is already available at this point in the update flow.

Also: add `migrate-smackback.sql` to `UPDATER_KNOWN_MIGRATIONS`.

### 8.4 Installer — `install.php`
After all files are extracted and the DB is set up:
```php
require_once 'core/smackback.php';
smackback_init_from_disk();
```

### 8.5 Skin registry — `core/skin-registry.php`
After skin package extraction (install and update):
```php
smackback_init_skin_manifest($zip_path, $skin_id);
```
On skin uninstall:
```php
smackback_remove_skin_manifest($skin_id);
```

### 8.6 Admin login — `core/auth-smack.php`
After successful credential verification, before redirecting to admin:
```php
if (($settings['smackback_enabled'] ?? '0') === '1') {
    require_once 'core/smackback.php';
    $result = smackback_verify_all();
    if ($result['status'] === 'breach') {
        smackback_handle_breach($result['tampered'], $result['missing']);
    } else {
        update_setting('smackback_last_full_verify', date('Y-m-d H:i:s'));
    }
}
```

### 8.7 Cron — `cron-version-check.php`
Add SMACKBACK verification to the existing cron routine:
```php
if (($settings['smackback_enabled'] ?? '0') === '1') {
    require_once 'core/smackback.php';
    $result = smackback_verify_all();
    if ($result['status'] === 'breach') {
        smackback_handle_breach($result['tampered'], $result['missing']);
    } else {
        update_setting('smackback_last_full_verify', date('Y-m-d H:i:s'));
    }
}
```

### 8.8 Public page loads — index.php, archive.php, page.php, gallery-wall.php, blogroll.php, collection.php, collections.php, albums.php
Add immediately after the maintenance gate include:
```php
if (($settings['smackback_enabled'] ?? '0') === '1'
    && ($settings['smackback_pageload_check'] ?? '0') === '1') {
    require_once 'core/smackback.php';
    if (!smackback_verify_quick()) {
        // verify_quick() calls handle_breach() internally on detection
        // public page continues rendering — SMACKBACK doesn't interrupt public visitors
    }
}
```
**Important:** On public pages, SMACKBACK detects and logs tamper silently. It does NOT interrupt the visitor's experience. The admin-facing BREACH response happens on the next admin login. Public-facing breach lockout is not in scope — that would allow an attacker to use SMACKBACK as a DoS vector.

### 8.9 Admin pages — `smack-admin.php`
At the top, after auth check:
```php
if (($settings['smackback_enabled'] ?? '0') === '1'
    && ($settings['smackback_mode'] ?? 'lockout') !== 'alert') {
    require_once 'core/smackback.php';
    if (smackback_is_breach()) {
        smackback_render_breach($settings);
        exit;
    }
}
```
In `alert` mode: breach is noted in the admin header as a prominent banner but admin access is not blocked.

---

## 9. BREACH Response

### Response modes

**Alert mode**  
Tamper detected → email fires → prominent warning banner appears in admin header on every page → admin can still use the site → banner cannot be dismissed (only resolving the tamper clears it).

**Lockout mode** (default)  
Tamper detected → email fires → all admin pages redirect to `smack-smackback.php` → admin cannot post, configure, or navigate anywhere else until tampered files are resolved or mode is explicitly changed to Alert. This is what the marketing copy describes.

**Paranoid mode**  
Same as Lockout + stub for hub breach reporting (Phase 2 hook, fires a no-op in this build, wired for the Phase 2 multisite correlation feature).

### BREACH skin

The BREACH lockout page is rendered by `smackback_render_breach()` in `core/smackback.php`. It is entirely self-contained — **all CSS is hardcoded inline in PHP**. No skin files, no asset files, no external includes are read. An attacker who has tampered with skin or CSS files cannot neutralise the warning.

Visual design:
- `body`: near-black background (`#0a0000`), red primary text (`#cc2200`)
- Header: large, unmissable `⚠ SMACKBACK ALERT` in amber (`#ff9900`)
- Sub-header: site name + detection timestamp
- File list: each tampered/missing file on its own row with status indicator
- Action section: restore buttons per file + "Restore All" button
- Resolution section: explains what happened and what to do
- Cannot be dismissed. Cannot be navigated away from (all admin pages redirect here).

### Email alert

**Subject:** `[SMACKBACK] File tampering detected on {site_name}`

**Body:**
```
SMACKBACK has detected unauthorised file modifications on {site_name}.

Detected: {timestamp}

Affected files:
  TAMPERED: core/db.php
  TAMPERED: index.php
  MISSING:  core/auth-smack.php

Your admin interface is now in lockout mode. Log in to begin recovery:
{admin_url}/smack-smackback.php

What to do:
1. Log in to your SnapSmack admin
2. Review the affected files
3. Restore them from the update server or run a full update
4. Investigate how the files were modified (check FTP logs, hosting panel access logs)

If you did not expect any file changes and cannot explain this alert,
treat this as a security incident.

-- SMACKBACK / {site_name}
```

Uses existing SnapSmack email infrastructure (same path as comment notifications).

---

## 10. Admin Page: `smack-smackback.php`

### Sections

**Status panel**
- Current status: CLEAN / BREACH (prominent, colour-coded)
- Last full verification: timestamp + how many files checked + duration
- Files monitored: count (core + per-skin breakdown)
- Next scheduled check: estimated from last cron run

**BREACH detail** (visible only when status = breach)
- Detection timestamp
- Affected files list with status (TAMPERED / MISSING) per file
- For each file: "Restore from Update Server" button
- "Restore All Tampered Files" button
- "Run Full Update Instead" link → goes to smack-update.php

**Manual verification**
- "Run Full Verification Now" button
- Outputs streaming results (same pattern as smack-maintenance.php streaming output)
- Shows file count, duration, any issues found

**Incident log**
- Table: detected_at, resolved_at, files affected, resolution method
- Last 20 incidents

**Settings**
- Enable SMACKBACK (toggle)
- Response mode: Alert / Lockout / Paranoid (radio)
- Enable pageload stat check (toggle + explanation of what it does)
- Alert email (text input, placeholder = site admin email)
- "Re-initialise Baseline" button — re-hashes all files from disk (use after legitimate manual edits, with warning that this should be rare)

---

## 11. Restore Flow

When an admin clicks "Restore from Update Server" for a tampered file:

1. System fetches the current release package from `snapsmack.ca/releases/snapsmack-{version}.zip` using `_updater_http_get()` — same HTTP client used by the updater, with same timeout and retry logic.
2. Opens the ZIP, extracts the specific file into a temporary buffer (not written to disk yet).
3. Computes SHA-256 of the buffer.
4. Compares against `expected_hash` in `snap_file_manifest`.
5. **If hashes match:** writes buffer to disk, updates `last_status = 'ok'`, updates `expected_mtime` to current mtime.
6. **If hashes do not match:** aborts, shows error — "Downloaded file does not match expected hash. The update server may have a different version. Try running a full update instead."
7. After all breached files are restored (or if none remain), calls `smackback_resolve_breach()`.

`smackback_resolve_breach()`:
- Sets `smackback_status = 'clean'`
- Sets `smackback_breach_resolved_at` to now
- Logs the incident (breach_at, resolved_at, files affected, method = 'restore')
- Clears `smackback_breach_files`

The incident log persists. Resolving a breach does not erase history.

---

## 12. Migration

### New file: `migrations/migrate-smackback.sql`

```sql
-- SMACKBACK file integrity monitoring table
-- 0.7.170

CREATE TABLE IF NOT EXISTS snap_file_manifest (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_path     VARCHAR(500)  NOT NULL,
    expected_hash CHAR(64)      NOT NULL,
    file_size     INT UNSIGNED  NOT NULL,
    expected_mtime INT UNSIGNED DEFAULT NULL,
    skin_id       VARCHAR(64)   DEFAULT NULL,
    baseline_set  DATETIME      NOT NULL,
    last_verified DATETIME      DEFAULT NULL,
    last_status   ENUM('ok','tampered','missing','pending')
                  NOT NULL DEFAULT 'pending',
    UNIQUE KEY uq_path (file_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO snap_settings (setting_key, setting_value) VALUES
    ('smackback_enabled',          '0'),
    ('smackback_mode',             'lockout'),
    ('smackback_status',           'clean'),
    ('smackback_breach_files',     ''),
    ('smackback_breach_at',        ''),
    ('smackback_breach_resolved_at', ''),
    ('smackback_last_full_verify', ''),
    ('smackback_alert_email',      ''),
    ('smackback_pageload_check',   '0');
```

Migration ID to add to `UPDATER_KNOWN_MIGRATIONS` in `core/updater.php`:  
`'migrate-smackback.sql'`

Also add `snap_file_manifest` table to `database/schema/snapsmack_canonical.sql`.

---

## 13. Files Created / Modified

### Created
| File | Purpose |
|------|---------|
| `core/smackback.php` | Core module — all SMACKBACK functions |
| `smack-smackback.php` | Admin page — status, management, settings |
| `migrations/migrate-smackback.sql` | DB migration |

### Modified
| File | Change |
|------|--------|
| `tools/_build/build-release.php` | Generate per-file hashes + write smackback-manifest.json |
| `tools/_build/build-skin-package.php` | Same for skin packages |
| `tools/_build/package-skin.php` | Same for skin packages |
| `core/updater.php` | Call smackback_init_manifest() post-update; add migration to known list |
| `core/skin-registry.php` | Call smackback_init/remove_skin_manifest() on install/uninstall/update |
| `install.php` | Call smackback_init_from_disk() post-extraction |
| `core/auth-smack.php` | Call smackback_verify_all() on admin login |
| `cron-version-check.php` | Add smackback_verify_all() to cron routine |
| `smack-admin.php` | Breach redirect gate at top |
| `smack-settings.php` | SMACKBACK section (moved from smack-smackback.php for consistency) |
| `smack-help.php` | SMACKBACK help topic |
| `database/schema/snapsmack_canonical.sql` | Add snap_file_manifest table |
| `CHANGELOG.md` | 0.7.170 entry |
| `core/constants.php` | Bump to 0.7.170 |
| Public pages ×8 | Add smackback_verify_quick() call after maintenance gate |

---

## 14. Testing Plan

Deploy to a single spoke first. Hub integration is Phase 2.

### Test sequence on spoke

1. **Enable SMACKBACK** in smack-smackback.php settings. Mode: Lockout. Pageload check: on.
2. **Run manual verification** → confirm all files show OK, count looks right, no false positives.
3. **Tamper test — modify a core file:**  
   Via FTP, open `index.php`, add a comment or a space to any line, save.
4. **Trigger via page load** (if pageload check enabled) → verify quick-check catches the mtime change → verify full hash fires → breach recorded.
5. **Log in to admin** → confirm BREACH skin renders, email received, tampered file listed.
6. **Restore from update server** → confirm file restored, hash re-verified, breach cleared.
7. **Tamper test — delete a file:**  
   Via FTP, delete `core/constants.php`.
8. **Trigger via admin login** → confirm missing file detected, BREACH fires.
9. **Restore missing file** → confirm restore succeeds.
10. **Test alert mode:**  
    Switch to Alert mode. Tamper a file. Confirm admin access not blocked, banner visible.
11. **Re-initialise baseline** button → confirm re-hashes from disk, clears pending status.

### False positive checks
- Oh Snap! CSS override push → confirm no false positive (DB write, not file write)
- Skin install via Skin Packager → confirm smackback manifest refreshes, no false positive
- SnapSmack update → confirm manifest refreshes correctly after update

---

## 15. Phase 2: Hub/Spoke Breach Correlation

Not in this build. Stubbed only (paranoid mode fires a no-op hook).

**Design intent for Phase 2:**
- Spoke detects breach → POST to `multisite/smackback/report` on hub with: spoke URL, tampered file list, detection timestamp
- Hub stores reports in a new `snap_smackback_reports` table
- Hub runs correlation: same file path tampered on 2+ spokes within a configurable window (default 1 hour) → coordinated attack flag
- Hub sends escalation email to hub admin listing all affected spokes and files
- Hub admin dashboard (smack-multisite.php) shows per-spoke breach status
- Hub can optionally notify all spoke admins simultaneously

This is a distinct build. The spoke-side hook point is already wired.

---

## 16. What This Delivers

When built, the Layer 6 description on snapsmack.ca is accurate as written:

> *SMACKBACK is automated sentinel software that ships in every install and runs without configuration. SNAPSMACK watches its own files. PHP, JavaScript, and CSS are hashed at install time and re-verified on a schedule — and on every admin login and skin load. A modified file is caught fast. Confirmed tamper is unmissable: the admin interface switches to a high-contrast BREACH skin that cannot be dismissed until the incident is resolved. An email alert fires.*

The hub/spoke cross-correlation paragraph remains Phase 2. Consider adding a "coming in a future release" note to that paragraph until Phase 2 ships.

---

<!-- ===== SNAPSMACK EOF ===== -->
