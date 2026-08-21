#!/usr/bin/env python3
"""
SMACKATTACK SCANNER — Linux Chrome/Blink port.

The window is now HTML/CSS/JS drawn by Chromium instead of tkinter. The WORK is the
original Python: config.py (settings INI), db.py (pymysql access), scanner.py (the
25-dimension stylometric vector), and smackattack_core.py (the scan orchestration
factored out of the old tkinter thread). This file only wires those to the page.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import os
import sys
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                     # tools/smackattack-scanner/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")     # tools/_shared/
for p in (SHARED, TOOL_ROOT):
    sys.path.insert(0, os.path.abspath(p))

import snap_blink

import config as cfg_module
import db as db_module
import scanner as scanner_module
import smackattack_core as core

BUILD_VERSION = "0.1.0"

app = snap_blink.App(
    tool="smackattack",
    title="SMACKATTACK SCANNER",
    web_dir=os.path.join(HERE, "web"),
)

# ─── Shared state (mirrors the module globals the tkinter app kept) ────────────

_cfg: dict = {}
_conn = None    # live pymysql connection


def _load_cfg() -> dict:
    global _cfg
    _cfg = cfg_module.load()
    return _cfg


def _try_connect():
    """Open (or reuse) a DB connection from the saved config. Returns conn or None."""
    global _conn
    if _conn is not None:
        try:
            _conn.ping(reconnect=True)
            return _conn
        except Exception:
            _conn = None
    try:
        _conn = db_module.connect(_cfg or _load_cfg())
        return _conn
    except Exception:
        _conn = None
        return None


# ─── API handlers (one per old tkinter action) ────────────────────────────────

@app.api
def load_state():
    """Everything the page needs on open: version + saved settings."""
    cfg = _load_cfg()
    return {
        "version":  BUILD_VERSION,
        "settings": cfg,
        # Which fields are secrets (rendered as password inputs), matching the
        # tkinter `show='•'` fields.
        "secret_fields": ["db_password", "api_key"],
    }


@app.api
def save_and_test(values):
    """SETTINGS → 'SAVE & TEST CONNECTION'. Save the INI, then open + ping the DB."""
    global _cfg, _conn
    cfg = _load_cfg()
    if isinstance(values, dict):
        for k in cfg_module.DEFAULTS:
            if k in values and values[k] is not None:
                cfg[k] = str(values[k]).strip()
    cfg_module.save(cfg)
    _cfg = cfg_module.load()

    if _conn is not None:
        try:
            _conn.close()
        except Exception:
            pass
        _conn = None
    try:
        _conn = db_module.connect(_cfg)
        _conn.ping(reconnect=True)
        return {
            "ok": True,
            "message": f"Connected to {_cfg['db_name']}@{_cfg['db_host']}",
        }
    except Exception as e:
        _conn = None
        return {"ok": False, "message": f"{e}"}


@app.api
def run_scan():
    """
    SCAN → 'RUN SCAN'. Runs the full stylometric scan and returns stats + the whole
    log at once.

    TODO(port): the tkinter version streamed live progress (a moving bar + growing
    log) from a background thread. blink.call is a single request/response, so the
    page shows the complete log when the scan returns rather than line-by-line. The
    scan runs on the server request thread; for very large databases a future version
    could add a polling handler (e.g. scan_progress()) to stream percent/log while it
    runs. All numbers and log lines are preserved — nothing is dropped.
    """
    conn = _try_connect()
    if not conn:
        raise RuntimeError(
            "Not connected to a database. Configure the connection in Settings first."
        )
    threshold = float((_cfg or _load_cfg()).get("threshold", "0.55"))
    min_words = int((_cfg or _load_cfg()).get("min_words", "30"))
    result = core.run_scan(conn, threshold, min_words)
    return result


@app.api
def get_results(filt="all"):
    """RESULTS → the flagged-matches table, honouring the filter radio buttons."""
    conn = _try_connect()
    if not conn:
        return {"rows": [], "count": 0, "connected": False}

    rows = db_module.fetch_stored_results(conn)
    out = []
    for row in rows:
        mt = row.get("match_type", "peer")
        rev = row.get("reviewed", 0)
        sim = float(row.get("similarity", 0.0))

        if filt == "peer" and mt != "peer":
            continue
        if filt == "banned" and mt != "banned":
            continue
        if filt == "unreviewed" and rev == 1:
            continue

        label = scanner_module.similarity_label(sim)
        out.append({
            "id":           row.get("id", 0),
            "similarity":   sim,
            "similarity_pct": f"{sim:.1%}",
            "label":        label,
            "colour":       scanner_module.similarity_colour(sim),
            "match_type":   mt,
            "display_name": (row.get("display_name") or row.get("author_key", "")),
            "author_key":   row.get("author_key", ""),
            "matched_key":  row.get("matched_key", ""),
            "email":        row.get("email", ""),
            "flagged_at":   str(row.get("flagged_at", ""))[:16],
            "reviewed":     int(rev),
        })
    return {"rows": out, "count": len(out), "connected": True}


@app.api
def mark_reviewed(row_id):
    """RESULTS → 'MARK REVIEWED' on the selected row."""
    conn = _try_connect()
    if not conn:
        raise RuntimeError("Not connected to a database.")
    rid = int(row_id or 0)
    if rid:
        db_module.mark_reviewed(conn, rid)
    return {"ok": True}


@app.api
def upload_to_hub(row_id):
    """RESULTS → 'UPLOAD TO HUB' on the selected row (needs api_url + api_key)."""
    cfg = _cfg or _load_cfg()
    api_url = cfg.get("api_url", "").strip()
    api_key = cfg.get("api_key", "").strip()
    if not api_url or not api_key:
        return {
            "ok": False,
            "message": "Enter the hub API URL and key in Settings to enable uploads.",
        }

    conn = _try_connect()
    rid = int(row_id or 0)

    # Pull the row we are reporting so the payload matches the tkinter version.
    row = None
    if conn:
        for r in db_module.fetch_stored_results(conn):
            if r.get("id", 0) == rid:
                row = r
                break
    if row is None:
        return {"ok": False, "message": "Row not found. Refresh and try again."}

    payload = json.dumps({
        "action":      "gobsmacked_report",
        "author_key":  row.get("display_name") or row.get("author_key", ""),
        "matched_key": row.get("matched_key", ""),
        "match_type":  (row.get("match_type", "") or "").upper(),
        "similarity":  f"{float(row.get('similarity', 0.0)):.1%}",
    }).encode()
    req = urllib.request.Request(
        api_url, data=payload,
        headers={"Content-Type": "application/json",
                 "Authorization": f"Bearer {api_key}"},
    )
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            body = json.loads(resp.read())
    except Exception as e:
        return {"ok": False, "message": f"Upload failed: {e}"}

    if body.get("ok"):
        if rid and conn:
            db_module.mark_reviewed(conn, rid)
        return {"ok": True, "message": "Uploaded to hub."}
    return {"ok": False, "message": f"Hub error: {body.get('error', 'unknown')}"}


if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
