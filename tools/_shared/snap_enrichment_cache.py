"""
SNAPSMACK shared AI-enrichment cache.

Stores the complete, reviewable AI response in the per-blog catalog.sqlite. The
CMS copy is authoritative; ``merge_remote`` applies its revision unless both
sides changed from the same base, in which case a collision is returned for the
user to resolve. Image bytes are identified by SHA-256, never by filename.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

from __future__ import annotations

import hashlib
import json
import os
import sqlite3
import time
from typing import Any, Dict, Optional

import snap_home

DEFAULT_TTL_DAYS = 90

_SCHEMA = """
CREATE TABLE IF NOT EXISTS enrichment_cache (
  cache_key TEXT PRIMARY KEY,
  image_sha256 TEXT NOT NULL,
  domain TEXT NOT NULL,
  model TEXT NOT NULL,
  prompt_sha256 TEXT NOT NULL,
  prompt_version TEXT NOT NULL DEFAULT '1',
  bundle_json TEXT NOT NULL,
  raw_response TEXT NOT NULL DEFAULT '',
  accepted_json TEXT NOT NULL DEFAULT '{}',
  revision INTEGER NOT NULL DEFAULT 1,
  base_revision INTEGER NOT NULL DEFAULT 0,
  generated_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL,
  modified_at INTEGER NOT NULL,
  source TEXT NOT NULL DEFAULT 'desktop',
  dirty INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_enrichment_lookup
  ON enrichment_cache(image_sha256, domain, model, prompt_sha256, expires_at);
"""


def image_sha256(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def prompt_sha256(prompt: str) -> str:
    return hashlib.sha256((prompt or "").encode("utf-8")).hexdigest()


def cache_key(image_hash: str, domain: str, model: str, prompt_hash: str,
              prompt_version: str = "1") -> str:
    material = "\n".join((image_hash, domain.lower().strip(), model,
                            prompt_hash, str(prompt_version)))
    return hashlib.sha256(material.encode("utf-8")).hexdigest()


def _db_path(site: str) -> str:
    return os.path.join(snap_home.site_db_dir(site), "catalog.sqlite")


def _connect(site: str) -> sqlite3.Connection:
    conn = sqlite3.connect(_db_path(site))
    conn.row_factory = sqlite3.Row
    conn.executescript(_SCHEMA)
    return conn


def get(site: str, image_hash: str, domain: str, model: str, prompt_hash: str,
        prompt_version: str = "1", include_expired: bool = False) -> Optional[dict]:
    key = cache_key(image_hash, domain, model, prompt_hash, prompt_version)
    conn = _connect(site)
    try:
        row = conn.execute("SELECT * FROM enrichment_cache WHERE cache_key = ?", (key,)).fetchone()
        if not row or (not include_expired and int(row["expires_at"]) <= int(time.time())):
            return None
        result = dict(row)
        result["bundle"] = json.loads(result.pop("bundle_json"))
        result["accepted"] = json.loads(result.pop("accepted_json"))
        return result
    finally:
        conn.close()


def put(site: str, image_hash: str, domain: str, model: str, prompt_hash: str,
        bundle: Dict[str, Any], *, raw_response: str = "", accepted: Optional[dict] = None,
        prompt_version: str = "1", ttl_days: int = DEFAULT_TTL_DAYS,
        source: str = "desktop", remote_revision: Optional[int] = None) -> dict:
    now = int(time.time())
    ttl_days = max(1, min(int(ttl_days or DEFAULT_TTL_DAYS), 3650))
    key = cache_key(image_hash, domain, model, prompt_hash, prompt_version)
    conn = _connect(site)
    try:
        old = conn.execute("SELECT revision FROM enrichment_cache WHERE cache_key = ?", (key,)).fetchone()
        revision = int(remote_revision) if remote_revision is not None else (int(old[0]) + 1 if old else 1)
        dirty = 0 if source == "cms" else 1
        with conn:
            conn.execute("""
                INSERT INTO enrichment_cache
                  (cache_key,image_sha256,domain,model,prompt_sha256,prompt_version,
                   bundle_json,raw_response,accepted_json,revision,base_revision,
                   generated_at,expires_at,modified_at,source,dirty)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(cache_key) DO UPDATE SET
                  bundle_json=excluded.bundle_json, raw_response=excluded.raw_response,
                  accepted_json=excluded.accepted_json, revision=excluded.revision,
                  base_revision=excluded.base_revision, generated_at=excluded.generated_at,
                  expires_at=excluded.expires_at, modified_at=excluded.modified_at,
                  source=excluded.source, dirty=excluded.dirty
            """, (key, image_hash, domain.lower().strip(), model, prompt_hash,
                    str(prompt_version), json.dumps(bundle, ensure_ascii=False, sort_keys=True),
                    raw_response or "", json.dumps(accepted or {}, ensure_ascii=False, sort_keys=True),
                    revision, int(old[0]) if old else 0, now, now + ttl_days * 86400,
                    now, source, dirty))
        return get(site, image_hash, domain, model, prompt_hash, prompt_version, True) or {}
    finally:
        conn.close()


def merge_remote(site: str, record: dict) -> dict:
    """Merge one authoritative CMS record; report, never hide, a true collision."""
    key = str(record.get("cache_key", ""))
    if not key:
        raise ValueError("Remote enrichment record has no cache_key")
    conn = _connect(site)
    try:
        local = conn.execute("SELECT * FROM enrichment_cache WHERE cache_key = ?", (key,)).fetchone()
        if local and int(local["dirty"]) and int(record.get("revision", 0)) > int(local["base_revision"]):
            return {"status": "collision", "cache_key": key,
                    "local": dict(local), "remote": record}
        with conn:
            conn.execute("""
              INSERT OR REPLACE INTO enrichment_cache
              (cache_key,image_sha256,domain,model,prompt_sha256,prompt_version,bundle_json,
               raw_response,accepted_json,revision,base_revision,generated_at,expires_at,
               modified_at,source,dirty) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)
            """, (key, record["image_sha256"], record["domain"], record["model"],
                    record["prompt_sha256"], str(record.get("prompt_version", "1")),
                    json.dumps(record.get("bundle", {}), ensure_ascii=False, sort_keys=True),
                    str(record.get("raw_response", "")),
                    json.dumps(record.get("accepted", {}), ensure_ascii=False, sort_keys=True),
                    int(record.get("revision", 1)), int(record.get("revision", 1)),
                    int(record.get("generated_at", time.time())),
                    int(record.get("expires_at", time.time())),
                    int(record.get("modified_at", time.time())), "cms"))
        return {"status": "updated", "cache_key": key}
    finally:
        conn.close()


def pending(site: str) -> list:
    conn = _connect(site)
    try:
        rows = conn.execute("SELECT * FROM enrichment_cache WHERE dirty = 1 ORDER BY modified_at").fetchall()
        output = []
        for row in rows:
            item = dict(row)
            item["bundle"] = json.loads(item.pop("bundle_json"))
            item["accepted"] = json.loads(item.pop("accepted_json"))
            output.append(item)
        return output
    finally:
        conn.close()

# ===== SNAPSMACK EOF =====
