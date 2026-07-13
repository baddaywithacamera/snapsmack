"""
SmackPress — config.py
Persistent configuration via SQLite.  All user-facing settings (WordPress
credentials, SnapSmack API key, AI provider, etc.) live here.

# ===== SNAPSMACK EOF =====
"""

import sqlite3
import os
import sys
from pathlib import Path


def _app_dir() -> Path:
    """Portable: state rides next to the .exe when frozen, else next to app.py."""
    if getattr(sys, "frozen", False):
        return Path(sys.executable).resolve().parent
    return Path(__file__).resolve().parent.parent


DB_DIR  = _app_dir()
DB_PATH = DB_DIR / "smackpress.db"

_SCHEMA = """
CREATE TABLE IF NOT EXISTS config (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS posts (
    wp_id           INTEGER PRIMARY KEY,
    wp_slug         TEXT,
    wp_title        TEXT,
    wp_date         TEXT,
    wp_status       TEXT    DEFAULT 'publish',
    snap_post_id    INTEGER DEFAULT NULL,
    snap_url        TEXT    DEFAULT NULL,
    migrated_at     TEXT    DEFAULT NULL,
    hidden_at       TEXT    DEFAULT NULL,
    notes           TEXT    DEFAULT '',
    wp_type         TEXT    DEFAULT 'post'
);

CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(wp_status);
"""

_DEFAULTS = {
    "wp_url":           "",
    "wp_user":          "",
    "wp_app_password":  "",
    "snap_url":         "",
    "snap_api_key":     "",
    "ai_provider":      "none",   # none | gemini | openai | anthropic
    "ai_model":         "",
    "ai_api_key":       "",
    "ai_system_prompt": (
        "You are helping migrate WordPress blog posts to SnapSmack. "
        "Given the original post, rewrite it for a photography-focused audience. "
        "Preserve the author's voice. Output clean prose paragraphs separated by "
        "blank lines. Do not add markdown headers or bullet points."
    ),
    "last_wp_page":     "1",
    "last_wp_status":   "publish",
    "caption_from_filename": "1",
    "window_width":     "1400",
    "window_height":    "900",
}


def _conn() -> sqlite3.Connection:
    DB_DIR.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(DB_PATH)
    con.row_factory = sqlite3.Row
    con.executescript(_SCHEMA)
    # Migrate DBs created before pages support (adds wp_type if absent).
    try:
        con.execute("ALTER TABLE posts ADD COLUMN wp_type TEXT DEFAULT 'post'")
    except sqlite3.OperationalError:
        pass  # column already exists
    con.commit()
    # Seed defaults for any missing keys
    for k, v in _DEFAULTS.items():
        con.execute(
            "INSERT OR IGNORE INTO config (key, value) VALUES (?, ?)", (k, v)
        )
    con.commit()
    return con


def get(key: str) -> str:
    with _conn() as con:
        row = con.execute("SELECT value FROM config WHERE key=?", (key,)).fetchone()
        return row["value"] if row else _DEFAULTS.get(key, "")


def set(key: str, value: str) -> None:
    with _conn() as con:
        con.execute(
            "INSERT INTO config (key, value) VALUES (?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
            (key, str(value)),
        )
        con.commit()


def get_all() -> dict:
    with _conn() as con:
        rows = con.execute("SELECT key, value FROM config").fetchall()
        return {r["key"]: r["value"] for r in rows}


def get_db() -> sqlite3.Connection:
    """Return an open connection for callers that need direct DB access."""
    return _conn()

# ===== SNAPSMACK EOF =====
