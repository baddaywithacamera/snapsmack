<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# CRONOMETER — changelog

CRONOMETER (a cron + chronometer pun) is the fleet cron / job-health console: one
board that shows, per SnapSmack site, whether the scheduled jobs (RSS fetch,
version check, fediverse delivery, backups, SMACKBACK integrity) are running —
red / amber / grey / green — so a silently-dead cron is caught before it bites.

Versioning follows the SnapSmack desktop-family `0.7.x` convention;
`bump_version.py` adds one patch for subsequent builds.

## Unreleased

### Fixed
- **Health verdict now reflects the cron run it just triggered.** Each poll runs a
  site's due crons (`multisite/run-crons`), but the job status was read from the
  heartbeat fetched *before* that run — so a job CRONOMETER had just kicked off
  still showed as stale/overdue the instant you checked, a false verdict. The probe
  now re-fetches the heartbeat once after a successful `run-crons` and reports from
  that. Best-effort: if the run or the re-fetch fails, the original heartbeat stands
  so a row is never lost. Regression test: `tests/test_cronometer_heartbeat.py`.
  (Version to be assigned on the next build.)

---

## 0.7.6 — 2026-08-31

### Added
- **Fleet cron driver.** On every poll, CRONOMETER now also nudges each site's due
  crons to run via the new `multisite/run-crons` endpoint — so the fleet's jobs run
  even on sites with no visitor traffic and no system crontab. Best-effort and
  idempotent (the server self-throttles), and it never affects the health verdict.
  Pairs with SnapSmack 0.7.595D. Just having CRONOMETER open keeps the fleet ticking.

---

## 0.7.5 — 2026-08-31

### Changed
- Monitor only the scheduled crons every SnapSmack site actually runs — **fediverse
  delivery, RSS blogroll fetch, version/update check**. Removed **Backups** and
  **SMACKBACK** from the job catalogue: backups are the SUYB desktop tool (no backup
  cron exists) and SMACKBACK rides `cron-version-check.php`, so both produced false
  red/grey rows. Backup freshness is still in the heartbeat (`last_backup_at`) if a
  separate, non-cron indicator is wanted later.
- Removed the backup/smackback honest-degradation fallbacks; fixed the fediverse
  fallback to read `fediverse_enabled` (was the stale `smackverse_enabled`).
- Pairs with SnapSmack 0.7.595D, whose `multisite/heartbeat` ships the matching
  `{fediverse, rss_fetch, version_check}` jobs block.

---

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
