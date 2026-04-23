# Smack Your Batch Up — Changelog

Same versioning scheme as SnapSmack (0.7.9x). Letter increments per SYBU release.

---

## 0.7.9c — Advanced Visual Match (2026-04-23)

### Added
- **ADV. MATCH tab** — two-stage pHash + SIFT visual image matching, ported from Fix Your Batch Up:
  - Pick a **Server Folder** (local FTP copy of blog images) and an **Originals Folder** (your local photos)
  - **Run Matching** launches a `ProcessPoolExecutor` (up to 4 workers, capped at ~75% CPU):
    - Stage 1: perceptual hash (pHash) pre-filter — picks the top 10 closest candidates per server image
    - Stage 2: SIFT keypoint matching via OpenCV — scores each candidate, selects the best match
  - Results render as scrollable **MatchRow** cards — server image on the left, confidence score in the centre (colour-coded: green ≥ 82%, amber ≥ 60%, red below), matched original on the right
  - Per-row actions: **Upload** (to Google Drive using credentials already in Settings, writes link back via `smack-backfill.php`), **Pick Different** (file browser), **Skip**
  - Serial upload queue prevents race conditions when multiple rows upload simultaneously
  - Stop button cancels matching mid-run
  - Drive credentials and folder ID are read from Settings — **no separate credential entry required**
- **`matcher.py`** added to SYBU — shared matching engine (pHash + SIFT)
- **`poster.py`** — `SnapSmackClient.backfill_update_link(snap_id, download_url)` for writing Drive URLs back to the blog

### Changed
- Repair tab renamed to **BASIC REPAIR & MATCH**

---

## 0.7.9b — Audit & Repair (2026-04-22)

### Added
- **Audit tab** — connects to a live SnapSmack site via `smack-audit.php` and shows:
  - Summary stats (total posts, Drive link coverage, duplicate title count)
  - Duplicate titles list grouped by title, sorted by severity, with per-post dates and Drive link status
  - Missing Drive links list
  - "Go to Repair" button when issues are found
  - Auto-pulls on first tab switch if already connected; Refresh button for subsequent pulls
  - **Audit progress indicators** — indeterminate bouncing progress bar and elapsed time counter (updates every second) visible during the two-phase pull. Step 1 fetches summary to get total post count; Step 2 displays that count while fetching the full post list. Both clear automatically on completion or error.
- **Repair tab** — three independent repair actions:
  - **Rename Drive Files to {id}.jpg** — batch-renames every Drive file to `{snap_id}.jpg`. URLs are file-ID-based and do not change. Handles both `/file/d/ID/` and `?id=ID` URL formats. Resumable stop/start. 150ms rate-limit delay per file.
  - **Re-enrich Duplicate Titles** — downloads original from Drive, sends to Gemini, writes new unique title back to blog via `smack-audit.php`. Retry loop up to 4× for uniqueness. `used_titles` set prevents intra-run duplicates. 500ms delay between Gemini calls. Marks audit as stale on completion.
  - **Backfill Missing Drive Links** — per-post URL entry with inline Save buttons, uses `smack-backfill.php` to write URL and enable `allow_download`.
- **Settings tab** — multi-site profile manager. Left pane: sortable site list with New / Delete / Load Site buttons. Right pane: scrollable form with CONNECTION (URL, username, password), GOOGLE DRIVE (credentials file, folder ID), GEMINI AI (API key), and DEFAULTS (copyright, category, album, orientation) sections. **Test Connection** button in the CONNECTION box attempts a live login using the current form fields without requiring a save first — shows ✓/✗ result inline. **Load Site** copies all profile fields into the POST tab and fires a connection attempt, switching directly to POST.
- **`profile_manager.py`** (new module) — per-site profile CRUD. One JSON file per site in `profiles/` next to the exe. Password stored as base64-obfuscated `password_enc` field (same convention as SUYB). Functions: `list_profiles`, `load_profile`, `save_profile`, `delete_profile`, `rename_profile`, `blank_profile`.
- **Tab strip** — POST / AUDIT / REPAIR / SETTINGS tabs in the header with ACCENT underline indicator.
- **Debug log** — `sybu-debug.log` written next to the exe. Both stdout and stderr are redirected to it at startup (before any import). Each session opens with a timestamped header. Line-buffered so entries appear immediately without waiting for a flush.
- **App icon** — exe, taskbar, and alt-tab now use the SnapSmack devil camera logo. Multi-size ICO (16×16 through 256×256) embedded via PyInstaller `icon=` in the spec.
- **`drive.py`** — two new functions: `rename(service, file_id, new_name)` and `download_to_temp(service, file_id) → tmp_path`
- **`poster.py`** — three new `SnapSmackClient` methods: `audit_summary()`, `audit_list()`, `audit_update_title()`. `audit_list()` timeout increased to `(10, 180)` — 10s connect, 3 min read — to handle large sites returning 1000+ posts.
- **`smack-audit.php`** (server-side) — new endpoint: GET summary, GET list, POST update_title

### Fixed
- Post tab content now lives in `self._post_frame` (child of root window) rather than directly on the root. Required to support tab switching without destroying/recreating widgets.
- `_on_audit_loaded` and `_on_audit_error` now both call `_audit_stop_progress()` so the progress bar and elapsed timer clear when the pull finishes or fails.

### Build
- **UPX disabled** (`upx=False` in spec) — UPX compression was stalling builds for 30–60+ minutes on the large Google API wheels. Builds now complete in 2–5 minutes.
- **`--clean` added** to `pyinstaller` call in `build.bat` — clears PyInstaller's analysis cache between runs.
- **All local `.py` modules explicitly listed** in spec `datas` and `hiddenimports` (same pattern as SUYB) — prevents PyInstaller from doing redundant deep-crawl analysis to locate them.
- **`pathex=[_src]`** and absolute paths for `hookspath` and `runtime_hooks` — spec now works correctly regardless of which directory `pyinstaller` is invoked from.

---

## 0.7.9a — Uniqueness Fix (2026-04-22)

### Fixed
- **Gemini duplicate haiku titles** — `enrich_batch()` now tracks a `used_titles` set seeded from `existing_titles` (live blog title list from `sybu-data.php`). On duplicate, retries up to 4× with "that title is taken" prepended to the prompt.
- **`sybu-data.php`** — now returns `titles` array (all existing post titles) so SYBU can seed the uniqueness check at enrichment time.
- **`poster.py` SiteData** — added `titles: List[str]` field; `fetch_site_data()` populates it from the API response.
