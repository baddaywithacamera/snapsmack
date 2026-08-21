#!/usr/bin/env python3
"""
SHOTS FIRED — Linux Chrome/Blink port.

The WINDOW is now HTML/CSS/JS (drawn by Chromium via snap_blink) instead of
tkinter. The WORK is the ORIGINAL Python, unchanged: the shared-library fleet
loader (fleet.py), the per-spoke scheduling HTTP client (schedule_client.py) and
the tiny prefs file (config.py) are imported straight from the tool root. Nothing
about how SHOTS FIRED talks to a site was rewritten — only the widgets it used to
draw with tkinter (main.py / ui.py / agenda.py) are replaced by web controls that
call back into these same functions through blink.call().

Feature parity vs the tkinter version:
    - REFRESH (with the LOOK AHEAD selector: 14/30/60/90/180/365 days)
    - the day-grouped agenda of every fleet site's future-dated posts
    - per-site colour swatches + per-site status notes (no key / not deployed / …)
    - the MOVE… reschedule flow (new date + new time, write img_date back)
    - the running status summary line

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import datetime
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                        # tools/shots-fired/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")        # tools/_shared/
for _p in (SHARED, TOOL_ROOT):
    _abs = os.path.abspath(_p)
    if _abs not in sys.path:
        sys.path.insert(0, _abs)

import snap_blink

# The ORIGINAL, tkinter-free logic modules — imported, never rewritten.
import config as cfg_module
import fleet as fleet_module
from schedule_client import (ApiStatus, ScheduledPost, list_scheduled,
                             reschedule)

BUILD_VERSION = "0.1.0"
LOOKAHEAD_CHOICES = [14, 30, 60, 90, 180, 365]

app = snap_blink.App(tool="shots-fired", title="SHOTS FIRED",
                     web_dir=os.path.join(HERE, "web"))


# ---------------------------------------------------------------------------
# serialisation helpers (dataclass -> JSON the page can render)
# ---------------------------------------------------------------------------

def _post_to_dict(p: ScheduledPost) -> dict:
    """Flatten one ScheduledPost for the page. The agenda groups by day and picks
    a per-site colour in JS, so send the raw pieces it needs."""
    return {
        "snap_id":   p.snap_id,
        "title":     p.title or "",
        "site_name": p.site_name,
        "site_url":  p.site_url,
        "iso":       p.img_date.strftime("%Y-%m-%dT%H:%M:%S"),
        "date":      p.img_date.strftime("%Y-%m-%d"),
        "time":      p.img_date.strftime("%H:%M"),
        "thumb_url": p.thumb_url,
        "post_id":   p.post_id,
    }


def _clamp_days(days) -> int:
    try:
        return max(1, int(days))
    except (TypeError, ValueError):
        return 60


def _parse_local(date_s: str, time_s: str):
    """Mirror of agenda._parse_local — turn the dialog's date+time fields into a
    datetime, or None if the operator typed something unparseable."""
    date_s = (date_s or "").strip()
    time_s = (time_s or "").strip() or "00:00"
    for tfmt in ("%H:%M", "%H:%M:%S"):
        try:
            return datetime.datetime.strptime(f"{date_s} {time_s}", f"%Y-%m-%d {tfmt}")
        except ValueError:
            continue
    return None


# ---------------------------------------------------------------------------
# API handlers — one per tkinter action
# ---------------------------------------------------------------------------

@app.api
def load_state():
    """Everything the page needs on open: the saved look-ahead + the choice list.
    Replaces App.__init__'s combobox seeding."""
    cfg = cfg_module.load()
    return {
        "version":            BUILD_VERSION,
        "lookahead_days":     _clamp_days(cfg.get("lookahead_days", 60)),
        "lookahead_choices":  LOOKAHEAD_CHOICES,
    }


@app.api
def refresh(days=60):
    """Enumerate the fleet, pull each site's upcoming queue, and return the flat
    agenda + per-site notes. Replaces App.refresh / App._load_worker / _apply_load.
    Saves the chosen look-ahead exactly like the tkinter version did."""
    days = _clamp_days(days)

    cfg = cfg_module.load()
    cfg["lookahead_days"] = days
    cfg_module.save(cfg)

    posts = []
    notes = []
    site_count = 0
    try:
        fleet = fleet_module.load_fleet()
        site_count = len(fleet)
        if not fleet:
            notes.append("No sites configured yet — set a blog up in SYBU or "
                         "COLD SNAP and it appears here.")
        for site in fleet:
            res = list_scheduled(site, lookahead_days=days)
            if res.status is ApiStatus.OK:
                posts.extend(_post_to_dict(p) for p in res.posts)
                if not res.posts:
                    notes.append(f"{site.display} — no scheduled posts")
            elif res.status is ApiStatus.NOT_DEPLOYED:
                notes.append(f"{site.display} — no scheduling API yet "
                             f"(server route not deployed)")
            elif res.status is ApiStatus.UNAUTHORIZED:
                notes.append(f"{site.display} — {res.message}")
            else:
                notes.append(f"{site.display} — {res.message}")
    except Exception as e:
        notes.append(f"load failed: {e}")

    posts.sort(key=lambda d: d["iso"])
    return {
        "posts":          posts,
        "notes":          notes,
        "site_count":     site_count,
        "lookahead_days": days,
    }


@app.api
def reschedule_post(site_url, snap_id, date_s, time_s):
    """Write a new img_date for one post. Replaces the MOVE dialog + App._handle_
    reschedule / _reschedule_worker / _apply_reschedule. The page passes the site
    the post belongs to (by url) plus the typed new date/time.

    Returns {ok, status, message}. status mirrors ApiStatus so the page can show
    the same 'no scheduling API yet' warning the tkinter messagebox showed."""
    new_dt = _parse_local(date_s, time_s)
    if new_dt is None:
        return {"ok": False, "status": "error",
                "message": "Enter a valid date (YYYY-MM-DD) and time (HH:MM)."}

    # Match the post back to a fleet Site by url (App._site_for).
    site = None
    try:
        for s in fleet_module.load_fleet():
            if s.url == site_url:
                site = s
                break
    except Exception as e:
        return {"ok": False, "status": "error", "message": f"fleet load failed: {e}"}
    if site is None:
        return {"ok": False, "status": "error",
                "message": "Could not match this post to a fleet site."}

    res = reschedule(site, int(snap_id), new_dt)
    ok = res.status is ApiStatus.OK
    msg = res.message
    if res.status is ApiStatus.NOT_DEPLOYED:
        msg = ("This site does not have the reschedule endpoint deployed yet, so "
               "the post could not be moved. (Server MUST-ADD route: "
               "POST smack-schedule.php?action=set_date — see the SHOTS FIRED spec.)")
    return {
        "ok":       ok,
        "status":   res.status.value,
        "message":  msg,
        "new_when": new_dt.strftime("%Y-%m-%d %H:%M"),
    }


if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
