<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  (Authoritative byte sequence: tools/check-eof.py EOF_MARKERS.)
  Missing or different = truncated/corrupted. Restore before saving.
-->


# Smack Your Batch Up — Changelog

Versioned 0.7.<build>. As of 0.7.12 the third number is a plain build counter
(+1 each rebuild) — the old letter suffixes (…0.7.9k) are retired for clarity.

---

## 0.7.18 — 2026-06-20

### Changed
- _TODO: describe this build._

---

## 0.7.17 — 2026-06-19

### Changed
- _TODO: describe this build._

---

## 0.7.16 — 2026-06-19

### Changed
- _TODO: describe this build._

---

## 0.7.15 — 2026-06-18

### Changed
- _TODO: describe this build._

---

## 0.7.14 — 2026-06-18

### Changed
- _TODO: describe this build._

---

## 0.7.13 — 2026-06-16

### Changed
- _TODO: describe this build._

---

## 0.7.12 — Switch to numeric build counter; Gemini/post audit log; cat/album list fix (2026-06-14)

- Version scheme: dropped letter suffixes (was 0.7.9k) for a plain incrementing
  build number. Next builds are 0.7.13, 0.7.14, …
- New rotating audit log (`sybu.log`, daily, 7-day retention) recording every
  Gemini request + raw response + parsed result per image, and every post
  (resolved category/album IDs + outcome). Separate from the stdout debug log.
- Enrichment: `_on_enrich` now feeds Gemini the proper-case display names for
  categories/albums (was lowercase internal keys) so returned values match the
  dropdown options. (POST already lowercases on lookup, so mapping is unchanged.)
- Build: spec now explicitly bundles `recovery.py`.

## 0.7.9k — Crash recovery, batch selection, cancel + session-hang fix (2026-06-12)

### Fixed
- **POST froze forever on "Checking session…"** — `_ensure_connected()` in `main.py`
  called `self._client.is_session_alive()`, a method removed in the 0.7.9e API-key
  migration. It raised `AttributeError` and hung the POST at 0/N (the 0.7.9j hang that
  lost ~$1 of Gemini enrichment). API-key auth has no server session, so the whole
  session-check block was removed — it now just confirms client + site data are present.
  (Third leftover from the same migration after 0.7.9h `_logged_in` and 0.7.9i `_api_key`.)

### Added
- **Incremental enrichment recovery (new `recovery.py`).** Each image's Gemini
  enrichment (title/tags/category/album/orientation/colors) is written to disk the
  instant it lands, to `recovery/sybu_recovery_<jobid>.json` next to the exe, via an
  atomic temp-write + `os.replace` (crash-safe). Reloading the same image folder offers
  **"Resume — N of M already enriched. Restore?"**, repopulates the rows, and skips
  re-enriching them (no repeat Gemini spend). Items are marked posted as they go; the
  file is pruned once the whole batch is posted. A crash/hang/close can no longer throw
  away paid enrichment.
- **Select one / some / all.** Every queue row has a checkbox (default on) plus a
  "Select all" toggle in the queue header. Both **Enrich** and **Post** act only on the
  ticked rows. Row status is now matched by entry identity, so processing a subset still
  lights up the correct rows.
- **Cancel a running job.** While posting, the POST BATCH button turns into a red
  **CANCEL** button. It stops cleanly between images — already-posted items are kept,
  the rest stay in the queue — and reports how many posted before the stop.

---

## 0.7.9i — Fix attribute name typo in api-key check (2026-05-08)

### Fixed
- `poster.py` — `fetch_site_data()` still raised `AttributeError` after the 0.7.9h fix, this time for `'SnapSmackClient' object has no attribute 'api_key'`. The constructor stores the key as `self._api_key` (underscore prefix); 0.7.9h checked `self.api_key` instead. One-character typo. Now correctly references `self._api_key`.

---

## 0.7.9h — Drop legacy `_logged_in` check (2026-05-08)

### Fixed
- `poster.py` — `fetch_site_data()` raised `AttributeError: 'SnapSmackClient' object has no attribute '_logged_in'` immediately after a successful API-key auth. The `_logged_in` flag was a leftover from session-auth days; `login()` no longer sets it (and `login()` itself is gone). Replaced the check with a simple `if not self._api_key` so the client validates that the key was supplied at construction without referencing a deleted attribute.

---

## 0.7.9g — Restore Remember checkbox variable (2026-05-08)

### Fixed
- **AttributeError on launch** — `__init__` crashed at `_build_ui()` line 1234 with `'_tkinter.tkapp' object has no attribute '_rem_var'`. The 0.7.9e API-key-auth refactor deleted `self._pass_var = tk.StringVar()` and accidentally also deleted `self._rem_var = tk.BooleanVar()`, but the "Remember" checkbox still references it (lines 1234, 3127, 3230). Restored the BooleanVar initialization beside `_url_var` and `_api_key_var`.

---

## 0.7.9e — API key authentication (2026-05-01)

### Changed
- **Session auth replaced with API key** — SYBU no longer logs in with username/password. Authentication now uses a 64-char hex key sent as the `X-Snap-Key` request header on every request. Generate the key in SnapSmack Admin → Settings → API Access. No session to maintain, no keepalive timer, no login page interaction.
- **Settings panel** — USERNAME and PASSWORD fields replaced with a single API KEY field.
- **Connect flow** — verifies the key against `sybu-data.php` on connect; instant rejection if the key is wrong or revoked.
- **`poster.py`** — `login()`, `is_session_alive()`, `relogin()` removed. `keepalive()` is now a no-op that always returns True (kept for call-site compatibility). `SnapSmackClient` now takes `api_key` as a constructor argument.

### Requires
- SnapSmack 0.7.36 or later (adds `core/api-auth.php`, `tool_api_key` setting, migration 046)

---

## 0.7.9d — Login endpoint fix (2026-05-01)

### Fixed
- **Login endpoint updated** — SnapSmack 0.7.22 blocked direct access to `login.php` via `.htaccess`. SYBU was still POSTing to `/login.php` and getting 403 on every connect attempt. Updated `poster.py` to POST to `/snap-in` (the named route) instead, which routes through the `.htaccess` rewrite and is not subject to the direct-file block.

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
<!-- ===== SNAPSMACK EOF ===== -->
