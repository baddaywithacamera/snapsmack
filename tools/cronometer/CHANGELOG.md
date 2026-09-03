<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# CRONOMETER — changelog

CRONOMETER (a cron + chronometer pun) is the fleet cron / job-health console: one
board that shows, per SnapSmack site, whether the scheduled jobs (RSS fetch,
version check, fediverse delivery, backups, SMACKBACK integrity) are running —
red / amber / grey / green — so a silently-dead cron is caught before it bites.

Versioning follows the SnapSmack desktop-family `0.7.x` convention;
`bump_version.py` adds one patch for subsequent builds.

## 0.7.8 — 2026-09-01

### Added
- **Maintenance-mode sites are parked, not alarmed.** The heartbeat already
  reports `maintenance_mode`; CRONOMETER now reads it and shows those sites as a
  dim **PARKED** dot instead of red/grey. A parked site is excluded from the
  FAILED/STALE/etc. counts and never sets the fleet headline — a deliberately
  idle blog no longer looks broken.
- **Mute any site (manual ignore).** Each card has a **MUTE / UNMUTE** button for
  blogs you want ignored (not-yet-live, known-parked, a hub you monitor
  elsewhere). Muted sites stay visible but drop out of the alarm counts and
  headline, and roll up under PARKED. The choice is saved to config and survives
  a restart.
- **Hide parked/muted sites.** A **HIDE PARKED (n)** button in the toolbar
  collapses every PARKED + MUTED card off the board (the count stays in the top
  summary), so the fleet view shows only the sites that can actually need
  attention. Toggles back with **SHOW PARKED (n)**; the choice is saved.
- **Site mode shown per card** (photoblog / carousel / smacktalk) and in the
  COPY STATUS report, so the one SMACKTALK blog is obvious at a glance.

### Fixed
- **Fediverse delivery no longer shows red on blogs with no followers.** With
  federation ON but zero followers, the delivery cron has nothing to send, so its
  last-run age says nothing about health — it now reports a calm N/A ("nothing to
  deliver") instead of FAILED/STALE/UNKNOWN. A blog *with* followers is still
  judged on freshness, so a genuinely broken delivery on an active, followed blog
  still shows red. This clears most of the false-red wall on a quiet fleet.

## 0.7.7 — 2026-08-31

### Fixed
- **API keys read again after the schema-2 profile migration.** The profile store moved
  keys out of the JSON files into the shared credential vault (schema 2); this build
  bundles the schema-2 `snap_profiles` / `snap_creds` that hydrate the key via
  `snap_creds.get_site()`, so all sites authenticate again (the 0.7.6 build shipped the
  stale schema-1 reader and showed "no API key saved" everywhere).

### Added
- **Fleet cron driver.** On every poll CRONOMETER also nudges each site's due crons via
  `multisite/run-crons`, so the fleet's jobs run even with no visitor traffic. Best-effort
  and idempotent. (Carried over from the 0.7.6 attempt, now on a build that authenticates.)

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
