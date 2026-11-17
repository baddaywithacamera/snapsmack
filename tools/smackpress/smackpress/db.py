# SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
"""
SmackPress — db.py
Local SQLite helpers for tracking migration state.

# ===== SNAPSMACK EOF =====
"""

from __future__ import annotations
import sqlite3
from datetime import datetime, timezone
from typing import Any

from smackpress import config   # explicit: tools/coldsnap on sys.path also has a `config`


def _con() -> sqlite3.Connection:
    return config.get_db()


# --------------------------------------------------------------------------
# Post tracking
# --------------------------------------------------------------------------

def upsert_post(wp_id: int, **fields) -> None:
    """Insert or update a post tracking record."""
    con   = _con()
    cols  = ["wp_id"] + list(fields.keys())
    vals  = [wp_id] + list(fields.values())
    ph    = ", ".join(["?"] * len(vals))
    names = ", ".join(cols)
    updates = ", ".join(f"{k}=excluded.{k}" for k in fields)
    con.execute(
        f"INSERT INTO posts ({names}) VALUES ({ph}) "
        f"ON CONFLICT(wp_id) DO UPDATE SET {updates}",
        vals,
    )
    con.commit()


def get_post(wp_id: int) -> sqlite3.Row | None:
    return _con().execute(
        "SELECT * FROM posts WHERE wp_id=?", (wp_id,)
    ).fetchone()


def mark_migrated(wp_id: int, snap_post_id: int, snap_url: str) -> None:
    now = datetime.now(timezone.utc).isoformat()
    upsert_post(wp_id,
                snap_post_id=snap_post_id,
                snap_url=snap_url,
                migrated_at=now)


def mark_hidden(wp_id: int) -> None:
    now = datetime.now(timezone.utc).isoformat()
    upsert_post(wp_id, hidden_at=now)


def get_all_posts(status_filter: str | None = None) -> list[sqlite3.Row]:
    con = _con()
    if status_filter:
        return con.execute(
            "SELECT * FROM posts WHERE wp_status=? ORDER BY wp_date DESC",
            (status_filter,)
        ).fetchall()
    return con.execute("SELECT * FROM posts ORDER BY wp_date DESC").fetchall()


def set_note(wp_id: int, note: str) -> None:
    upsert_post(wp_id, notes=note)


def add_note_lines(wp_id: int, lines) -> int:
    """APPEND notes without losing what is already there, and without
    repeating a line that is already recorded.

    set_note() overwrites. The link report runs on every import and on every
    repair, so overwriting would mean the last run's findings replaced the
    previous run's -- and a note nobody can accumulate is a note that loses
    the thing it was written down for. Returns how many lines were added.
    """
    lines = [str(line).strip() for line in (lines or []) if str(line).strip()]
    if not lines:
        return 0
    row = get_post(wp_id)
    existing = ""
    if row is not None:
        try:
            existing = (row["notes"] or "")
        except (KeyError, IndexError, TypeError):
            existing = ""
    have = {line.strip() for line in existing.splitlines() if line.strip()}
    fresh = []
    for line in lines:
        if line not in have:
            fresh.append(line)
            have.add(line)
    if not fresh:
        return 0
    joined = ("\n".join([existing.rstrip()] + fresh) if existing.strip()
              else "\n".join(fresh))
    upsert_post(wp_id, notes=joined)
    return len(fresh)

# ===== SNAPSMACK EOF =====
