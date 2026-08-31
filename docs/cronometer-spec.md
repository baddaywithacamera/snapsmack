<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# CRONOMETER — fleet cron / job-health console

**Status:** desktop tool, first cut shipped at `0.1.0` (tools/cronometer/).
**Name:** a *cron* + *chronometer* pun — it times the crons.
**Category:** INSURANCE. The whole reason it exists is to surface a **silently-dead
cron before it bites** — the "backups went red weeks ago and nobody noticed" case.

---

## 1. What it does

One dark desktop board. For every site in the fleet it shows, per scheduled job,
**last-run + ok/stale/failed**, as a red / amber / grey / green light:

Only the scheduled crons EVERY site actually runs are monitored:

| Job | Cron script | Documented cadence |
|-----|-------------|--------------------|
| Fediverse delivery | `cron-fediverse.php` | every 10 min |
| RSS blogroll fetch | `cron-rss-fetch.php` | ~hourly |
| Version / update check | `cron-version-check.php` | every 6 h |

**Deliberately NOT monitored** (they are not per-site crons, and showing them
produced false red/grey rows): **Backups** — done by the SUYB desktop tool, not a
cron; **SMACKBACK** — rides `cron-version-check.php`, not its own job; **directory
feeds** (`cron-directory-feeds.php`) — runs on the photoblogs.fyi host only, not on
spokes. Backup freshness is still in the heartbeat (`last_backup_at`) if a separate,
clearly non-cron indicator is ever wanted.

- **Fleet source:** the ONE shared per-site profile store,
  `tools/_shared/snap_profiles` (files under
  `C:\snapsmack\shared_library\profiles\<site>.json`). A blog set up in SYBU / GYSS /
  COLD SNAP appears here automatically — no keys re-typed. Read-only.
- **Probe:** `GET multisite/heartbeat` with the site's saved Bearer key, one request
  per site, off the UI thread (`ThreadPoolExecutor`), results marshalled back with
  Tk `after`.
- **Controls:** per-site **RE-CHECK**, whole-board **REFRESH ALL**, **RELOAD FLEET**
  (re-reads profiles). An initial sweep fires on open.
- **Honest degradation (critical):** see §4. Where the heartbeat doesn't carry a
  job's real last-run, the row is an explicit grey **"not reported"** caution —
  never a fabricated green.

### Severity ladder

`offline` (red) > `failed` (red) > `stale` (amber) > `unknown` (grey) > `ok`
(green) > `na` (dim). A site's overall dot is the worst of its job rows; an
unreachable heartbeat paints the whole card red — a dead probe *is* the signal.

Staleness per job (in `heartbeat_client.JOB_SPECS`): **ok** while
`age ≤ 2 × cadence`; **stale** up to a per-job red cutoff; **failed** beyond it, or
whenever the site reports an explicit `failed` / `breach` status.

---

## 2. Files (tools/cronometer/)

| File | Role |
|------|------|
| `cronometer.py` | Entry. `_shared` bootstrap + debug log, loads the fleet, builds the Tk health board, threaded polling. `BUILD_VERSION = "0.1.0"`. |
| `heartbeat_client.py` | The probe + the honest-degradation layer. Turns a heartbeat reply into per-job `JobHealth`. No Tk. |
| `config.py` | Shared config.ini (poll timeout / geometry) via `snap_home`; `load_fleet()` from `snap_profiles`. All `_shared` access read-only. |
| `bump_version.py` | +1 patch to `BUILD_VERSION`, CHANGELOG stub. Cloned from COLD SNAP. |
| `build.bat` | `pyinstaller --clean cronometer.spec --distpath "C:\snapsmack\cronometer"`. |
| `cronometer.spec` | Self-bundling recipe (every local `.py` + `_shared/*.py` forced in). Entry = `cronometer.py`. |
| `requirements.txt` | `requests`, `pyinstaller`. (No Pillow — no imaging.) |
| `CHANGELOG.md` | Family convention; fresh at 0.1.0. |

Every source file ends with the `SNAPSMACK EOF` sentinel. Reuses `_shared`
(`snap_home`, `snap_profiles`, `snap_creds`, `snap_stepup`) strictly read-only.

---

## 3. Conventions honoured

- `_add_shared_to_path()` bootstrap copied verbatim from COLD SNAP.
- Config + logs under the shared `C:\snapsmack` home (`snap_home.config_path` /
  `log_path`), with legacy next-to-exe adoption.
- Dark admin palette mirrors `sumna_ui.py`.
- Transport guard: never send a Bearer key over plaintext `http://` (except
  localhost) — reuses `snap_stepup.insecure_transport_reason`, fail-closed fallback.
- Windows-only desktop tool; no mobile concern.

---

## 4. Server API — EXISTS vs MUST-ADD  ⚠️ the load-bearing section

**Verified by opening the files.** The current heartbeat reports site **STATE**, not
a per-cron-job last-run/health block — so **most jobs cannot be shown accurately
yet**. CRONOMETER is built to consume a *richer* heartbeat and degrade honestly
until it exists.

### 4.1 Endpoint & auth — EXISTS

- Route dispatch `api.php?route=multisite/heartbeat` → **`api.php:7`** (doc) and the
  `/api/multisite/*` router at **`api.php:33`**.
- Endpoint handler: **`core/multisite-api.php:400`**
  `if ($resource === 'heartbeat' && $method === 'GET')`.
- Bearer-key auth for multisite write/read endpoints: **`core/multisite-api.php:6`**
  (doc) and the `Authorization: Bearer` header helper at
  **`core/multisite-api.php:74`** (`ms_get_auth_header`).
- Client call shape: `GET {site}/api.php?route=multisite/heartbeat`,
  `Authorization: Bearer <profile api_key>` — `heartbeat_client._heartbeat_url()`.

### 4.2 Fields the heartbeat returns TODAY — EXISTS

All in the `ms_ok([...])` response, **`core/multisite-api.php:456`–`481`**:

| Field | Line | Used by CRONOMETER for |
|-------|------|------------------------|
| `version` | 457 | site header `v…` |
| `last_backup_at` | 462 | **Backups** last-run |
| `last_backup_status` (`ok`/`failed`/`unknown`) | 465 | **Backups** verdict |
| `smackback_status` (`clean`/`breach`/`unknown`) | 469 | **SMACKBACK** verdict |
| `smackback_breach_at` | 470 | **SMACKBACK** breach time |
| `fediverse_enabled` | 474 | **Fediverse** enabled vs N/A |
| `fediverse_followers…replies` | 475–479 | (context; not health) |
| `timestamp` | 480 | probe freshness |

Backing columns confirmed in `database/schema/snapsmack_canonical.sql`:
`last_backup_at/size/dest/status` **lines 779–782**, `smackback_status` /
`smackback_breach_at` **786–789** (these are the hub-side cache columns in
`snap_multisite_nodes`; the heartbeat itself reads the same values from
`snap_settings`).

**⇒ Only two jobs render accurately today: Backups and (breach-only) SMACKBACK.**

### 4.3 What the crons DO record — but the heartbeat does NOT expose

The last-run markers exist in `snap_settings`, they're simply **not in the
heartbeat response**:

| Job | Marker written | Where (verified) | In heartbeat? |
|-----|----------------|------------------|---------------|
| Version check | `last_update_check` | `cron-version-check.php:122` | **No** |
| Fediverse delivery | `fediverse_cron_last_run` | `cron-fediverse.php:126` | **No** |
| SMACKBACK full verify | `smackback_last_full_verify` | `cron-version-check.php:153` | **No** (only the pass/fail status is) |
| RSS fetch | `rss_last_fetched` **per blogroll peer** (`snap_blogroll`), `cron-rss-fetch.php:54`–`58` | — | **No**, and there is **no fleet-level RSS marker at all** |

### 4.4 MUST-ADD (server side, by the CMS dev — not done here)

CRONOMETER already consumes an **optional `jobs` block**; add it and the grey rows
light up with zero client change. All additions go in the `ms_ok([...])` array at
**`core/multisite-api.php:456`**:

```jsonc
// GET multisite/heartbeat — proposed ADDITIVE block (back-compatible)
"jobs": {
  "fediverse":     { "last_run": "2026-08-14T09:30:00+00:00", "status": "ok",     "detail": "queue drained" },
  "rss_fetch":     { "last_run": "2026-08-14T09:00:00+00:00", "status": "ok",     "detail": "12 peers" },
  "version_check": { "last_run": "2026-08-14T06:00:00+00:00", "status": "ok" },
  "backup":        { "last_run": "2026-08-13T02:00:00+00:00", "status": "ok" },
  "smackback":     { "last_run": "2026-08-14T06:00:00+00:00", "status": "clean" }
}
```

Each entry: `last_run` (ISO-8601 / `date('c')`), `status`
(`ok|failed|stale|clean|breach`), optional `detail`. Sourcing on the server:

- `fediverse.last_run` ← `fediverse_cron_last_run` (already stored).
- `version_check.last_run` ← `last_update_check` (already stored).
- `smackback.last_run` ← `smackback_last_full_verify`; `status` ← existing
  `smackback_status`.
- `backup` ← existing `last_backup_at` / `last_backup_status` (fold in for
  uniformity).
- **`rss_fetch` needs a NEW marker first:** have `cron-rss-fetch.php` upsert a
  `rss_last_run` (and optionally `rss_last_status`) into `snap_settings` at the end
  of its sweep — today it only stamps per-peer `rss_last_fetched`
  (`cron-rss-fetch.php:54`–`58`), so there is nothing fleet-level to report.

Optional, for a hub-side board (not required by this desktop tool): mirror the
per-job last-run into `snap_multisite_nodes` (schema **759–812** has **no** per-cron
columns today) so the hub caches job health the way it already caches
`last_backup_*` / `fediverse_*`.

**Client contract while MUST-ADD is pending:** absent `jobs`, rows for
`rss_fetch` / `version_check` show grey **"not reported by this heartbeat"**;
`fediverse` shows grey **"enabled, but last-run not reported"** (or dim **N/A** when
`fediverse_enabled = 0`); `backup` and `smackback` render from the existing fields.

---

## 5. Build & run

```
cd tools\cronometer
build.bat            REM bumps patch, bundles, outputs C:\snapsmack\cronometer\cronometer.exe
build.bat norev      REM rebuild current version (debug)
```

Dev run: `python cronometer.py` (needs `tools/_shared` reachable one level up, as in
the repo layout, for a non-empty fleet). Ships **inside an install only**, never
hosted on the net.

---

## 6. Deferred / not in 0.1.0

- Auto-refresh timer (config key `auto_refresh` reserved; 0 = manual only).
- Per-job drill-down / history sparkline.
- Hub-mode (read the hub's cached `snap_multisite_nodes` directly instead of
  polling each spoke) — depends on the optional §4.4 node columns.
- Desktop notification / tray alert when a green job first turns red.

<!-- ===== SNAPSMACK EOF ===== -->
