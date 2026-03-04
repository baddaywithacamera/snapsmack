# SNAPSMACK — Backup, Recovery, Export & FTP System
## Design Document v1.0 — March 2026

> **Purpose**: This document is the roadmap for building the complete backup/recovery/export
> system. If an AI session loses context mid-build, read this file first to understand
> what's been decided, what's built, and what remains.

---

## 1. PROBLEM STATEMENT

SnapSmack's current backup system exports SQL dumps and source code, but deliberately
**excludes** the small files that hurt worst when lost:

- Branding assets (`assets/img/` — logo, favicon)
- Media library files (`media_assets/`)
- Active skin directory (custom skins like Rational Geo, HTBS, Picasa)
- Any uploaded page images (`snap_pages.image_asset`)

The database knows the *paths* to all of these, and the recovery engine can *relocate*
them — but there's no mechanism to get them off the server before disaster strikes.

Additionally, users who outgrow SnapSmack have no clean migration path to WordPress
or other platforms. Data portability is an ethical obligation, not a feature.

---

## 2. WHAT WE'RE BUILDING

### Tier 1: Recovery Kit (downloadable archive)
A single `.tar.gz` that contains **everything needed to rebuild the site from scratch**.
User clicks a button, downloads the file, stashes it wherever they want (Google Drive,
OneDrive, USB stick, NAS). Zero cloud integration needed.

### Tier 2: OAuth Cloud Push (session-only, no stored credentials)
User clicks "Push to Google Drive" or "Push to OneDrive", authenticates in browser,
file uploads, access token dies with the PHP session. **NOT building Tier 3** (stored
refresh tokens / automated cron push). Tier 2 is a separate future task and is NOT
covered in this build.

### Data Liberation Exports
- **WordPress WXR** — standard WordPress eXtended RSS import format
- **Portable JSON** — documented schema for any platform

### FTP Backup Client
Built-in FTP push for image directories. User configures host/credentials, points to
a remote directory. Covers the "I want my photos somewhere else" use case without
cloud API complexity. PHP's native `ftp_*` functions work on virtually all shared hosts.

---

## 3. ARCHITECTURE

### 3.1 File Structure (new and modified files)

```
core/
  export-engine.php          NEW — All export logic (recovery kit, WXR, JSON)
  recovery-engine.php        MODIFY — Add importRecoveryKit() method
  ftp-engine.php             NEW — FTP connection, directory walk, push logic

smack-backup.php             MODIFY — New UI sections, calls export-engine
smack-ftp.php                NEW — FTP configuration and manual push UI

migrations/
  migrate-0.7-0.8.sql        MODIFY — Add FTP settings to defaults

docs/
  DESIGN-backup-recovery-export.md   THIS FILE
```

### 3.2 Recovery Kit Archive Format

```
snapsmack_recovery_YYYY-MM-DD_HH-MM/
├── manifest.json              # Metadata + complete file inventory
├── database.sql               # Full SQL dump (all 11 tables)
├── branding/                  # Contents of assets/img/
│   ├── logo.png
│   └── favicon.ico
├── media/                     # Contents of media_assets/
│   └── [uploaded files]
└── skin/                      # Active skin directory only
    └── [skin-slug]/
        ├── manifest.php
        ├── style.css
        ├── layout.php
        └── [all other skin files]
```

**manifest.json schema:**
```json
{
  "snapsmack_version": "0.8.0-alpha",
  "export_date": "2026-03-04T15:30:00-07:00",
  "export_type": "recovery-kit",
  "site_name": "My Photography Site",
  "site_url": "https://example.com/",
  "active_skin": "rational-geo",
  "active_variant": "light",
  "php_version": "8.1.2",
  "files": {
    "database.sql": { "size": 45230, "checksum": "sha256:abc..." },
    "branding/logo.png": { "size": 12400, "checksum": "sha256:def...", "restores_to": "assets/img/logo.png" },
    "media/photo.jpg": { "size": 8800, "checksum": "sha256:ghi...", "restores_to": "media_assets/photo.jpg" },
    "skin/rational-geo/style.css": { "size": 52000, "checksum": "sha256:jkl...", "restores_to": "skins/rational-geo/style.css" }
  },
  "stats": {
    "total_images": 25,
    "total_comments": 17,
    "total_pages": 2,
    "branding_files": 2,
    "media_files": 5,
    "skin_files": 11
  }
}
```

The `restores_to` field is the key innovation — the manifest tells the recovery engine
exactly where every file goes. No guessing.

### 3.3 Recovery Kit Import (Eat Our Own Dog Food)

The recovery engine's new `importRecoveryKit()` method:

1. Receives path to extracted `.tar.gz` directory (or extracts it)
2. Reads `manifest.json` — validates format version and checksums
3. Calls existing `importSqlDump()` with `database.sql`
4. Iterates `manifest.files` — for each entry with `restores_to`:
   - Creates target directory if needed
   - Copies file to `restores_to` path
   - Verifies checksum after copy
   - Streams progress via existing `streamProgress()` method
5. Calls existing `ensureDirectories()` to fill any gaps
6. Calls existing `regenerateAndChecksum()` for any images missing thumbnails
7. Returns summary: {imported_sql, restored_files, checksum_failures, missing}

**Critical**: The import must handle recovery kits from older versions gracefully.
If `manifest.json` is missing a field, use sensible defaults. Never fail hard on
a missing optional field.

### 3.4 WordPress WXR Export

Standard WXR 1.2 XML format. Mapping:

| SnapSmack | WordPress | Notes |
|-----------|-----------|-------|
| `snap_images` | `<item>` post_type="post" + attachment | Each image becomes a post with the image as featured attachment |
| `snap_images.img_title` | `<title>` | Direct |
| `snap_images.img_slug` | `<wp:post_name>` | Direct |
| `snap_images.img_description` | `<content:encoded>` | Wrapped in CDATA |
| `snap_images.img_date` | `<wp:post_date>` | Format: Y-m-d H:i:s |
| `snap_images.img_status` | `<wp:status>` | published→publish, draft→draft |
| `snap_images.img_file` | `<wp:attachment_url>` | Full URL (site_url + path) |
| `snap_categories` | `<wp:category>` | cat_slug → nicename, cat_name → cat_name |
| `snap_albums` | `<wp:tag>` | Albums map to tags (WP has no albums) |
| `snap_comments` | `<wp:comment>` | comment_author, comment_email, comment_text, comment_date |
| `snap_pages` | `<item>` post_type="page" | slug, title, content |
| `snap_images.img_exif` | `<wp:postmeta>` | Each EXIF field as a custom field |

Image URLs in the WXR must be absolute (BASE_URL + img_file) so the WordPress
importer can fetch them. The source site needs to stay up during migration, or
images need to be accessible at those URLs.

### 3.5 Portable JSON Export

Clean JSON with documented schema. Not tied to any platform.

```json
{
  "export_format": "snapsmack-portable",
  "format_version": "1.0",
  "exported": "2026-03-04T15:30:00-07:00",
  "site": {
    "name": "My Site",
    "url": "https://example.com/",
    "tagline": "A photography blog"
  },
  "images": [
    {
      "id": 1,
      "title": "Sunset",
      "slug": "sunset",
      "description": "A beautiful sunset",
      "date": "2026-01-15T18:30:00",
      "status": "published",
      "file_url": "https://example.com/img_uploads/2026/01/sunset.jpg",
      "file_path": "img_uploads/2026/01/sunset.jpg",
      "width": 2500,
      "height": 1667,
      "exif": { "Model": "Canon EOS R5", "FNumber": "f/2.8", ... },
      "categories": ["landscapes", "golden-hour"],
      "albums": ["best-of-2026"],
      "comments": [
        {
          "author": "Jane",
          "email": "jane@example.com",
          "text": "Beautiful shot!",
          "date": "2026-01-16T09:00:00",
          "approved": true
        }
      ]
    }
  ],
  "categories": [
    { "id": 1, "name": "Landscapes", "slug": "landscapes", "description": "" }
  ],
  "albums": [
    { "id": 1, "name": "Best of 2026", "description": "" }
  ],
  "pages": [
    { "id": 1, "slug": "about", "title": "About", "content": "...", "active": true }
  ],
  "blogroll": [
    { "name": "Friend's Blog", "url": "https://friend.com", "description": "" }
  ]
}
```

### 3.6 FTP Backup Client

**Settings stored in `snap_settings`:**

| Key | Description | Example |
|-----|-------------|---------|
| `ftp_host` | FTP server hostname | `ftp.mybackup.com` |
| `ftp_port` | Port (default 21) | `21` |
| `ftp_user` | Username | `backupuser` |
| `ftp_pass` | Password (encrypted) | `[encrypted]` |
| `ftp_remote_dir` | Remote directory path | `/snapsmack-backups/` |
| `ftp_use_ssl` | Use FTPS (boolean) | `1` |
| `ftp_passive` | Use passive mode (boolean) | `1` |
| `ftp_last_push` | Timestamp of last successful push | `2026-03-04 15:30:00` |
| `ftp_last_status` | Result of last push | `success` or error message |

**Encryption**: FTP password encrypted with `openssl_encrypt()` using AES-256-CBC.
Key derived from `download_salt` setting. The password is only decrypted in memory
during the FTP push operation.

**What gets pushed**: The user chooses what to push via checkboxes:
- Recovery Kit (the .tar.gz) — always available
- Image directories only (`img_uploads/`) — for people who just want photos backed up
- Full site (recovery kit + all image directories) — the nuclear option

**Push operation**: Walks the selected directories, compares local files against
remote directory listing (by filename + size), uploads only new/changed files.
Progress streamed via `streamProgress()` pattern (same as recovery engine).

**UI page** (`smack-ftp.php`):
- Configuration form (host, port, user, pass, remote dir, SSL, passive)
- Test Connection button (AJAX — tries to connect and list remote dir)
- Push Now buttons for each scope (recovery kit / images / full)
- Last push status display
- Estimated transfer size before pushing

**PHP requirements**: `ftp_*` functions (ext-ftp). Available on most shared hosts
including HostPapa. For FTPS, `ftp_ssl_connect()` requires OpenSSL. We detect
availability at runtime and show/hide the SSL option accordingly.

---

## 4. BUILD ORDER

Each chunk is independently committable. If context is lost between chunks,
the next session should read this doc, check git log, and continue from
wherever the previous session stopped.

### Chunk 1: Export Engine + Recovery Kit Export
**File**: `core/export-engine.php`
**Methods**:
- `exportRecoveryKit()` — builds tar.gz with manifest.json
- `generateManifest()` — builds the manifest.json content
- `generateSqlDump()` — reusable SQL dump (extracted from smack-backup.php)

### Chunk 2: Recovery Kit Import
**File**: `core/recovery-engine.php` (modify)
**Methods**:
- `importRecoveryKit(string $archivePath)` — full round-trip import
- `validateManifest(array $manifest)` — sanity checks

### Chunk 3: Data Liberation Exports
**File**: `core/export-engine.php` (add methods)
**Methods**:
- `exportWordPressWXR()` — generates WXR 1.2 XML
- `exportPortableJSON()` — generates documented JSON

### Chunk 4: FTP Engine
**File**: `core/ftp-engine.php`
**Class**: `SnapSmackFTP`
**Methods**:
- `__construct(array $config)` — takes host/port/user/pass/ssl/passive
- `connect()` — establishes connection, returns success/error
- `testConnection()` — connect + list remote dir + disconnect
- `pushFile(string $localPath, string $remotePath)` — single file upload
- `pushDirectory(string $localDir, string $remoteDir)` — recursive with delta
- `pushRecoveryKit(string $archivePath)` — push a .tar.gz
- `getRemoteListing(string $dir)` — for delta comparison
- `disconnect()` — cleanup
- `encryptPassword(string $plain, string $salt)` — static, AES-256-CBC
- `decryptPassword(string $cipher, string $salt)` — static

### Chunk 5: FTP Configuration UI
**File**: `smack-ftp.php`
- Settings form (save to snap_settings)
- Test Connection (AJAX endpoint)
- Push Now buttons with progress streaming
- Status display

### Chunk 6: Backup Page Overhaul
**File**: `smack-backup.php` (rewrite)
- Reorganize into sections:
  1. **BACKUP & RECOVERY** — Recovery Kit download, Recovery Kit import (file upload)
  2. **DATABASE** — Full dump, Schema, Keys (existing)
  3. **DATA LIBERATION** — WordPress WXR, Portable JSON
  4. **MAINTENANCE** — Verify Integrity, Source Archive (existing)
  5. **FTP** — Link to smack-ftp.php config, or quick-push button
- Import UI: file upload form that accepts .tar.gz, streams progress

### Chunk 7: Migration & Wiring
- Update `migrate-0.7-0.8.sql` with FTP default settings
- Update sidebar nav if needed (add FTP link)
- Update `smack-help.php` with backup/recovery documentation
- Final round-trip test: export → delete → import → verify

---

## 5. DECISIONS LOG

| Decision | Rationale |
|----------|-----------|
| Single .tar.gz for recovery kit | One file to grab, one file to upload. No multi-step restore. |
| manifest.json with `restores_to` | Recovery engine doesn't guess file locations. Round-trip guaranteed. |
| SHA-256 checksums in manifest | Detect corruption during transfer. Verify integrity post-restore. |
| Active skin only (not all skins) | Keeps archive small. Stock skins ship with the source code. |
| FTP over SFTP | PHP's ftp_* is universally available. SFTP needs ssh2 extension (rare on shared hosts). |
| FTP password encrypted with AES-256-CBC | Better than plaintext. Key from download_salt. Not bulletproof but reasonable for shared hosting. |
| Albums → WP tags (not categories) | WP categories are hierarchical, tags are flat. Albums are flat. Better semantic match. |
| No Tier 3 (automated cloud push) | Complexity vs value. Manual push with session-only OAuth covers the need. |
| Portable JSON includes full URLs | Allows any platform to fetch images. Site must stay up during migration. |
| Source backup still excludes media | That's what the Recovery Kit is for. Source archive stays lean for code-only recovery. |

---

## 6. CURRENT STATUS

**As of commit `9c15c37` (March 4, 2026):**

- [x] Design document written (this file)
- [ ] Chunk 1: Export Engine + Recovery Kit Export
- [ ] Chunk 2: Recovery Kit Import
- [ ] Chunk 3: Data Liberation Exports (WXR + JSON)
- [ ] Chunk 4: FTP Engine
- [ ] Chunk 5: FTP Configuration UI
- [ ] Chunk 6: Backup Page Overhaul
- [ ] Chunk 7: Migration & Wiring

Update this section as chunks are completed.
