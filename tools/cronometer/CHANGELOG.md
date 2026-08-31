<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# CRONOMETER — changelog

CRONOMETER (a cron + chronometer pun) is the fleet cron / job-health console: one
board that shows, per SnapSmack site, whether the scheduled jobs (RSS fetch,
version check, fediverse delivery, backups, SMACKBACK integrity) are running —
red / amber / grey / green — so a silently-dead cron is caught before it bites.

Versioning matches the family convention: fresh start at 0.1.0, `bump_version.py`
adds +1 patch each build. Never bump the minor/major by hand.

## Unreleased — 2026-08-31

### Changed
- Monitor only the scheduled crons every SnapSmack site actually runs — **fediverse
  delivery, RSS blogroll fetch, version/update check**. Removed **Backups** and
  **SMACKBACK** from the job catalogue: backups are the SUYB desktop tool (there is
  no backup cron) and SMACKBACK rides `cron-version-check.php`, so both produced
  false red/grey rows. Backup freshness is still in the heartbeat (`last_backup_at`)
  if a separate, clearly non-cron indicator is wanted later.
- Server pairing: `multisite/heartbeat` now ships the matching
  `{fediverse, rss_fetch, version_check}` jobs block (SnapSmack 0.7.595D), so those
  three light up green/amber/red from real last-run data instead of grey "never".
- Run `bump_version.py` on rebuild to stamp the build number.

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
