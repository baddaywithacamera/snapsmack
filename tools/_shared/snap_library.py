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

Content mirror (posts + assets + originals) — the producer/consumer store COLD SNAP
composes long-form against and every posting tool feeds on post-success
(shared-library-post-cache-producer spec). Media originals live beside thumbs at
shared_library/<site>/media, named by sha256:

    a = L.store_media(site_url, "C:/exports/DSC001.jpg", alt="…")   # copy into media/
    L.record_post(site_url, {"post_id": 1234, "post_type": "long",
                             "title": "…", "body": "…[mosaic:9]…"}, assets=[a])
    for p in L.posts(site_url, site_mode="smacktalk"):              # offline read
        imgs = L.assets_for(site_url, p["post_id"])
        path = L.asset_file(site_url, imgs[0]["asset_id"])

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import hashlib
import json
import os
import shutil
import sqlite3
import time

import snap_home
from snap_paths import contained_local_path


_SCHEMA = """
CREATE TABLE IF NOT EXISTS meta       (key TEXT PRIMARY KEY, val TEXT);
CREATE TABLE IF NOT EXISTS categories (name TEXT PRIMARY KEY, description TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS albums     (name TEXT PRIMARY KEY, description TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS tags       (tag  TEXT PRIMARY KEY);
CREATE TABLE IF NOT EXISTS titles     (title TEXT PRIMARY KEY);

-- Content mirror (shared-library-post-cache-producer spec §5). Keyed by the
-- SnapSmack post ID the server returns — the canonical identity. Added with
-- IF NOT EXISTS so an existing vocabulary-only catalog gains these on open.
CREATE TABLE IF NOT EXISTS posts (
    post_id     INTEGER PRIMARY KEY,   -- SnapSmack-assigned; canonical key
    site_mode   TEXT DEFAULT '',       -- photoblog|carousel|smacktalk at post time
    post_type   TEXT DEFAULT '',       -- solo|carousel|long
    title       TEXT DEFAULT '',
    body        TEXT DEFAULT '',       -- longform HTML/shortcodes for SMACKTALK
    permalink   TEXT DEFAULT '',
    categories  TEXT DEFAULT '',       -- JSON array
    tags        TEXT DEFAULT '',       -- JSON array
    posted_at   TEXT DEFAULT '',       -- YYYY-MM-DD HH:MM:SS (local write time)
    source_tool TEXT DEFAULT '',       -- 'smackpress' | 'coldsnap' | 'sybu' | …
    source_ref  TEXT DEFAULT ''        -- provenance, e.g. 'wp:example.com/?p=1234'
);
CREATE TABLE IF NOT EXISTS assets (
    asset_id    TEXT PRIMARY KEY,      -- sha256 of the original bytes
    post_id     INTEGER,               -- FK → posts.post_id (NULL until attached)
    media_path  TEXT DEFAULT '',       -- relative to shared_library/<site>/media
    thumb_path  TEXT DEFAULT '',       -- relative to …/thumbs if a derivative exists
    orig_name   TEXT DEFAULT '',       -- original filename as pulled/exported
    mime        TEXT DEFAULT '',
    width       INTEGER DEFAULT 0,
    height      INTEGER DEFAULT 0,
    alt         TEXT DEFAULT '',
    source_ref  TEXT DEFAULT ''
);
CREATE INDEX IF NOT EXISTS assets_post ON assets(post_id);
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


# ── Content mirror: media store (shared-library-post-cache-producer spec §4/§6) ─
_EXT_MIME = {
    ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png",
    ".gif": "image/gif", ".webp": "image/webp", ".tif": "image/tiff",
    ".tiff": "image/tiff", ".bmp": "image/bmp", ".avif": "image/avif",
    ".heic": "image/heic",
}


def _sha256_bytes(data: bytes) -> str:
    h = hashlib.sha256()
    h.update(data)
    return h.hexdigest()


def _sha256_file(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def store_media(site, source, *, orig_name="", ext="") -> dict:
    """Copy one original into shared_library/<site>/media, named by sha256 so
    identical bytes from any tool dedupe. `source` is a filesystem path or raw
    bytes. Returns {asset_id, media_path (relative), orig_name, mime}. Idempotent —
    an already-present file is not rewritten. Does NOT touch the DB; pass the
    returned dict into record_post()'s assets list to persist the row."""
    if isinstance(source, (bytes, bytearray)):
        data = bytes(source)
        asset_id = _sha256_bytes(data)
        src_path = None
    else:
        src_path = str(source)
        asset_id = _sha256_file(src_path)
        data = None
        if not orig_name:
            orig_name = os.path.basename(src_path)
        if not ext:
            ext = os.path.splitext(src_path)[1]
    ext = (ext or os.path.splitext(orig_name)[1] or "").lower()
    # Restrict the extension to known image types — an unknown/hostile ext (e.g. a
    # caller-supplied "/../../evil.exe", an NTFS ":stream", or ".php"/".lnk") is
    # dropped so the store only ever holds recognisable media. asset_id is safe hex.
    if ext not in _EXT_MIME:
        ext = ""
    # Contain the final filename too (defence in depth — every other path in the
    # suite is jailed; the media filename must be no exception).
    rel_name = asset_id + ext
    dest = contained_local_path(snap_home.site_media_dir(site), rel_name)
    if not os.path.isfile(dest):
        if data is not None:
            with open(dest, "wb") as f:
                f.write(data)
        else:
            shutil.copyfile(src_path, dest)
    return {"asset_id": asset_id, "media_path": rel_name,
            "orig_name": orig_name, "mime": _EXT_MIME.get(ext, "")}


def record_post(site, post: dict, assets=None) -> dict:
    """Idempotent upsert of one just-posted post + its assets into the shared
    library. Call immediately AFTER the server confirms the write and returns a
    post_id — never on a failed/partial post. INSERT OR REPLACE on post_id (and on
    asset_id) so resumes and re-posts are safe. Returns a small summary.

    post keys: post_id (required), site_mode, post_type, title, body, permalink,
    categories (list), tags (list), source_tool, source_ref, posted_at.
    asset dicts: asset_id (required), media_path, thumb_path, orig_name, mime,
    width, height, alt, source_ref. Use store_media() to get asset_id+media_path."""
    post = post or {}
    if post.get("post_id") in (None, ""):
        raise ValueError("record_post requires post['post_id']")
    post_id = int(post["post_id"])
    assets = assets or []

    def _jsonarr(v):
        return json.dumps(list(v)) if isinstance(v, (list, tuple)) else str(v or "")

    posted_at = str(post.get("posted_at") or time.strftime("%Y-%m-%d %H:%M:%S"))
    conn = _connect(site)
    try:
        with conn:
            conn.execute(
                """INSERT OR REPLACE INTO posts
                   (post_id, site_mode, post_type, title, body, permalink,
                    categories, tags, posted_at, source_tool, source_ref)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
                (post_id,
                 str(post.get("site_mode", "") or ""),
                 str(post.get("post_type", "") or ""),
                 str(post.get("title", "") or ""),
                 str(post.get("body", "") or ""),
                 str(post.get("permalink", "") or ""),
                 _jsonarr(post.get("categories")),
                 _jsonarr(post.get("tags")),
                 posted_at,
                 str(post.get("source_tool", "") or ""),
                 str(post.get("source_ref", "") or "")),
            )
            for a in assets:
                aid = a.get("asset_id")
                if not aid:
                    continue
                conn.execute(
                    """INSERT OR REPLACE INTO assets
                       (asset_id, post_id, media_path, thumb_path, orig_name,
                        mime, width, height, alt, source_ref)
                       VALUES (?,?,?,?,?,?,?,?,?,?)""",
                    (str(aid), post_id,
                     str(a.get("media_path", "") or ""),
                     str(a.get("thumb_path", "") or ""),
                     str(a.get("orig_name", "") or ""),
                     str(a.get("mime", "") or ""),
                     int(a.get("width", 0) or 0),
                     int(a.get("height", 0) or 0),
                     str(a.get("alt", "") or ""),
                     str(a.get("source_ref", "") or "")),
                )
        return {"post_id": post_id,
                "assets": len([a for a in assets if a.get("asset_id")])}
    finally:
        conn.close()


# ── Content mirror: read (offline consumers — GYSS / COLD SNAP / SYBU) ───────
def _row_to_post(row) -> dict:
    d = dict(row)
    for k in ("categories", "tags"):
        try:
            d[k] = json.loads(d.get(k) or "[]")
        except Exception:
            d[k] = []
    return d


def post(site, post_id):
    if not os.path.isfile(_db_path(site)):
        return None
    conn = _connect(site)
    conn.row_factory = sqlite3.Row
    try:
        row = conn.execute("SELECT * FROM posts WHERE post_id = ?", (int(post_id),)).fetchone()
        return _row_to_post(row) if row else None
    finally:
        conn.close()


def posts(site, *, site_mode=None, source_tool=None) -> list:
    if not os.path.isfile(_db_path(site)):
        return []
    conn = _connect(site)
    conn.row_factory = sqlite3.Row
    try:
        sql = "SELECT * FROM posts"
        clauses, args = [], []
        if site_mode:
            clauses.append("site_mode = ?"); args.append(str(site_mode))
        if source_tool:
            clauses.append("source_tool = ?"); args.append(str(source_tool))
        if clauses:
            sql += " WHERE " + " AND ".join(clauses)
        sql += " ORDER BY posted_at DESC, post_id DESC"
        return [_row_to_post(r) for r in conn.execute(sql, args).fetchall()]
    finally:
        conn.close()


def assets_for(site, post_id) -> list:
    if not os.path.isfile(_db_path(site)):
        return []
    conn = _connect(site)
    conn.row_factory = sqlite3.Row
    try:
        return [dict(r) for r in conn.execute(
            "SELECT * FROM assets WHERE post_id = ? ORDER BY rowid", (int(post_id),)).fetchall()]
    finally:
        conn.close()


def has_source_ref(site, source_ref) -> bool:
    """True if a post with this provenance is already recorded — the resume ledger
    that lets a migration skip work already committed."""
    if not os.path.isfile(_db_path(site)) or not source_ref:
        return False
    conn = _connect(site)
    try:
        row = conn.execute("SELECT 1 FROM posts WHERE source_ref = ? LIMIT 1",
                           (str(source_ref),)).fetchone()
        return row is not None
    finally:
        conn.close()


def asset_file(site, asset_id):
    """Absolute path to a stored original in media/, or None if not present."""
    if not os.path.isfile(_db_path(site)):
        return None
    conn = _connect(site)
    try:
        row = conn.execute("SELECT media_path FROM assets WHERE asset_id = ?",
                           (str(asset_id),)).fetchone()
    finally:
        conn.close()
    if not row or not row[0]:
        return None
    path = os.path.join(snap_home.site_media_dir(site), row[0])
    return path if os.path.isfile(path) else None
# ===== SNAPSMACK EOF =====
