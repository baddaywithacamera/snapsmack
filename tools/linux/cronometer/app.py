#!/usr/bin/env python3
"""
CRONOMETER — Linux Chrome/Blink port.

The WINDOW is now HTML/CSS/JS drawn by Chromium (see web/). The WORK is the
original CRONOMETER Python, imported unchanged:

    config.py            — tool prefs + the shared per-site fleet (read-only)
    heartbeat_client.py  — probe() one site's heartbeat -> per-cron-job health

Nothing in the fleet/health logic is rewritten. This file is only the bridge:
it registers the same actions the tkinter window had (REFRESH ALL, RELOAD FLEET,
per-site RE-CHECK) as blink.call handlers and hands the page JSON.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys
from concurrent.futures import ThreadPoolExecutor

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                       # tools/cronometer/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")       # tools/_shared/
for _p in (SHARED, TOOL_ROOT):
    _ap = os.path.abspath(_p)
    if _ap not in sys.path:
        sys.path.insert(0, _ap)

import snap_blink

# The ORIGINAL logic — reused verbatim, no tkinter involved.
import config as cfg_module          # noqa: E402
import heartbeat_client as hb        # noqa: E402


BUILD_VERSION = "0.1.0"

# Shared thread pool so REFRESH ALL polls the fleet off the request thread with the
# same fan-out the tkinter build used (ThreadPoolExecutor(max_workers=6)).
_POOL = ThreadPoolExecutor(max_workers=6)

# Severity -> (hex colour, short word). Mirrors SEV_STYLE in the tkinter build so
# the web board speaks the same visual language. One place maps verdict -> look.
SEV_STYLE = {
    hb.SEV_OK:      ("#4EC994", "OK"),
    hb.SEV_STALE:   ("#D4872A", "STALE"),
    hb.SEV_UNKNOWN: ("#8A8A8A", "UNKNOWN"),
    hb.SEV_FAILED:  ("#FF3E3E", "FAILED"),
    hb.SEV_NA:      ("#777777", "N/A"),
    hb.SEV_OFFLINE: ("#FF3E3E", "OFFLINE"),
}
# Legend order + rollup order, matching the tkinter legend row.
_LEGEND_ORDER = [hb.SEV_OK, hb.SEV_STALE, hb.SEV_UNKNOWN,
                 hb.SEV_FAILED, hb.SEV_OFFLINE, hb.SEV_NA]


app = snap_blink.App(tool="cronometer", title="CRONOMETER",
                     web_dir=os.path.join(HERE, "web"))


# ── serialisers (dataclass -> JSON-safe dict) ────────────────────────────────
def _sev_style(sev):
    return SEV_STYLE.get(sev, ("#8A8A8A", str(sev).upper()))


def _job_dict(jh):
    colour, word = _sev_style(jh.severity)
    return {
        "key": jh.key,
        "label": jh.label,
        "severity": jh.severity,
        "sev_word": word,
        "colour": colour,
        "age_text": jh.age_text(),
        "detail": jh.detail,
        "reported": jh.reported,
    }


def _health_dict(site, health):
    """Turn a SiteHealth into the exact shape the tkinter _render_site painted:
    overall dot, version, a worst-thing summary line, and one row per job."""
    overall = health.overall()
    ocolour, oword = _sev_style(overall)

    if not health.online:
        return {
            "url": site["url"],
            "name": site["name"],
            "online": False,
            "overall": overall,
            "overall_colour": ocolour,
            "version": "",
            "summary": health.error or "offline",
            "summary_colour": _sev_style(hb.SEV_FAILED)[0],
            "jobs": [],
        }

    jobs = [_job_dict(j) for j in health.jobs]
    counts = {}
    for j in health.jobs:
        counts[j.severity] = counts.get(j.severity, 0) + 1
    bits = []
    if counts.get(hb.SEV_FAILED):
        bits.append(f"{counts[hb.SEV_FAILED]} failed")
    if counts.get(hb.SEV_STALE):
        bits.append(f"{counts[hb.SEV_STALE]} stale")
    if counts.get(hb.SEV_UNKNOWN):
        bits.append(f"{counts[hb.SEV_UNKNOWN]} not reported")
    summary = ", ".join(bits) if bits else "all jobs healthy"

    return {
        "url": site["url"],
        "name": site["name"],
        "online": True,
        "overall": overall,
        "overall_colour": ocolour,
        "overall_word": oword,
        "version": health.version or "",
        "summary": summary,
        "summary_colour": ocolour,
        "jobs": jobs,
    }


def _fleet_headline(healths):
    """Roll every site's overall verdict up to one fleet headline (worst wins),
    same idea as _fleet_headline in the tkinter build."""
    worst = hb.SEV_NA
    for h in healths:
        worst = hb.worst([worst, h.get("overall", hb.SEV_UNKNOWN)])
    return f"worst: {_sev_style(worst)[1]}"


# ── the actions (one per tkinter control) ────────────────────────────────────
@app.api
def load_state():
    """Everything the page needs on open: prefs, the fleet, the job catalogue and
    the legend. Mirrors __init__ + _load_fleet + the legend build."""
    prefs = cfg_module.load()
    fleet = cfg_module.load_fleet()
    return {
        "build": BUILD_VERSION,
        "timeout": int(prefs.get("poll_timeout", cfg_module.DEFAULT_POLL_TIMEOUT)),
        "prefs": prefs,
        "fleet": fleet,
        "job_specs": [{"key": s.key, "label": s.label} for s in hb.JOB_SPECS],
        "legend": [{"sev": s, "colour": _sev_style(s)[0], "word": _sev_style(s)[1]}
                   for s in _LEGEND_ORDER],
    }


@app.api
def reload_fleet():
    """RELOAD FLEET — re-read the shared per-site profiles and return the fleet."""
    return {"fleet": cfg_module.load_fleet()}


@app.api
def probe_site(site, timeout=None):
    """RE-CHECK one site. `site` is the {name,url,api_key} dict the page holds.
    Never raises — probe() folds every failure into an offline SiteHealth."""
    t = int(timeout) if timeout else int(
        cfg_module.load().get("poll_timeout", cfg_module.DEFAULT_POLL_TIMEOUT))
    try:
        health = hb.probe(site, t)
    except Exception as e:  # probe() shouldn't raise, but never break the window
        health = hb.SiteHealth(name=site.get("name", ""), url=site.get("url", ""),
                               online=False, error=f"internal error: {e}")
    return _health_dict(site, health)


@app.api
def probe_all(sites=None, timeout=None):
    """REFRESH ALL — poll the whole board. Fans out across the shared pool the same
    way refresh_all() did, and returns a result per site plus a fleet headline."""
    if sites is None:
        sites = cfg_module.load_fleet()
    t = int(timeout) if timeout else int(
        cfg_module.load().get("poll_timeout", cfg_module.DEFAULT_POLL_TIMEOUT))

    def _one(s):
        try:
            return _health_dict(s, hb.probe(s, t))
        except Exception as e:
            h = hb.SiteHealth(name=s.get("name", ""), url=s.get("url", ""),
                              online=False, error=f"internal error: {e}")
            return _health_dict(s, h)

    results = list(_POOL.map(_one, list(sites))) if sites else []
    return {
        "results": results,
        "headline": _fleet_headline(results),
        "count": len(results),
    }


@app.api
def save_prefs(prefs):
    """Persist tool prefs (poll timeout). The tkinter build also saved window
    geometry on close; a Blink --app window is sized by Chromium, so there is no
    geometry to persist here.
    TODO(port): if a future spec wants a remembered window size, capture it from
    the page (window.outerWidth/Height) and stash it in prefs['win_geometry']."""
    cur = cfg_module.load()
    cur.update(prefs or {})
    cfg_module.save(cur)
    return {"ok": True, "prefs": cfg_module.load()}


if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
