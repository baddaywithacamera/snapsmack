"""
SNAPSMACK — snap_library.py  (per-site offline catalog mirror, SQLite)

The offline archive COLD SNAP needs so it can enrich WITHOUT a live site call —
the blind spot that would otherwise bite exactly as it did GYSS. A tool fetches a
site's catalog ONCE while online (sybu-data.php already returns categories, albums,
tags, titles and site_mode) and stores it here; every later enrich/sort reads it
offline.

One SQLite file per site under the shared library:
    C:\\snapsmack\\shared_library\\<site>\\db\\catalog.sqlite

SQLite is public-domain and in Python's stdlib — the one on-disk format both the
Python tools and the Rust/Tauri tools open natively, no dependency, no licence.

    import snap_library as L
    L.sync_from_sybu_data(site_url, payload_dict_from_sybu_data_php)   # online, occasional
    cats  = L.categories(site_url)     # offline, at enrich time
    albums= L.albums(site_url)
    mode  = L.site_mode(site_url)

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sqlite3
import time

import snap_home


_SCHEMA = """
CREATE TABLE IF NOT EXISTS meta       (key TEXT PRIMARY KEY, val TEXT);
CREATE TABLE IF NOT EXISTS categories (name TEXT PRIMARY KEY, description TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS albums     (name TEXT PRIMARY KEY, description TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS tags       (tag  TEXT PRIMARY KEY);
CREATE TABLE IF NOT EXISTS titles     (title TEXT PRIMARY KEY);
"""


def _db_path(site: str) -> str:
    return os.path.join(snap_home.site_db_dir(site), "catalog.sqlite")


def _connect(site: str) -> sqlite3.Connection:
    conn = sqlite3.connect(_db_path(site))
    conn.executescript(_SCHEMA)
    return conn


# ── Write (the "librarian" — call while ONLINE) ──────────────────────────────
def sync_from_sybu_data(site: str, payload: dict) -> dict:
    """Replace this site's cached catalog from a sybu-data.php JSON payload
    (categories/albums/tags/titles/site_mode). Wholesale replace inside one
    transaction — the endpoint returns the full current set, so this also prunes
    anything deleted server-side. Returns a small count summary."""
    payload = payload or {}
    cats   = payload.get("categories", []) or []
    albums = payload.get("albums", []) or []
    tags   = [t for t in (payload.get("tags", []) or []) if str(t).strip()]
    titles = [t for t in (payload.get("titles", []) or []) if str(t).strip()]
    mode   = str(payload.get("site_mode", "") or "").strip().lower()

    conn = _connect(site)
    try:
        with conn:                                   # one atomic transaction
            conn.execute("DELETE FROM categories")
            conn.execute("DELETE FROM albums")
            conn.execute("DELETE FROM tags")
            conn.execute("DELETE FROM titles")
            conn.executemany(
                "INSERT OR REPLACE INTO categories(name, description) VALUES (?, ?)",
                [(str(c.get("name", "")), str(c.get("description", "") or "")) for c in cats if c.get("name")],
            )
            conn.executemany(
                "INSERT OR REPLACE INTO albums(name, description) VALUES (?, ?)",
                [(str(a.get("name", "")), str(a.get("description", "") or "")) for a in albums if a.get("name")],
            )
            conn.executemany("INSERT OR REPLACE INTO tags(tag) VALUES (?)", [(str(t),) for t in tags])
            conn.executemany("INSERT OR REPLACE INTO titles(title) VALUES (?)", [(str(t),) for t in titles])
            conn.execute("INSERT OR REPLACE INTO meta(key, val) VALUES ('site_url', ?)", (str(site),))
            if mode:
                conn.execute("INSERT OR REPLACE INTO meta(key, val) VALUES ('site_mode', ?)", (mode,))
            conn.execute("INSERT OR REPLACE INTO meta(key, val) VALUES ('synced_at', ?)",
                         (time.strftime("%Y-%m-%d %H:%M:%S"),))
        return {"categories": len(cats), "albums": len(albums),
                "tags": len(tags), "titles": len(titles), "site_mode": mode}
    finally:
        conn.close()


# ── Read (offline, at enrich time) ───────────────────────────────────────────
def is_synced(site: str) -> bool:
    """True if a catalog has been fetched for this site."""
    return os.path.isfile(_db_path(site))


def _col(site: str, sql: str) -> list:
    if not os.path.isfile(_db_path(site)):
        return []
    conn = _connect(site)
    try:
        return [r[0] for r in conn.execute(sql).fetchall()]
    finally:
        conn.close()


def categories(site: str) -> list:
    return _col(site, "SELECT name FROM categories ORDER BY name COLLATE NOCASE")


def albums(site: str) -> list:
    return _col(site, "SELECT name FROM albums ORDER BY name COLLATE NOCASE")


def tags(site: str) -> list:
    return _col(site, "SELECT tag FROM tags ORDER BY tag COLLATE NOCASE")


def titles(site: str) -> list:
    return _col(site, "SELECT title FROM titles ORDER BY title COLLATE NOCASE")


def _descs(site: str, table: str) -> dict:
    if not os.path.isfile(_db_path(site)):
        return {}
    conn = _connect(site)
    try:
        return {name.lower(): (desc or "")
                for name, desc in conn.execute(f"SELECT name, description FROM {table}").fetchall()}
    finally:
        conn.close()


def category_descriptions(site: str) -> dict:
    return _descs(site, "categories")


def album_descriptions(site: str) -> dict:
    return _descs(site, "albums")


def meta(site: str, key: str, default: str = "") -> str:
    if not os.path.isfile(_db_path(site)):
        return default
    conn = _connect(site)
    try:
        row = conn.execute("SELECT val FROM meta WHERE key = ?", (key,)).fetchone()
        return row[0] if row else default
    finally:
        conn.close()


def site_mode(site: str) -> str:
    return meta(site, "site_mode", "")


def synced_at(site: str) -> str:
    return meta(site, "synced_at", "")
# ===== SNAPSMACK EOF =====
