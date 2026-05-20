<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  (Authoritative byte sequence: tools/check-eof.py EOF_MARKERS.)
  Missing or different = truncated/corrupted. Restore before saving.
-->


# GOBSMACKED Scanner — Changelog

## 0.1.0 (2026-04-29)

Initial release.

### Features
- Direct MySQL connection to SnapSmack database
- 25-dimension stylometric vector engine (exact Python port of `core/ste-style.php`)
- Peer comparison — all active commenters compared against each other
- Banned profile comparison — flags matches against stored ban fingerprint vectors
- Results stored in `snap_gobsmacked_scan` table (created on first use, idempotent)
- Results tab with filter by type (peer / banned / unreviewed) and similarity-coded rows
- Mark reviewed action
- Upload to hub API (optional — requires API URL + key in Settings)
- Config persisted to `gobsmacked-scanner.ini` next to the exe
- Debug log written to `gobsmacked-debug.log` next to the exe
- Single-file exe via PyInstaller (`build.bat`)
<!-- ===== SNAPSMACK EOF ===== -->
