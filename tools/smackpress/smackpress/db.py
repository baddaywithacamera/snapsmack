"""
SmackPress — db.py
Local SQLite helpers for tracking migration state.

# ===== SNAPSMACK EOF =====
"""

from __future__ import annotations
import sqlite3
from datetime import datetime, timezone
from typing import Any

import config


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

# ===== SNAPSMACK EOF =====
