<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# CRONOMETER — changelog

CRONOMETER (a cron + chronometer pun) is the fleet cron / job-health console: one
board that shows, per SnapSmack site, whether the scheduled jobs (RSS fetch,
version check, fediverse delivery, backups, SMACKBACK integrity) are running —
red / amber / grey / green — so a silently-dead cron is caught before it bites.

Versioning follows the SnapSmack desktop-family `0.7.x` convention;
`bump_version.py` adds one patch for subsequent builds.

## 0.7.4 — 2026-08-31

### Changed
- Replaces the vertically sprawling always-expanded cards with a compact fleet
  overview and expandable per-site job details.
- Adds fleet-wide totals for failed, stale, unknown, healthy, and offline sites.
- Reduces secondary-button prominence so failures and job state lead the screen.

---

## 0.7.3 — 2026-08-31

### Fixed
- Uses each profile's fleet-management credential for the authenticated heartbeat
  instead of the restricted publishing key. This fixes the fleet-wide HTTP 401
  failure identified and live-verified in Claude's handoff specification.
- Adds COPY STATUS diagnostics and improves board readability.
- Repairs the Windows build script so packaging fails honestly and reliably.

---

## 0.1.1 — 2026-08-31

### Changed
- Superseded by the corrected 0.7.1 family-versioned build.

---

## 0.1.0 — 2026-08-14

### Added
- First cut. Loads the fleet from the shared per-site profile store
  (tools/_shared/snap_profiles) — every blog set up in SYBU / GYSS / COLD SNAP
  appears with no re-typing.
- Polls each site's `GET multisite/heartbeat` with its saved Bearer key, off the
  UI thread, and renders a red / amber / grey / green board with a per-site
  RE-CHECK and a REFRESH ALL sweep.
- Honest degradation: the current heartbeat reports site STATE, not a per-cron-job
  last-run/health block, so only Backups (last_backup_at / last_backup_status) and
  the SMACKBACK breach flag render accurately today; RSS fetch, version check and
  fediverse delivery show an explicit grey "not reported" caution rather than a
  fabricated green. The client already consumes an optional richer `jobs` block, so
  it lights up automatically once the server ships one (see docs/cronometer-spec.md,
  "Server API").
- Transport guard: refuses to send an API key over plaintext http:// (except
  localhost), reusing the shared snap_stepup guard when present.

---
<!-- ===== SNAPSMACK EOF ===== -->
