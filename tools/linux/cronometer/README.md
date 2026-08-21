<!--
  CRONOMETER — Linux Chrome/Blink port README.
-->

# CRONOMETER — Linux (Chrome/Blink) port

CRONOMETER is the fleet cron / job-health board. Per fleet site it shows the health
of the scheduled jobs — Fediverse delivery, RSS blogroll fetch, version/update
check, Backups, SMACKBACK integrity — as coloured dots (green OK / amber STALE /
grey UNKNOWN / red FAILED / dim N/A / red OFFLINE), so a silently-dead cron is
caught before it bites. A job the site can't yet report is shown as an explicit
grey "not reported", never a fabricated green.

This is the **Linux port**: the window is drawn by Chromium (the Blink engine) via
the shared `snap_blink` runtime instead of tkinter. The actual work is the original
CRONOMETER Python, imported unchanged.

## What was reused (not rewritten)

- `../config.py` — tool prefs + the shared per-site **fleet** (read-only, from
  `tools/_shared/snap_profiles`). Any blog set up in SYBU / GYSS / COLD SNAP shows
  up here with no re-typing.
- `../heartbeat_client.py` — `probe()` one site's heartbeat and turn the reply into
  a per-cron-job verdict. All severity logic, honest-degradation rules and the job
  catalogue (`JOB_SPECS`) are the originals.

`app.py` is only the bridge: it registers the same actions as `blink.call` handlers
and serialises the `SiteHealth` / `JobHealth` dataclasses to JSON for the page. No
`cronometer_core.py` was needed — the tkinter build already kept the work in
`config.py` and `heartbeat_client.py`, with zero logic tangled into the window.

## Run it (Linux)

```
cd tools/cronometer/linux
pip install -r requirements.txt        # just: requests
./run.sh
```

`run.sh` sets `SNAPSMACK_HOME` (defaults to `~/snapsmack`), puts `tools/_shared`
and `tools/cronometer` on `PYTHONPATH`, and launches `app.py`. `snap_blink` starts
a localhost-only server on a random port and opens a Chromium `--app` window; close
the window to quit. If no Chromium/Chrome is installed it prints a URL to open in a
normal browser tab instead.

## Feature parity vs the tkinter build

| tkinter control / behaviour            | Blink port                                        |
|----------------------------------------|---------------------------------------------------|
| REFRESH ALL button                     | `#btn-refresh` → `blink.call('probe_all')` (same 6-wide thread pool fan-out) |
| RELOAD FLEET button                    | `#btn-reload` → `reload_fleet` then re-probe       |
| Per-site RE-CHECK button               | per-card `.recheck` → `probe_site`                 |
| Severity legend row                    | `#legend`, built from `load_state().legend`        |
| Per-site card: dot, name, version, summary | `.card` head, painted by `paintCard`           |
| Per-job rows (dot / label / state / age / detail) | `.job` grid rows, one per `JOB_SPECS` entry |
| "checking…" amber while polling        | `markChecking()` + per-card amber summary          |
| Status line + fleet headline (worst wins) | `#status`, set from `probe_all` headline        |
| Empty-fleet guidance panel             | `.empty` message in `renderBoard`                  |
| Initial auto-sweep on open (`after(200)`) | `setTimeout(refreshAll, 200)` in `boot`         |
| Shared prefs/fleet from `_shared`      | unchanged — `config.py` reads the same shared home |

### TODO(port)

- **Window geometry.** The tkinter build saved `win_geometry` on close and restored
  it. A Blink `--app` window is sized by Chromium (`--window-size` in `snap_blink`),
  so there is nothing to restore. `save_prefs` still persists `poll_timeout`; if a
  future spec wants a remembered size, capture `window.outerWidth/Height` from the
  page and stash it in `prefs['win_geometry']`.

## Honesty

Ported and imports verified (`ast.parse` clean on `app.py`). **Not yet run on Linux
hardware** — this build box has no Linux Chromium to launch.

<!-- ===== SNAPSMACK EOF ===== -->
