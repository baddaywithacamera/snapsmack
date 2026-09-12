"""Atomic SQLite checkpoints for resumable Blogger imports."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import json
import sqlite3
from pathlib import Path


class JobStore:
    def __init__(self, path: str | Path):
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(self.path, timeout=30, check_same_thread=False)
        self.db.row_factory = sqlite3.Row
        self.db.executescript("""
        PRAGMA journal_mode=WAL;
        PRAGMA synchronous=FULL;
        CREATE TABLE IF NOT EXISTS job_meta (
          key TEXT PRIMARY KEY, value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS item_state (
          source_id TEXT PRIMARY KEY,
          source_kind TEXT NOT NULL,
          source_checksum TEXT NOT NULL,
          state TEXT NOT NULL DEFAULT 'pending',
          destination_type TEXT,
          destination_id INTEGER,
          message TEXT NOT NULL DEFAULT '',
          updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS media_state (
          source_url TEXT PRIMARY KEY,
          checksum TEXT,
          local_path TEXT,
          destination_id INTEGER,
          state TEXT NOT NULL DEFAULT 'pending',
          message TEXT NOT NULL DEFAULT '',
          updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        """)
        self.db.commit()

    def close(self):
        self.db.close()

    def set_meta(self, key: str, value):
        encoded = json.dumps(value, ensure_ascii=False, sort_keys=True)
        with self.db:
            self.db.execute("INSERT INTO job_meta(key,value) VALUES(?,?) "
                            "ON CONFLICT(key) DO UPDATE SET value=excluded.value", (key, encoded))

    def get_meta(self, key: str, default=None):
        row = self.db.execute("SELECT value FROM job_meta WHERE key=?", (key,)).fetchone()
        return default if row is None else json.loads(row["value"])

    def seed(self, entries):
        with self.db:
            for item in entries:
                self.db.execute("""
                    INSERT INTO item_state(source_id,source_kind,source_checksum)
                    VALUES(?,?,?) ON CONFLICT(source_id) DO NOTHING
                """, (item.source_id, item.kind, item.source_sha256))

    def state(self, source_id: str):
        row = self.db.execute("SELECT * FROM item_state WHERE source_id=?", (source_id,)).fetchone()
        return dict(row) if row else None

    def mark(self, source_id: str, state: str, *, destination_type=None,
             destination_id=None, message=""):
        with self.db:
            self.db.execute("""
                UPDATE item_state SET state=?, destination_type=COALESCE(?,destination_type),
                  destination_id=COALESCE(?,destination_id), message=?, updated_at=CURRENT_TIMESTAMP
                WHERE source_id=?
            """, (state, destination_type, destination_id, message, source_id))

    def summary(self) -> dict[str, int]:
        rows = self.db.execute("SELECT state,COUNT(*) AS n FROM item_state GROUP BY state")
        return {row["state"]: row["n"] for row in rows}

# ===== SNAPSMACK EOF =====
