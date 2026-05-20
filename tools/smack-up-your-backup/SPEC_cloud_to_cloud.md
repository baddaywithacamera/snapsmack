<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  (Authoritative byte sequence: tools/check-eof.py EOF_MARKERS.)
  Missing or different = truncated/corrupted. Restore before saving.
-->


# SPEC: Cloud-to-Cloud Sync (Google Drive → OneDrive)

**Version target:** 0.2.6  
**Status:** Awaiting approval

---

## Problem

High-res source files for foundtextures.ca live exclusively on Google Drive.
They have no FTP/server copy to back up via the existing backup pipeline.
A second copy on OneDrive provides redundancy with no manual effort.

---

## What this adds

A **"Cloud Sync" tab** in SUYB that runs an independent sync job:
- Source: a Google Drive folder (user's own files, not app-created)
- Destination: an OneDrive folder
- Transfer: file-by-file, no ZIP — files land on OneDrive individually and are browsable
- Differential: skip files already on OneDrive with matching name + size
- Progress UI: same pattern as the Backup tab (progress bar, stats row, log)

Cloud Sync jobs are **not** blog backup profiles. They are stored separately
as sync configs (JSON, one per named job).

---

## Scope

**In:** Google Drive source → OneDrive destination, differential file sync,
interactive auth for both services, per-job config, cancel mid-run.

**Out:** folder recursion (flat folder only, v0.2.6), bidirectional sync,
scheduling (can be added later), other cloud pairs.

---

## Files

### New

| File | Purpose |
|------|---------|
| `cloud_sync_engine.py` | Sync logic: list source, diff against dest, download→upload, callbacks |
| `sync_manager.py` | CRUD for sync job configs (parallel to `profile_manager.py`) |

### Modified

| File | Change |
|------|--------|
| `cloud_client.py` | (1) Add `drive.readonly` scope to `DriveClient` for reading user's own files; (2) Fix `OneDriveClient` auth: replace device-flow console output with `acquire_token_interactive()` (opens browser); (3) Add `authenticate_onedrive()` and `get_onedrive_token_status()` helpers |
| `main.py` | Add `CloudSyncTab` class and "Cloud Sync" tab button |
| `requirements.txt` | Ensure `msal>=1.28` is listed (OneDrive auth) |
| `CHANGELOG.md` | 0.2.6 entry |
| `smackupyourbackup-0.2.6.spec` | New PyInstaller spec |

---

## Credentials

### Google Drive (source)
Same OAuth client secret JSON already used for Drive upload. **One change:**
the scope expands from `drive.file` to `drive.readonly` so SUYB can read
folders it didn't create. A separate token cache file is used
(`*_readonly_token.json`) so the upload token is unaffected.

The Cloud Sync tab has its own "Authenticate with Google" button that runs
the readonly consent flow independently.

### OneDrive (destination)
User creates a **public client app** in Azure portal
(portal.azure.com → App registrations → New → Mobile/Desktop):
- Platform: Mobile and desktop applications
- Redirect URI: `http://localhost` (MSAL fills the port)
- Delegated permissions: `Files.ReadWrite`, `offline_access`
- No client secret needed (public client)

Credentials JSON stored locally, format:
```json
{ "client_id": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" }
```

Token cache stored next to it as `*_token_cache.bin`.

Auth in `OneDriveClient`: replace `initiate_device_flow` with
`acquire_token_interactive(scopes, login_hint=None)` which opens the
system browser. Falls back to cached token silently on subsequent runs.

---

## Sync config schema

Stored in `C:\SmackUpYourBackup\sync_jobs\{name}.json`:

```json
{
  "name": "foundtextures high-res",
  "source_provider": "google_drive",
  "source_credentials_file": "C:\\...\\oauth_client.json",
  "source_folder_id": "1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs",
  "dest_provider": "onedrive",
  "dest_credentials_file": "C:\\...\\ms_app.json",
  "dest_folder_id": "root:/FoundTexturesBackup:"
}
```

`dest_folder_id` for OneDrive uses the Graph API path syntax:
`root:/FolderName:` for a folder at Drive root, or a folder item ID.

---

## Engine: `cloud_sync_engine.py`

```python
class CloudSyncEngine:
    def __init__(self, config: dict, on_log, on_progress, on_stats, on_done, on_ask=None): ...
    def run(self) -> None: ...          # called in background thread
    def cancel(self) -> None: ...
```

Callbacks mirror `backup_engine.py`:
- `on_log(msg: str)` — line appended to log widget
- `on_progress(pct: float)` — 0.0–1.0
- `on_stats(done, total, skipped, failed, bytes_done, bytes_total)` — stats row
- `on_done(result: dict)` — `{ok, files_synced, files_skipped, files_failed, bytes_synced, cancelled}`
- `on_ask(msg: str)` — failure-threshold prompt (same pattern as backup)

### Run sequence

1. **List source** — `DriveClient.list_files()` for the configured folder ID.
   Log file count.
2. **List dest** — `OneDriveClient.list_files()` for the configured folder/path.
   Build a dict `{name: size}`.
3. **Diff** — for each source file: skip if dest has same name and size within
   1% tolerance (Drive/OneDrive size reporting can differ slightly for metadata).
   Log skipped count.
4. **Transfer loop** — for each file to sync:
   a. Download from Drive to a temp file in `%TEMP%\suyb_sync\`
   b. Upload temp file to OneDrive
   c. Delete temp file
   d. Fire `on_stats`, `on_progress`
   e. On failure: log error, increment `files_failed`, check threshold → `on_ask`
5. **Done** — fire `on_done`.

Failure threshold: same as backup (1 failure prompts the user). The
prompt dialog is reused from the Backup tab (same queue message pattern).

---

## UI: `CloudSyncTab`

Tab button label: **Cloud Sync**

Layout (matches Backup tab structure):

```
[ Job: [dropdown ▼] ]  [New]  [Edit]  [Delete]

Source:  Google Drive — foundtextures high-res   [✓ Authenticated]
Dest:    OneDrive — FoundTexturesBackup           [✓ Authenticated]

[████████████░░░░░░░░░░░░]  47%

Files: 312 / 664 synced  |  Skipped: 89       Bytes: 1.2 GB / 2.6 GB
       Current: DSC_4821.jpg                   Elapsed: 00:12:44  ETA: 00:14:20

[log text widget]

[  Run Sync  ]  [  Cancel  ]
```

Job management (New/Edit) opens a `tk.Toplevel` form matching the fields
in the sync config schema above. Each credentials field has a file picker
and an authenticate button (Google or Microsoft depending on provider).

Queue messages from engine thread → `_poll()`:
- `("sync_log", msg)` → append to log
- `("sync_progress", pct)` → update progress bar
- `("sync_stats", done, total, skipped, failed, bytes_done, bytes_total)` → stats row
- `("sync_done", result)` → unlock UI, show summary
- `("sync_ask", msg)` → same Toplevel prompt as backup (Abort / Continue)

---

## `sync_manager.py`

```python
SYNC_DIR = os.path.join(_app_dir(), "sync_jobs")

def list_jobs() -> List[str]: ...
def load_job(name: str) -> Optional[dict]: ...
def save_job(config: dict) -> None: ...
def delete_job(name: str) -> None: ...
def new_job_template() -> dict: ...
```

Mirrors `profile_manager.py` exactly.

---

## Error handling

- Auth failures surface in the status label next to each authenticate button.
- If source or dest is not authenticated when Run is clicked: show error in
  log, do not start the thread.
- Network errors mid-transfer: log the error + filename, increment
  `files_failed`, check failure threshold.
- Cancel mid-transfer: delete the partial temp file, mark remaining files
  as cancelled (not failed), report in `on_done`.

---

## Out of scope for 0.2.6

- Recursive subfolder sync
- Scheduling cloud sync jobs
- Reverse direction (OneDrive → Drive)
- Progress persistence / resume (no checkpoint for sync jobs)
<!-- ===== SNAPSMACK EOF ===== -->
