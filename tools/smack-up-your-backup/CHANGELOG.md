<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  (Authoritative byte sequence: tools/check-eof.py EOF_MARKERS.)
  Missing or different = truncated/corrupted. Restore before saving.
-->


# Smack Up Your Backup — Changelog

## Versioning

SUYB uses `0.7.x` where the third number is SUYB's own build count within the SnapSmack milestone era. When SnapSmack moves to 0.8.x (Closed Beta), SUYB resets to `0.8.1`. `BUILD_VERSION` in `main.py` must always match the latest entry in this file.

Historical entries used a `0.7.9x` letter-suffix scheme. That scheme is retired. Rapid same-day debug iterations (0.7.9e–h) are not counted as separate builds — only meaningful releases count. Entries are preserved as-is for history.

---

## 0.7.28 — 2026-08-27

### Fixed — the hub now receives the shared backup key

The last packaged Windows build (`0.7.25`) could self-heal backup credentials on
ordinary spokes but still failed with HTTP 401 when backing up the hub itself.
The fleet-discovery path now provisions the same revocable, backup-scoped key on
the hub and every spoke, then stores it once for SUYB to use across the fleet.
After installing this build, run **Discover Fleet** once in THE HUB to complete
the idempotent rollout.

### Clarified — Windows tray and Linux scheduling

The Windows build still supports **Minimize to system tray instead of closing**
when enabled under Settings → Automatic Backups. Linux deliberately uses its OS
schedule for unattended backups rather than depending on inconsistent desktop
tray implementations; the Linux window may be closed after the schedule is
installed.

---

## 0.7.27 — 2026-08-26

### Added — SLAP HAPPY

SUYB now has a dedicated **SLAP HAPPY** tab for SNAP SLAPPER backups. SNAP
SLAPPER publishes an explicit handoff declaring its settings directory, shared
catalog, and every photograph root. The tab shows those locations before a run
and lets the user independently include settings, catalog/cache data,
photographs, and editable `.slapper` / `.slaprecipe` projects.

Backups can be full or incremental. Incrementals contain only changed files plus
a deletion ledger, maintain a separate verified baseline for every destination,
and never advance a cloud baseline unless the upload's bytes are verified.
Destinations include a local folder and every SUYB profile with a configured
Google Drive or Box target, so multiple cloud drives remain distinct.

SLAP HAPPY can also include SUYB's own preferences, schedules, and sanitized
profile definitions. Passwords, API keys, OAuth tokens, credential files, and
other live secrets are explicitly redacted from these ordinary backup ZIPs.

---

## 0.7.26 — 2026-08-22

### Added — Backup Manager (new "Manage" tab)

A proper manager for the backup ZIPs kept in the cloud, replacing the bare
"pick one and press Select" restore picker. The old picker was a flat list with
no way to sort, search, delete, or see how much space backups were using.

The new **Manage** tab (between Restore and Audit) shows every backup across
every blog that shares the cloud folder — not just the selected profile — and
adds:

- **Source picker** — "Backup cloud (Google Drive / Box)" plus every **Backblaze
  B2** bucket referenced by a Cloud Sync job, so Backblaze backups can be viewed,
  tidied and freed from the same screen. Backblaze credentials are read from the
  sync jobs (a blog profile never stores B2 keys).
- **Sort** — newest, oldest, blog name, **grouped under each blog** (with a
  per-blog count and total size), file name, largest, smallest. Column headings
  are clickable too.
- **Search** box (matches blog or file name) and a **date-from / date-to** filter.
- **Tick-box selection** with a "tick all" in the header — big, unambiguous
  click targets instead of Ctrl/Shift-click.
- **Restore selected** — Drive/Box backups hand straight to the Restore tab;
  Backblaze backups download to the computer first, then load into Restore as a
  local ZIP.
- **Delete selected** (one or many) — removes the backups from the cloud after a
  confirmation box that lists exactly what goes and how much space it frees. Only
  the ticked backups are touched; blogs and local backups are left alone.
- Status line showing how many backups are on screen and their total size, and
  how many exist in total when a filter is on.
- Full in-app **Help** entry, plus a line on the welcome-screen tour.

### Added — delete support for Google Drive and Box cloud clients

`DriveClient.delete_file` (permanent) and `BoxClient.delete_file` (to Box Trash)
were added — previously only the Backblaze client could delete. A uniform
`delete_file` alias was also added to the Backblaze client so the manager deletes
the same way for every provider.

## 0.7.21 — 2026-08-19

### Fixed — hub credentials now pull from The Hub (backups were falling behind)

The **Discover from Hub** dialog used to pre-fill the Hub URL and Hub API key only
from the currently-selected SUYB profile. The credentials you set once in **THE HUB
app** (its shared credential store, filled on *Discover Fleet*) never reached SUYB,
so the dialog opened blank and discovery — and therefore the fleet backups — stalled.

SUYB now reads `hub_url` and `hub_key` from The Hub's shared store first, falling
back to the current profile only when the shared store is empty (old / portable
installs still work). This honours the standing rule: **every tool that needs a
login pulls its credentials from The Hub, never its own private store.**

- **`config.shared_cred(key, default)`** — new reader for The Hub's shared
  credential store (`snap_creds`), path-safe and returns the default when the
  shared home isn't present.
- **`HubDiscoveryDialog`** — pre-fills Hub URL + API key from the shared store,
  profile as fallback. Discovery then authenticates by Bearer key (no admin
  password needed), pulls every spoke, and points them at the Global Cloud Config.

## 0.7.19 — 2026-08-04

### Security (SECAUDIT 037, Finding A — credential encryption at rest)

Closes the headline finding from 0.7.18's audit: saved credentials are no longer
merely base64-obfuscated. A new **credential vault** encrypts the FTP password,
admin password, scoped API key, and Google Drive / Box OAuth refresh tokens with
a key derived from a passphrase (scrypt) and sealed with Fernet (AES-128-CBC +
HMAC-SHA256). The passphrase is never stored, so a lost or synced thumb drive no
longer yields working credentials.

- **New module `secret_vault.py`** — enable / unlock / change-passphrase / disable,
  plus transparent seal/unseal of secrets. Fully covered by roundtrip,
  wrong-passphrase, tamper-detection, re-key, and fail-closed tests.
- **Whole-store migration is transactional.** Profiles, Google upload/read-only
  tokens, Box tokens, and cloud-sync Backblaze keys move together through a
  durable rollback journal. Interrupted enable/re-key/disable operations recover
  before normal startup; a locked vault can never downgrade a write to plaintext.
- **Portable by design is preserved.** No machine-bound keychain is required for
  normal (interactive) use — you enter the passphrase when SUYB starts. The vault
  metadata (`vault.meta`) rides next to the app; the key never touches the drive.
- **Unattended (scheduled) backups.** A headless run has nobody to type the
  passphrase, so — only if you opt in — the master key is cached in *this
  machine's* OS keychain (via `keyring`), off the portable drive. Scheduled runs
  unlock from there. With no keychain backend, scheduled backups are cleanly
  skipped with a clear log message rather than running without credentials.
- **UI.** Settings → **Credential Encryption**: Enable / Change passphrase /
  Disable, and an "allow unattended backups on this computer" toggle. SUYB prompts
  for the passphrase at startup when the vault is on. Enabling the daily schedule
  while encryption is on offers to store the machine key.
- **Backward compatible.** Existing profiles (base64 passwords, plaintext API key)
  load unchanged; enabling encryption re-seals every profile, disabling restores
  the legacy encoding. Token caches written before enabling stay readable and are
  re-sealed on next refresh/auth. Forgetting the passphrase is unrecoverable by
  design (delete `vault.meta` and re-enter credentials).
- Adds `cryptography` and `keyring` to requirements and to the PyInstaller spec
  (with keyring backends named explicitly for the frozen build).

## 0.7.18 — 2026-08-04

### Security (SECAUDIT 037 — desktop client hardening, clean-as-we-go)

First security audit of the SUYB **desktop client's** local attack surface (the
server-side export endpoint was covered separately by SECAUDIT 034). Three
findings; the no-regression tightenings below were applied in this build. Full
report: `secaudits/2026-08-04-037-suyb-desktop-client-credential-and-transport-attack-surface.md`.

- **Finding C — restore path traversal (CLOSED).** The restore pipeline now
  validates every manifest `restores_to` and `directory_structure` entry before
  use and refuses absolute paths, drive-letter paths, NUL bytes, and any `..`
  traversal segment. A recovery kit from an untrusted source can no longer direct
  uploads outside the profile's remote directory. Rejected entries are surfaced in
  the restore log. (`restore_engine.py`)
- **Reverse traversal closed too.** Server-supplied backup inventory paths are
  resolved beneath the local staging directory and rejected if absolute,
  drive/UNC-prefixed, or traversing, preventing local overwrite by a compromised
  backup source. (`backup_engine.py`, `path_safety.py`)
- **Finding B — SFTP host-key pinning (HARDENED).** SFTP previously accepted any
  host key on every connection (weaker than trust-on-first-use, because the key
  was never persisted or compared). It now pins the server's key to a portable
  `suyb_known_hosts` file on first connect and rejects a **changed** key on later
  connections — genuine TOFU. The first honest connection is unaffected. Delete
  `suyb_known_hosts` to re-pin after a legitimate server key rotation.
  (`sftp_client.py`) The FTPS "trust invalid certificate" default is unchanged
  pending a product decision (it exists for mismatched shared-hosting certs).
- **Finding A — credential-at-rest honesty + permissions (HARDENED, not closed).**
  Profile and OAuth/Box token files are now tightened to owner-only where the OS
  supports it (no-op on FAT/Windows). The storage is documented honestly: the
  `_enc` fields are base64 **obfuscation, not encryption**, and the SUYB folder is
  secret-equivalent to the credentials in it. Real at-rest protection
  (passphrase-derived encryption that preserves portability) is recommended as a
  separate, owner-approved build rather than faked here with an app-baked key.
  (`profile_manager.py`, `cloud_client.py`)

## 0.7.17 — 2026-07-22

### Added
- **Delete button for blog profiles.** The profile toolbar (top right) had New, Edit, and Dup but no way to remove a profile from inside the app — so duplicates made with **Dup** could only be cleared by hand-deleting their JSON from the `profiles/` folder. There is now a **Del** button beside Dup: it confirms, then removes the selected profile via `profile_manager.delete_profile()`. It deletes only that profile's SUYB connection settings on this computer (site URL, FTP + admin credentials, schedule) — backups already written to disk or the cloud are never touched. After a delete, SUYB falls back to the first remaining profile, or clears the selection if that was the last one.

### Note
- **Changelog had drifted from `BUILD_VERSION`.** The version reached `0.7.16` in `main.py` while the newest written entry was `0.7.11` (0.7.12–0.7.16 shipped without notes). This entry sets both to `0.7.17` to restore the "BUILD_VERSION matches the latest entry" invariant; the undocumented gap is left as-is rather than reconstructed from memory.

## 0.7.11 — 2026-07-06

### Fixed
- **Backup-complete ping no longer fails silently — hub dashboard now reflects the backup.** The completion ping built a *fresh* session and re-logged in from scratch (with no scoped `suyb` Bearer key) instead of reusing the session that had already authenticated for the whole backup. When that re-login didn't stick, the POST to `suyb-complete.php` hit an unauthenticated redirect to the HTML login page and `resp.json()` died with `Expecting value: line 1 column 1 (char 0)` — so the site never recorded `last_backup_at` and the Multisite BACKUP dot stayed red. The ping now reuses the already-authenticated backup session. Also hardened: a non-JSON response now raises a clear "not authenticated / endpoint not deployed" message instead of a cryptic parse error.

### Changed
- **Transfer speed picker replaces the freeform "Pacing delay (sec)" field.** The profile editor now offers UNZUCKER's named fast→slow tiers (Full Send → Geological Time) for consistency across the toolset. **Default is now Full Send (no delay)** — pulling files down over FTP doesn't strain a server the way posting + thumbnail generation does, so there's no reason to throttle unless a specific fragile shared host demands it. Existing profiles keep their current delay (shown as the matching tier); new profiles default to Full Send. Per-profile, so one shared-host blog can crawl while the rest run flat out.

## 0.7.4 — 2026-07-02

### Fixed
- **Admin login uses the site's login slug (was hardcoded `/login.php`).** On installs with a renamed login path — the default `snap-in`, and the recommended hardened config — `/login.php` returns 403, so admin-login profiles plus the "Test Login" and reachability checks failed even with correct credentials (API-key profiles were unaffected, they skip login). Profiles gain a **Login slug** field (default `snap-in`), threaded through the backup session, hub discovery, and both Test buttons. Fixes the "403 / creds fine" failures on slug-login sites.

## 0.7.3 — 2026-04-26

### Changed
- **Versioning scheme updated** — retired `0.7.9x` letter-suffix format in favour of plain `0.7.x` (meaningful release count within the Alpha era). See Versioning note above.

### Security
- **Drive API query injection closed** — `name_filter` in `DriveClient.list_files()` is now escaped before interpolation into the Drive API query string. Single quotes in profile names no longer break listing.
- **Debug log removed** — unconditional `_dbg()` logging to `suyb-debug.log` in the exe directory has been removed from `DriveClient.list_files()`. Was leftover development instrumentation, written on every backup run.
- **SA key patterns added to .gitignore** — `*-drive-key-*.json`, `*_token.json`, and `*_box_token.json` are now excluded so credential files can never be accidentally committed.

---

## 0.7.9h — 2026-04-20

### Fixed
- **Cloud Sync source count wrong (1145 instead of 1457)** — `src_map` was built as a dict keyed by filename using a dict comprehension, silently dropping any file whose name already appeared in the map. Drive was returning all 1457 files correctly (confirmed via page logging); 312 of them shared a filename with another entry and were lost. Fixed: build the map in a loop, keeping the most recently modified entry when duplicates exist. Duplicate count is logged with a ⚠ warning.

---

## 0.7.9g — 2026-04-20

### Fixed
- **Console/DOS window no longer appears on launch** — spec changed to `console=False`. Debug output still captured to `suyb-debug.log` next to the exe.

---

## 0.7.9f — 2026-04-20

### Added
- **Drive listing debug logging** — `list_files` now logs per-page counts, folder ID, query, and final total to both the console window and `suyb-debug.log` next to the exe. Lets us see exactly what the Drive API is returning page by page to diagnose the 1145 vs 1457 source count discrepancy.

---

## 0.7.9e — 2026-04-20

### Fixed
- **Google Drive source listing returning wrong count** — removed `orderBy="modifiedTime desc"` from Drive API `list_files` pagination. Drive re-sorts between paginated requests when ordering is specified, causing files to be skipped across page boundaries. Still investigating if this fully resolves the 1145 vs 1457 discrepancy.

---

## 0.7.9d — 2026-04-20

### Fixed
- **B2 credentials not persisting across sessions** — Edit Sync Job was saving B2 Key ID, Application Key, and Bucket name to the dialog's result dict but relying on the caller to write to disk after `wait_window()` returned. On Windows this handoff was silently failing: the window closed, the caller read `dlg.result`, but the values never reached the JSON job file. Save is now self-contained inside `_SyncJobDialog._save()` — writes directly to disk via `sync_manager.save_job()` before destroying the dialog. Prints a confirmation line to the console (`[SUYB] saved → <path>`) so the write is visible and verifiable.
- **Edit Sync Job dialog oversized** — window was 600×720, taller than necessary on most screens. Reduced to 600×560.
- **BUILD_VERSION incremented** — was "0.7.9c", now "0.7.9d".

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
<!-- ===== SNAPSMACK EOF ===== -->
