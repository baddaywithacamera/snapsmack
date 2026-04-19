# Smack Up Your Backup — Changelog

---

## 0.2.6 — 2026-04-18

### Added
- **Cloud Sync tab** — new tab for cloud-to-cloud file sync (Google Drive → OneDrive). Create named sync jobs, run differential syncs (files already on OneDrive with matching size are skipped), and monitor progress with the same real-time stats and log UI as the Backup tab.
- **`sync_manager.py`** — CRUD for sync job configs stored in `sync_jobs/` next to profiles.
- **`cloud_sync_engine.py`** — background sync engine: lists Drive source, diffs against OneDrive destination, downloads to temp, uploads, deletes temp. Failure threshold prompt (Abort/Continue) mirrors backup behaviour.
- **Google Drive readonly scope** — `DriveClient` now accepts `readonly=True` which uses `drive.readonly` scope and a separate token cache (`*_readonly_token.json`), leaving the existing backup upload token untouched.
- **OneDrive interactive auth** — `OneDriveClient` now uses `acquire_token_interactive()` (opens system browser) instead of device flow. `authenticate_onedrive()` and `get_onedrive_token_status()` helpers added. OneDrive credentials JSON format: `{"client_id": "your-azure-app-client-id"}`.
- **OneDrive folder path support** — destination folder now specified by name (e.g. `"FoundTexturesBackup"`) rather than item ID. Both `list_files` and `upload_file` route via Graph API path syntax (`root:/FolderName:/children`).

---

## 0.2.5 — 2026-04-18

### Fixed
- **Download failures now surface immediately** — each failed FTP download logs the exact FTP error in real time rather than silently accumulating until the end of the run.
- **Backup stops on first unrecoverable failure** — after one failed download (already retried once by FTP client), a dialog pauses the backup with explicit **Abort Backup** / **Continue Anyway** buttons. No more ambiguous Yes/No.
- **Cloud upload blocked on failed backup** — if any files failed to download, the ZIP is not pushed to cloud storage so a good backup is never overwritten by a broken one.
- **FTP remote directory default changed from `/public_html` to `/`** — avoids silent path mismatches on servers where the FTP root is the web root.

---

## 0.2.4 — 2026-04-15

### Fixed
- **Per-profile OAuth authentication** — the per-profile Creds override field now shows a status label and an "Authenticate with Google" button when an OAuth client secret JSON is selected. Previously only the Global Cloud Config had an auth button, making it impossible to authenticate profile-specific credentials from the UI.

---

## 0.2.3 — 2026-04-14

### Added
- **Scheduled backups** — per-profile automatic backup scheduling. Set daily or weekly, pick the time, done. A background thread fires due backups without any user action.
- **System tray** — minimize to tray instead of closing. Right-click the tray icon to open the app, run a backup, or quit. Requires "Minimize to system tray" enabled in Settings → Automatic Backups.
- **Launch at startup** — option to start SUYB automatically when Windows boots (registry key) or Linux logs in (.desktop autostart file). Toggle in Settings → Automatic Backups.
- **Help tab** — in-app documentation covering all tabs, configuration, scheduling, cloud setup, and troubleshooting.

### Fixed
- Install AI button was launching a second instance of the app (sys.executable in a PyInstaller build points to the exe itself, not Python). Now finds system Python via shutil.which or shows a manual install dialog.

---

## 0.2.2 — 2026-04-14

### Added
- **Crash recovery checkpoints** — the backup engine writes an atomic checkpoint file after every downloaded file. If the process is interrupted (Windows Update reboot, power cut, crash), the next run detects the checkpoint and offers to resume from where it stopped rather than starting over. The checkpoint uses temp-file + atomic rename so even a power cut during the write itself cannot produce a corrupt checkpoint.
- **SHA-256 verification at every transfer stage**:
  - *Backup*: after each FTP download, the file's SHA-256 is computed and compared against the manifest. On mismatch, the file is retried once and re-verified. A second mismatch marks the file as failed with expected/actual hashes logged.
  - *Restore*: before uploading each local file, its checksum is verified against the manifest — a corrupt local file is rejected before it can overwrite a good server copy.
  - *Restore post-upload*: FTP SIZE command verifies the server-side file size after each upload.

### Fixed
- Cloud upload was silently skipped with no log output when credentials were missing. Now logs the specific reason.

---

## 0.2.1 — 2026-04-14

### Added
- **First-run setup wizard** — six-step guided setup on first launch: Welcome, Blog Details, Admin Login (with Test Connection), FTP Setup (with Test FTP), Backup Destination (with Browse), summary and tab tour.
- **Friendlier theme** — warm dark palette replacing harsh black, softer leaf green replacing neon, fonts bumped 1pt for readability.
- **Card-based Settings tab** — redesigned with card frames, better spacing, consistent Browse buttons everywhere.
- **Browse buttons** — added to Credentials JSON and Local backup directory in both the Settings tab and the New/Edit Profile dialog. Previously these were text-only fields.
- **Test Login / Test FTP buttons** — in the Settings tab Site Connection card. Both run in background threads and show results inline.
- **Save Profile creates new profiles** — previously refused with "Select a profile first" if no profile was loaded. Now creates from the form if a blog name is entered.
- **New Profile button** — clears the form to start a fresh profile without needing the top-bar +New button.
- **backup_method saved explicitly** — previously the backup method (FTP/Cloud/Local) was inferred from cloud_provider and ftp_host on load, causing FTP to always win if both were set. Now stored directly.
- **Persistent data paths** — profiles and config.ini now save next to the exe in a PyInstaller build instead of to a temp directory that gets wiped on exit.
- **Window state persistence** — maximized/normal state remembered between sessions. Closing maximized reopens maximized.
- **Auto-hide scrollbars** — log pane and Settings tab scrollbars hidden when content fits, shown when it overflows.
- **FTP certificate option** — "Verify certificate" checkbox in FTP settings. Off by default (accepts shared hosting cert mismatches, same as FileZilla's trust dialog). On for strict verification.

### Fixed
- Recovery kit POST parameters were wrong (sent `action=export_recovery_kit` instead of `action=export&type=recovery_kit`), causing the server to silently return HTML and the backup to fail.
- Login test used `snap-login.php` which doesn't exist. Fixed to `login.php`.
- FTP_TLS hostname mismatch crash — shared hosting servers present certs for the server hostname, not your domain. Python's FTP_TLS rejected these. Now matches FileZilla's behavior (encryption active, hostname check off).
- Backup tab layout — options and buttons were packed after the log pane with expand=True, pushing them off screen. Buttons now pack from the bottom first.
- BooleanVar crash when loading profiles that predated the ftp_verify_cert field.
- ProfileDialog had a lone Browse button at the bottom unconnected to any field.

---

## 0.2.0 — 2026-04-13

### Initial release
- Six-stage backup pipeline: login → recovery kit → SQL dumps → FTP differential download → ZIP package → cloud push → verify.
- Differential backups using manifest checksums — only downloads files that changed since the last run.
- Cloud upload to Google Drive (service account or OAuth) and OneDrive (MSAL).
- Restore from local ZIP, local recovery kit, or cloud-stored backup.
- Audit mode — three-way comparison of manifest vs server filesystem vs database to find missing, orphaned, or mismatched files.
- Multi-profile management — one profile per blog, dropdown selector.
- Hub/Spoke discovery — auto-creates profiles from a SnapSmack multisite hub.
- Export/Import settings for moving config between machines.
- AI-assisted file matching for restore (optional, requires sentence-transformers).
